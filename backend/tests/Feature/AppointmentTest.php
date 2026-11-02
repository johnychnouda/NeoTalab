<?php

namespace Tests\Feature;

use App\Jobs\CheckUnacceptedOrder;
use App\Jobs\NotifyStaffOfAppointmentCancelled;
use App\Jobs\SendAppointmentReminder;
use App\Models\Appointment;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ShopHour;
use App\Models\SystemAlert;
use App\Services\WhatsApp\ShopTemplateService;
use App\Services\WhatsApp\WhatsAppInboundService;
use App\Support\OrderMessage;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const CUSTOMER = '+96170123456';

    private Merchant $merchant;

    private string $token;

    private Product $haircut;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]], 200)]);
        Bus::fake([CheckUnacceptedOrder::class, SendAppointmentReminder::class]);
        // Tuesday 6 Oct 2026, 08:00 in Beirut (UTC+3).
        Carbon::setTestNow(Carbon::parse('2026-10-06 08:00', 'Asia/Beirut'));

        [$this->merchant, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'name' => 'Hair Studio', 'currency' => 'USD', 'timezone' => 'Asia/Beirut',
            'settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto', 'business_mode' => 'appointments', 'pickup_address' => 'Hamra St'],
        ]);
        $this->token = $this->tokenFor($owner);
        $this->haircut = Product::factory()->forMerchant($this->merchant)->create(['name' => 'Haircut', 'price' => 10, 'duration_mins' => 30]);
    }

    private function say(string $text, string $from = self::CUSTOMER): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [['id' => 'wamid.IN'.(++$this->seq), 'from' => ltrim($from, '+'), 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]]);
    }

    private function lastTo(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        $last = Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['to'] === $digits)->last();

        return $last['text']['body'] ?? ($last['interactive']['body']['text'] ?? '');
    }

    private function settings(array $values): void
    {
        $this->merchant->update(['settings' => array_merge($this->merchant->fresh()->settings, $values)]);
    }

    public function test_customer_sees_real_free_times(): void
    {
        $this->say('slots haircut 2026-10-06');

        $reply = $this->lastTo(self::CUSTOMER);
        $this->assertStringContainsString('Free times for Haircut on Tue 6 Oct', $reply);
        $this->assertStringContainsString('09:00, 09:30, 10:00', $reply);
        $this->assertStringNotContainsString('08:30', $reply);
        $this->assertStringContainsString('18:00, 18:30', $reply, 'no saved hours: the dashboard default 09:00–23:00, not a separate booking default');
    }

    public function test_dates_are_written_in_the_customers_language(): void
    {
        Customer::query()->withoutGlobalScopes()->forceCreate(['merchant_id' => $this->merchant->id, 'phone' => self::CUSTOMER, 'locale' => 'ar']);

        $this->say('slots haircut 2026-10-06');

        $reply = $this->lastTo(self::CUSTOMER);
        $this->assertStringContainsString('6 أكتوبر', $reply);
        $this->assertStringNotContainsString('Oct', $reply);
    }

    public function test_booking_confirms_notifies_staff_and_schedules_a_reminder(): void
    {
        $owner = Driver::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Owner', 'role' => 'staff', 'phone' => '+96171555000', 'whatsapp_number' => '+96171555000', 'password' => 'x', 'status' => 'on_duty', 'availability' => 'available']);
        $this->staffWroteToShop($this->merchant, '+96171555000');

        $this->say('book haircut 2026-10-06 11:00');

        $appointment = Appointment::query()->withoutGlobalScopes()->sole();
        $this->assertSame('2026-10-06 08:00:00', $appointment->starts_at->utc()->format('Y-m-d H:i:s'), 'stored in UTC');
        $this->assertStringContainsString('Booked: Haircut on Tue 6 Oct at 11:00 (10.00$) at Hair Studio — Hamra St', $this->lastTo(self::CUSTOMER));
        $this->assertStringContainsString('New booking', $this->lastTo($owner->whatsapp_number));
        Bus::assertDispatched(SendAppointmentReminder::class);

        $this->say('slots haircut 2026-10-06');
        $this->assertStringNotContainsString('11:00,', $this->lastTo(self::CUSTOMER), 'booked slot no longer offered');
    }

    public function test_silent_staff_get_the_booking_as_a_template(): void
    {
        Driver::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Owner', 'role' => 'staff', 'phone' => '+96171555000', 'whatsapp_number' => '+96171555000', 'password' => 'x', 'status' => 'on_duty', 'availability' => 'available']);
        $settings = $this->merchant->fresh()->settings;
        $settings['bot']['templates'][ShopTemplateService::NEW_BOOKING] = ['status' => 'APPROVED'];
        $this->merchant->update(['settings' => $settings]);

        $this->say('book haircut 2026-10-06 11:00');

        $appointment = Appointment::query()->withoutGlobalScopes()->sole();
        $sent = Http::recorded()->map(fn ($p) => $p[0])->last(fn ($r) => str_ends_with($r->url(), '/messages') && $r['to'] === '96171555000');
        $this->assertSame(ShopTemplateService::NEW_BOOKING, $sent['template']['name']);
        $this->assertSame(['Hair Studio', 'Haircut', 'Tue 6 Oct', '11:00', '10.00$', OrderMessage::phone(self::CUSTOMER)], array_column($sent['template']['components'][0]['parameters'], 'text'));
        $this->assertSame("stf:appt_no_show:{$appointment->id}", $sent['template']['components'][2]['parameters'][0]['payload']);
    }

    public function test_a_taken_slot_offers_alternatives_and_capacity_allows_parallel_bookings(): void
    {
        $this->say('book haircut 2026-10-06 10:00');
        $this->say('book haircut 2026-10-06 10:00', '+96170000002');
        $this->assertStringContainsString('that time was just taken', $this->lastTo('+96170000002'));
        $this->assertSame(1, Appointment::query()->withoutGlobalScopes()->count());

        $this->settings(['appointment_capacity' => 2]);
        $this->say('book haircut 2026-10-06 10:00', '+96170000002');
        $this->assertSame(2, Appointment::query()->withoutGlobalScopes()->count());
    }

    public function test_past_times_today_are_not_offered(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:20', 'Asia/Beirut'));

        $this->say('slots haircut 2026-10-06');

        $this->assertStringContainsString(":\n13:00, 13:30", $this->lastTo(self::CUSTOMER));
    }

    public function test_a_past_date_is_refused_and_never_booked_on_today_instead(): void
    {
        $this->say('book haircut 2020-01-01 10:00');

        $this->assertSame(0, Appointment::query()->withoutGlobalScopes()->count(), 'nothing is booked');
        $this->assertStringContainsString('already passed', $this->lastTo(self::CUSTOMER));
    }

    public function test_closed_day_points_to_the_next_free_day(): void
    {
        foreach (range(0, 6) as $day) {
            ShopHour::query()->forceCreate(['merchant_id' => $this->merchant->id, 'day_of_week' => $day, 'opens_at' => '10:00', 'closes_at' => '14:00', 'is_closed' => $day === 2]);
        }

        $this->say('slots haircut 2026-10-06');

        $reply = $this->lastTo(self::CUSTOMER);
        $this->assertStringContainsString('no free times for Haircut on Tue 6 Oct', $reply);
        $this->assertStringContainsString('The next free day is Wed 7 Oct', $reply);
        $this->assertStringContainsString('10:00, 10:30', $reply);
    }

    public function test_customer_cancels_and_staff_mark_done(): void
    {
        $owner = Driver::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Owner', 'role' => 'staff', 'phone' => '+96171555000', 'whatsapp_number' => '+96171555000', 'password' => 'x', 'status' => 'on_duty', 'availability' => 'available']);
        $this->staffWroteToShop($this->merchant, '+96171555000');
        $this->say('book haircut 2026-10-06 10:00');
        $first = Appointment::query()->withoutGlobalScopes()->sole();

        $this->app[WhatsAppInboundService::class]->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [['id' => 'wamid.BTN', 'from' => '96171555000', 'type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => "stf:appt_completed:{$first->id}", 'title' => 'Done']]]],
        ]]]]]]);
        $this->assertSame('completed', $first->fresh()->status);

        $this->say('book haircut 2026-10-07 11:00');
        $this->say('cancel appointment');
        $this->assertStringContainsString('Haircut on Wed 7 Oct at 11:00 has been cancelled', $this->lastTo(self::CUSTOMER));
    }

    public function test_with_two_bookings_the_customer_is_asked_which_one_and_only_that_one_is_cancelled(): void
    {
        Driver::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Owner', 'role' => 'staff', 'phone' => '+96171555000', 'whatsapp_number' => '+96171555000', 'password' => 'x', 'status' => 'on_duty', 'availability' => 'available']);
        $this->staffWroteToShop($this->merchant, '+96171555000');
        $this->say('book haircut 2026-10-08 10:00');
        $this->say('book haircut 2026-10-09 11:00');

        $this->say('cancel appointment');
        $this->assertStringContainsString('more than one booking', $this->lastTo(self::CUSTOMER));
        $this->assertSame(2, Appointment::query()->withoutGlobalScopes()->where('status', 'booked')->count(), 'nothing cancelled without knowing which');

        $this->say('cancel appointment 2026-10-09');
        $this->assertStringContainsString('Fri 9 Oct at 11:00 has been cancelled', $this->lastTo(self::CUSTOMER));
        $left = Appointment::query()->withoutGlobalScopes()->where('status', 'booked')->sole();
        $this->assertSame('2026-10-08 10:00', $left->starts_at->setTimezone('Asia/Beirut')->format('Y-m-d H:i'));
        $this->assertStringContainsString('Booking cancelled by the customer', $this->lastTo('+96171555000'), 'the staff are told');
    }

    public function test_reminder_is_sent_once_before_the_visit(): void
    {
        $this->say('book haircut 2026-10-06 15:00');
        $appointment = Appointment::query()->withoutGlobalScopes()->sole();

        (new SendAppointmentReminder($appointment->id))->handle();
        (new SendAppointmentReminder($appointment->id))->handle();

        $reminders = Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && str_contains($r['text']['body'] ?? '', 'Reminder'));
        $this->assertCount(1, $reminders);
        $this->assertStringContainsString('Haircut at Hair Studio is on Tue 6 Oct at 15:00', $reminders->first()['text']['body']);
    }

    public function test_reminder_days_after_the_booking_uses_the_approved_template(): void
    {
        $this->say('book haircut 2026-10-09 15:00');
        $appointment = Appointment::query()->withoutGlobalScopes()->sole();
        $bot = $this->merchant->fresh()->settings['bot'];
        $bot['templates']['neotalab_c_booking_reminder_en'] = ['status' => 'APPROVED'];
        $this->merchant->update(['settings' => array_merge($this->merchant->fresh()->settings, ['bot' => $bot])]);

        Carbon::setTestNow(Carbon::parse('2026-10-09 09:00', 'Asia/Beirut')); // three days after the customer last wrote
        (new SendAppointmentReminder($appointment->id))->handle();

        $sent = Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['type'] === 'template')->sole();
        $this->assertSame('neotalab_c_booking_reminder_en', $sent['template']['name']);
        $this->assertSame(['Haircut', 'Hair Studio', 'Fri 9 Oct', '15:00'], array_column($sent['template']['components'][0]['parameters'], 'text'));
    }

    public function test_reminder_days_after_the_booking_without_a_template_tells_the_owner_instead_of_sending_text(): void
    {
        $this->say('book haircut 2026-10-09 15:00');
        $appointment = Appointment::query()->withoutGlobalScopes()->sole();

        Carbon::setTestNow(Carbon::parse('2026-10-09 09:00', 'Asia/Beirut'));
        (new SendAppointmentReminder($appointment->id))->handle();

        $reminders = Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && str_contains($r['text']['body'] ?? '', 'Reminder'));
        $this->assertCount(0, $reminders, 'plain text outside the window would be dropped by Meta');
        $this->assertNotNull(SystemAlert::query()->where('message', 'like', '%was not told about "booking_reminder"%')->first());
    }

    public function test_staff_out_of_the_window_get_the_cancelled_booking_by_template(): void
    {
        $this->say('book haircut 2026-10-09 11:00');
        Driver::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Owner', 'role' => 'staff', 'phone' => '+96171555000', 'whatsapp_number' => '+96171555000', 'password' => 'x', 'status' => 'on_duty', 'availability' => 'available']);
        $this->staffWroteToShop($this->merchant, '+96171555000', now()->subDays(2));
        $bot = $this->merchant->fresh()->settings['bot'];
        $bot['templates'][ShopTemplateService::BOOKING_CANCELLED] = ['status' => 'APPROVED'];
        $this->merchant->update(['settings' => array_merge($this->merchant->fresh()->settings, ['bot' => $bot])]);
        $appointment = Appointment::query()->withoutGlobalScopes()->sole();
        $appointment->forceFill(['status' => 'cancelled'])->save();

        app()->call([new NotifyStaffOfAppointmentCancelled($appointment->id), 'handle']);

        $sent = Http::recorded()->map(fn ($p) => $p[0])
            ->last(fn ($r) => str_ends_with($r->url(), '/messages') && $r['to'] === '96171555000' && $r['type'] === 'template');
        $this->assertSame(ShopTemplateService::BOOKING_CANCELLED, $sent['template']['name']);
        $this->assertSame(['Hair Studio', 'Haircut', 'Fri 9 Oct', '11:00', '+96170123456'], array_column($sent['template']['components'][0]['parameters'], 'text'));
    }

    public function test_a_customer_who_names_service_day_and_time_is_booked_at_once_even_when_the_assistant_answers_with_the_list(): void
    {
        $this->say('ask haircut 2026-10-08 at 11:00 please');

        $this->assertStringContainsString('Booked: Haircut on', $this->lastTo(self::CUSTOMER));
        $this->assertSame(['2026-10-08 11:00'], Appointment::query()->withoutGlobalScopes()->where('status', 'booked')->get()
            ->map(fn ($a) => $a->starts_at->setTimezone('Asia/Beirut')->format('Y-m-d H:i'))->all());
    }

    public function test_a_question_about_a_time_still_gets_the_list_not_a_booking(): void
    {
        $this->say('ask haircut 2026-10-08 is 11:00 free?');

        $this->assertStringContainsString('Free times for Haircut', $this->lastTo(self::CUSTOMER));
        $this->assertSame(0, Appointment::query()->withoutGlobalScopes()->where('status', 'booked')->count());
    }

    public function test_a_day_without_a_time_gets_the_list(): void
    {
        $this->say('ask haircut 2026-10-08');

        $this->assertStringContainsString('Free times for Haircut', $this->lastTo(self::CUSTOMER));
        $this->assertSame(0, Appointment::query()->withoutGlobalScopes()->where('status', 'booked')->count());
    }

    public function test_a_time_that_is_taken_is_not_double_booked_by_the_shortcut(): void
    {
        $this->say('book haircut 2026-10-08 11:00');
        $this->say('ask haircut 2026-10-08 at 11:00 please', '+96170999111');

        $this->assertStringContainsString('just taken', $this->lastTo('+96170999111'));
        $this->assertSame(1, Appointment::query()->withoutGlobalScopes()->where('status', 'booked')->count());
    }

    public function test_a_free_service_can_be_booked_and_the_confirmation_says_free(): void
    {
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Table', 'price' => 0, 'duration_mins' => 60]);

        $this->say('book table 2026-10-08 12:00');

        $reply = $this->lastTo(self::CUSTOMER);
        $this->assertStringContainsString('Booked: Table on', $reply);
        $this->assertStringContainsString('(free)', $reply);
        $this->assertSame(1, Appointment::query()->withoutGlobalScopes()->where('status', 'booked')->count());
    }

    public function test_cancelling_an_order_that_does_not_exist_cancels_the_only_booking_instead(): void
    {
        $this->say('book haircut 2026-10-08 11:00');
        $this->assertSame(1, Appointment::query()->withoutGlobalScopes()->where('status', 'booked')->count());

        // The assistant sometimes takes "cancel my appointment" for an order; a booking shop's customer has no order.
        $this->say('cancel order');

        $this->assertStringContainsString('has been cancelled', $this->lastTo(self::CUSTOMER));
        $this->assertSame(0, Appointment::query()->withoutGlobalScopes()->where('status', 'booked')->count());
    }

    public function test_cancelling_with_neither_an_order_nor_a_booking_still_says_there_is_nothing(): void
    {
        $this->say('cancel order');

        $this->assertStringContainsString('no open order', $this->lastTo(self::CUSTOMER));
    }

    public function test_services_cannot_be_put_in_the_cart(): void
    {
        $this->say('add 1 haircut');

        $this->assertSame(0, CartItem::query()->withoutGlobalScopes()->count());
    }

    public function test_dashboard_lists_upcoming_appointments_in_shop_time(): void
    {
        $this->say('book haircut 2026-10-06 10:00');

        $this->withToken($this->token)->getJson('/api/v1/appointments')
            ->assertOk()
            ->assertJsonPath('appointments.0.service', 'Haircut')
            ->assertJsonPath('appointments.0.starts_at', '2026-10-06 10:00')
            ->assertJsonPath('appointments.0.customer_phone', self::CUSTOMER);
    }

    // ── the shop cancels or moves a booking, from WhatsApp ───────────────────

    private const STAFF = '+96171555000';

    private function team(): void
    {
        Driver::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Desk', 'role' => 'staff', 'phone' => self::STAFF, 'whatsapp_number' => self::STAFF, 'password' => 'x', 'status' => 'on_duty', 'availability' => 'available']);
        $this->staffWroteToShop($this->merchant, self::STAFF);
    }

    /** A tap on a button or a list row, from the team. */
    private function press(string $id, string $from = self::STAFF, string $kind = 'button_reply'): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [['id' => 'wamid.P'.(++$this->seq), 'from' => ltrim($from, '+'), 'type' => 'interactive',
                'interactive' => ['type' => $kind, $kind => ['id' => $id, 'title' => 'x']]]],
        ]]]]]]);
    }

    /** @return array<string, mixed> the last message sent to the phone */
    private function lastMessageTo(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone);

        return Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['to'] === $digits)->last()?->data() ?? [];
    }

    private function bookedHaircut(string $slot = '2026-10-08 11:00'): Appointment
    {
        $this->say('book haircut '.$slot);

        return Appointment::query()->withoutGlobalScopes()->where('status', 'booked')->latest()->firstOrFail();
    }

    public function test_the_booking_ticket_has_a_change_button_and_change_offers_move_cancel_keep(): void
    {
        $this->team();
        $appointment = $this->bookedHaircut();

        $ticket = $this->lastMessageTo(self::STAFF);
        $this->assertSame(["stf:appt_completed:{$appointment->id}", "stf:appt_no_show:{$appointment->id}", "stf:appt_change:{$appointment->id}"],
            array_column(array_column($ticket['interactive']['action']['buttons'], 'reply'), 'id'));

        $this->press("stf:appt_change:{$appointment->id}");
        $prompt = $this->lastMessageTo(self::STAFF);
        $this->assertStringContainsString('Haircut on Thu 8 Oct at 11:00', $prompt['interactive']['body']['text']);
        $this->assertSame(["stf:appt_move:{$appointment->id}", "stf:appt_cancel:{$appointment->id}", "stf:appt_keep:{$appointment->id}"],
            array_column(array_column($prompt['interactive']['action']['buttons'], 'reply'), 'id'));
    }

    public function test_the_shop_cancels_a_booking_and_the_customer_is_told(): void
    {
        $this->team();
        $appointment = $this->bookedHaircut();

        $this->press("stf:appt_cancel:{$appointment->id}");

        $this->assertSame('cancelled', $appointment->fresh()->status);
        $this->assertSame('shop', $appointment->fresh()->cancelled_by);
        $this->assertStringContainsString('Sorry, Hair Studio cancelled your Haircut on Thu 8 Oct at 11:00', $this->lastTo(self::CUSTOMER));
        $this->assertStringContainsString('Booking cancelled', $this->lastTo(self::STAFF));
        $this->assertSame(0, Appointment::query()->withoutGlobalScopes()->where('status', 'booked')->count());

        // The same time can be booked again, and a second tap changes nothing.
        $this->press("stf:appt_cancel:{$appointment->id}");
        $this->assertStringContainsString('already cancelled or closed', $this->lastTo(self::STAFF));
    }

    public function test_keeping_a_booking_changes_nothing(): void
    {
        $this->team();
        $appointment = $this->bookedHaircut();

        $this->press("stf:appt_keep:{$appointment->id}");

        $this->assertSame('booked', $appointment->fresh()->status);
        $this->assertStringContainsString('Booking kept', $this->lastTo(self::STAFF));
    }

    public function test_move_it_offers_free_times_and_picking_one_moves_the_booking_and_tells_the_customer(): void
    {
        $this->team();
        $appointment = $this->bookedHaircut();

        $this->press("stf:appt_move:{$appointment->id}");
        $list = $this->lastMessageTo(self::STAFF);
        $rows = $list['interactive']['action']['sections'][0]['rows'];
        $this->assertLessThanOrEqual(10, count($rows));
        $this->assertSame('list', $list['interactive']['type']);
        $this->assertNotContains("stf:appt_to:{$appointment->id}:202610081100", array_column($rows, 'id'), 'the booking is not offered its own time');

        $this->press("stf:appt_to:{$appointment->id}:202610091530", self::STAFF, 'list_reply');

        $moved = $appointment->fresh();
        $this->assertSame('2026-10-09 15:30', $moved->starts_at->setTimezone('Asia/Beirut')->format('Y-m-d H:i'));
        $this->assertSame('booked', $moved->status);
        $this->assertNull($moved->reminded_at);
        $this->assertStringContainsString('Hair Studio moved your Haircut to Fri 9 Oct at 15:30', $this->lastTo(self::CUSTOMER));
        $this->assertStringContainsString('Moved to Fri 9 Oct at 15:30', $this->lastTo(self::STAFF));
        Bus::assertDispatched(SendAppointmentReminder::class, fn ($job) => $job->appointmentId === $appointment->id && str_contains((string) $job->dueAt, '2026-10-09T10:30'));
    }

    public function test_moving_to_a_time_that_was_just_taken_changes_nothing_and_offers_new_times(): void
    {
        $this->team();
        $appointment = $this->bookedHaircut();
        $this->say('book haircut 2026-10-09 15:30', '+96170999111'); // someone else takes it

        $this->press("stf:appt_to:{$appointment->id}:202610091530", self::STAFF, 'list_reply');

        $this->assertSame('2026-10-08 11:00', $appointment->fresh()->starts_at->setTimezone('Asia/Beirut')->format('Y-m-d H:i'));
        $this->assertStringContainsString('just taken', $this->lastMessageTo(self::STAFF)['interactive']['body']['text']);
    }

    public function test_a_booking_can_be_moved_to_an_overlapping_time_of_its_own(): void
    {
        $this->team();
        $appointment = $this->bookedHaircut('2026-10-08 11:00'); // a 30 minute service: 11:00-11:30

        $this->press("stf:appt_to:{$appointment->id}:202610081130", self::STAFF, 'list_reply');

        $this->assertSame('2026-10-08 11:30', $appointment->fresh()->starts_at->setTimezone('Asia/Beirut')->format('Y-m-d H:i'));
    }

    public function test_a_reminder_queued_for_the_old_time_is_dropped_after_a_move(): void
    {
        $this->team();
        $appointment = $this->bookedHaircut();
        $oldDue = $appointment->starts_at->copy()->subMinutes(120)->toIso8601String();
        $this->press("stf:appt_to:{$appointment->id}:202610091530", self::STAFF, 'list_reply');
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]], 200)]);

        (new SendAppointmentReminder($appointment->id, $oldDue))->handle();

        $this->assertNull($appointment->fresh()->reminded_at, 'the old reminder did nothing');
    }

    public function test_the_customer_hears_about_a_cancellation_by_template_after_two_days(): void
    {
        $this->team();
        $appointment = $this->bookedHaircut();
        $bot = $this->merchant->fresh()->settings['bot'];
        $bot['templates']['neotalab_c_booking_cancelled_shop_en'] = ['status' => 'APPROVED'];
        $this->settings(['bot' => $bot]);

        Carbon::setTestNow(now()->addDays(2));
        $this->staffWroteToShop($this->merchant, self::STAFF);
        $this->press("stf:appt_cancel:{$appointment->id}");

        $sent = collect(Http::recorded()->map(fn ($p) => $p[0])->all())
            ->last(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['to'] === '96170123456' && $r['type'] === 'template');
        $this->assertSame('neotalab_c_booking_cancelled_shop_en', $sent['template']['name']);
        $this->assertSame(['Hair Studio', 'Haircut', 'Thu 8 Oct', '11:00'], array_column($sent['template']['components'][0]['parameters'], 'text'));
    }

    public function test_the_team_asks_for_the_days_bookings(): void
    {
        $this->team();
        $this->say('book haircut 2026-10-06 15:00'); // "today" in this test is Tuesday 6 October 08:00
        $this->say('book haircut 2026-10-07 11:00', '+96170999111');

        $this->say('today', self::STAFF);
        $this->assertStringContainsString('Bookings today', $this->lastTo(self::STAFF));
        $this->assertStringContainsString('15:00 · Haircut', $this->lastTo(self::STAFF));
        $this->assertStringNotContainsString('11:00', $this->lastTo(self::STAFF));

        $this->say('tomorrow', self::STAFF);
        $this->assertStringContainsString('11:00 · Haircut', $this->lastTo(self::STAFF));

        Appointment::query()->withoutGlobalScopes()->update(['status' => 'cancelled']);
        $this->say('today', self::STAFF);
        $this->assertStringContainsString('No bookings today', $this->lastTo(self::STAFF));
    }

    public function test_a_customer_cannot_use_the_team_buttons(): void
    {
        $this->team();
        $appointment = $this->bookedHaircut();

        $this->press("stf:appt_cancel:{$appointment->id}", self::CUSTOMER);

        $this->assertSame('booked', $appointment->fresh()->status);
    }

    // ── a shop with no staff: the owner does it themselves ───────────────────

    private function ownerNumber(): string
    {
        return $this->merchant->fresh()->settings['ops_whatsapp'] ?? '+96171000999';
    }

    private function aloneShop(): void
    {
        $this->merchant->update(['whatsapp_number' => '+96171000999', 'phone' => '+96171000999', 'settings' => array_merge($this->merchant->fresh()->settings, ['ops_whatsapp' => '+96171000999'])]);
        $this->staffWroteToShop($this->merchant, '+96171000999');
    }

    public function test_a_shop_without_staff_sends_the_booking_ticket_to_its_owner(): void
    {
        $this->aloneShop();

        $this->bookedHaircut();

        $ticket = $this->lastMessageTo('+96171000999');
        $this->assertStringContainsString('New booking', $ticket['interactive']['body']['text']);
        $this->assertCount(3, $ticket['interactive']['action']['buttons']);
    }

    public function test_the_owner_of_a_shop_without_staff_cancels_and_moves_from_their_own_phone(): void
    {
        $this->aloneShop();
        $appointment = $this->bookedHaircut();

        $this->press("stf:appt_to:{$appointment->id}:202610091530", '+96171000999', 'list_reply');
        $this->assertSame('2026-10-09 15:30', $appointment->fresh()->starts_at->setTimezone('Asia/Beirut')->format('Y-m-d H:i'));
        $this->assertStringContainsString('moved your Haircut', $this->lastTo(self::CUSTOMER));

        $this->press("stf:appt_cancel:{$appointment->id}", '+96171000999');
        $this->assertSame('cancelled', $appointment->fresh()->status);

        $this->say('today', '+96171000999');
        $this->assertStringContainsString('No bookings today', $this->lastTo('+96171000999'));
    }

    public function test_the_owner_number_is_still_an_ordinary_customer_for_everything_else(): void
    {
        $this->aloneShop();

        $this->say('slots haircut 2026-10-06', '+96171000999');

        $this->assertStringContainsString('Free times for Haircut', $this->lastTo('+96171000999'), 'not taken for staff: only booking buttons and "today" are');
    }

    public function test_a_shop_with_staff_does_not_turn_its_owner_number_into_staff(): void
    {
        $this->aloneShop();
        $this->team();
        $appointment = $this->bookedHaircut();

        $this->press("stf:appt_cancel:{$appointment->id}", '+96171000999');

        $this->assertSame('booked', $appointment->fresh()->status);
    }
}
