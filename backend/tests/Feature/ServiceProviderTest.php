<?php

namespace Tests\Feature;

use App\Jobs\CheckUnacceptedOrder;
use App\Jobs\SendAppointmentReminder;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ServiceProvider;
use App\Services\WhatsApp\WhatsAppInboundService;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Salons and barbers: customers book with a named person, each with their own calendar. */
class ServiceProviderTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Merchant $merchant;

    private string $token;

    private Product $haircut;

    private ServiceProvider $rami;

    private ServiceProvider $lea;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]], 200)]);
        Bus::fake([CheckUnacceptedOrder::class, SendAppointmentReminder::class]);
        Carbon::setTestNow(Carbon::parse('2026-10-06 08:00', 'Asia/Beirut'));

        [$this->merchant, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'name' => 'Hair Studio', 'currency' => 'USD', 'timezone' => 'Asia/Beirut',
            'settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto', 'business_mode' => 'appointments', 'pickup_address' => 'Hamra St'],
        ]);
        $this->token = $this->tokenFor($owner);
        $this->haircut = Product::factory()->forMerchant($this->merchant)->create(['name' => 'Haircut', 'price' => 10, 'duration_mins' => 30]);
        $this->rami = ServiceProvider::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Rami', 'is_active' => true]);
        $this->lea = ServiceProvider::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Lea', 'is_active' => true, 'sort_order' => 1]);
    }

    private function say(string $text, string $from = '+96170123456'): string
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [['id' => 'wamid.IN'.(++$this->seq), 'from' => ltrim($from, '+'), 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]]);

        return $this->lastTo($from);
    }

    private function lastTo(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        $last = Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['to'] === $digits)->last();

        return $last['text']['body'] ?? ($last['interactive']['body']['text'] ?? '');
    }

    private function bookings()
    {
        return Appointment::query()->withoutGlobalScopes()->where('status', 'booked')->with(['provider' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('created_at')->get();
    }

    public function test_two_people_mean_two_customers_at_the_same_time_each_with_someone(): void
    {
        $this->say('book haircut 2026-10-07 10:00', '+96170000001');
        $this->say('book haircut 2026-10-07 10:00', '+96170000002');
        $third = $this->say('book haircut 2026-10-07 10:00', '+96170000003');

        $this->assertSame(['Rami', 'Lea'], $this->bookings()->map(fn ($a) => $a->provider->name)->all());
        $this->assertStringContainsString('just taken', $third);
    }

    public function test_a_customer_books_with_the_person_they_name(): void
    {
        $staff = Driver::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Desk', 'role' => 'staff', 'phone' => '+96171555000',
            'whatsapp_number' => '+96171555000', 'password' => 'x', 'status' => 'on_duty', 'availability' => 'available']);
        $this->staffWroteToShop($this->merchant, $staff->phone);

        $reply = $this->say('book haircut 2026-10-07 10:00 with lea');

        $this->assertSame('Lea', $this->bookings()->sole()->provider->name);
        $this->assertStringContainsString('Haircut with Lea', $reply);
        $this->assertStringContainsString('Haircut with Lea', $this->lastTo('+96171555000'), 'the staff see who');
    }

    public function test_free_times_for_a_person_skip_their_bookings_and_the_team_is_offered(): void
    {
        $this->say('book haircut 2026-10-07 10:00 with rami', '+96170000001');

        $forRami = $this->say('slots haircut 2026-10-07 with rami');
        $this->assertStringContainsString('Haircut with Rami', $forRami);
        $this->assertStringNotContainsString('10:00,', $forRami);

        $anyone = $this->say('slots haircut 2026-10-07');
        $this->assertStringContainsString('10:00', $anyone, 'Lea is still free at 10:00');
        $this->assertStringContainsString('With: Rami, Lea', $anyone);
    }

    public function test_a_person_who_does_not_do_the_service_is_not_booked_for_it(): void
    {
        $beard = Product::factory()->forMerchant($this->merchant)->create(['name' => 'Beard trim', 'price' => 5, 'duration_mins' => 15]);
        $this->rami->update(['service_ids' => [$this->haircut->id]]);

        $this->say('book beard trim 2026-10-07 10:00 with rami');

        $this->assertSame('Lea', $this->bookings()->sole()->provider->name);
        $this->assertSame($beard->id, $this->bookings()->sole()->product_id);
    }

    public function test_a_booking_made_before_the_team_was_listed_still_takes_one_person(): void
    {
        $customer = Customer::factory()->forMerchant($this->merchant)->create();
        Appointment::query()->forceCreate(['merchant_id' => $this->merchant->id, 'customer_id' => $customer->id, 'product_id' => $this->haircut->id,
            'service_name' => 'Haircut', 'price' => 10, 'status' => 'booked',
            'starts_at' => Carbon::parse('2026-10-07 10:00', 'Asia/Beirut')->utc(), 'ends_at' => Carbon::parse('2026-10-07 10:30', 'Asia/Beirut')->utc()]);

        $this->say('book haircut 2026-10-07 10:00', '+96170000001');
        $third = $this->say('book haircut 2026-10-07 10:00', '+96170000002');

        $this->assertCount(2, $this->bookings());
        $this->assertStringContainsString('just taken', $third);
    }

    public function test_the_team_is_set_up_in_the_dashboard_and_other_shops_cannot_touch_it(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/providers', ['name' => 'Maya', 'service_ids' => [$this->haircut->id]])->assertCreated();
        $this->withToken($this->token)->getJson('/api/v1/providers')->assertOk()->assertJsonCount(3, 'providers');

        [$other] = $this->merchantWithUser();
        $foreign = Product::factory()->forMerchant($other)->create(['duration_mins' => 30]);
        $this->withToken($this->token)->postJson('/api/v1/providers', ['name' => 'X', 'service_ids' => [$foreign->id]])->assertUnprocessable();
    }

    public function test_another_shop_cannot_see_or_change_the_team(): void
    {
        [, $otherOwner] = $this->merchantWithUser();
        $otherToken = $this->tokenFor($otherOwner);
        $this->withToken($otherToken)->getJson('/api/v1/providers')->assertOk()->assertJsonCount(0, 'providers');
        $this->withToken($otherToken)->patchJson("/api/v1/providers/{$this->rami->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->withToken($otherToken)->deleteJson("/api/v1/providers/{$this->rami->id}")->assertNotFound();
        $this->assertSame('Rami', $this->rami->fresh()->name);
    }
}
