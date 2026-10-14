<?php

namespace Tests\Feature;

use App\Jobs\CheckUnacceptedOrder;
use App\Jobs\SendAppointmentReminder;
use App\Models\Appointment;
use App\Models\CartItem;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ShopHour;
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
        $this->assertStringContainsString('17:30', $reply, 'last 30-min slot before 18:00');
        $this->assertStringNotContainsString('18:00', $reply);
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
}
