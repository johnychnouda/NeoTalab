<?php

namespace Tests\Feature;

use App\Jobs\CheckLateOrder;
use App\Jobs\CheckUnacceptedOrder;
use App\Jobs\CheckWhishTransfer;
use App\Jobs\RemindShopOfWhishCheck;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderConfirmation;
use App\Services\WhatsApp\ShopTemplateService;
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

/**
 * Whish-to-Whish payments checked by the shop. No Whish API: the customer pays the shop's Whish
 * number with the order number in the note, taps "I paid", and the shop confirms or refuses.
 */
class WhishTransferTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const CUSTOMER = '+96170123456';

    private const STAFF = '+96176000009';

    private Merchant $merchant;

    private string $token;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Inside the default opening hours (09:00–23:00) whatever time the suite runs.
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Asia/Beirut'));
        $this->seed(RolePermissionSeeder::class);
        config(['whatsapp.platform_access_token' => 'test-token', 'app.url' => 'https://api.test', 'app.frontend_url' => 'https://neotalab.com']);
        Bus::fake([CheckUnacceptedOrder::class, CheckWhishTransfer::class, RemindShopOfWhishCheck::class, CheckLateOrder::class]);

        Http::fake([
            'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]], 200),
        ]);

        $settings = ['bot' => ['phone_id' => '555'], 'mode' => 'auto', 'lbp_rate' => 89500, 'whish' => ['number' => '70123456'], 'wish_timeout_mins' => 15];
        [$this->merchant, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['name' => 'Beirut Bites', 'currency' => 'USD', 'settings' => $settings]);
        $this->token = $this->tokenFor($owner);
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Shawarma', 'price' => 6]);

        $staff = Driver::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Nour', 'role' => 'staff', 'phone' => self::STAFF, 'whatsapp_number' => self::STAFF, 'password' => 'x', 'status' => 'on_duty', 'availability' => 'available']);
        $this->staffWroteToShop($this->merchant, self::STAFF);
    }

    private function say(string $text, string $from = self::CUSTOMER): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [['id' => 'wamid.IN'.(++$this->seq), 'from' => ltrim($from, '+'), 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]]);
    }

    /** A button tap, from the customer or from staff. */
    private function tap(string $buttonId, string $from): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [['id' => 'wamid.IN'.(++$this->seq), 'from' => ltrim($from, '+'), 'type' => 'interactive',
                'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $buttonId, 'title' => 'x']]]],
        ]]]]]]);
    }

    /** An order placed by the customer, then accepted by the shop (the shop phone types ✅). */
    private function acceptedWhishOrder(string $currency = 'USD'): Order
    {
        $this->say('add 2 shawarma');
        $this->say("confirm | | Bliss St, Building 4 | whish | {$currency}");
        $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->latest('number')->firstOrFail();
        $this->tap("stf:accept:{$order->id}", self::STAFF);

        return $order->fresh();
    }

    private function sentTo(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone);

        return Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['to'] === $digits)
            ->map(fn (Request $r) => $r['text']['body'] ?? ($r['interactive']['body']['text'] ?? ''))
            ->values()->all();
    }

    private function lastTo(string $phone): string
    {
        return collect($this->sentTo($phone))->last() ?? '';
    }

    /** Button ids of the last message sent to the phone. */
    private function lastButtonsTo(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone);
        $last = Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['to'] === $digits)->last();

        return collect($last['interactive']['action']['buttons'] ?? [])->pluck('reply.id')->all();
    }

    public function test_accepting_sends_the_shop_number_the_order_number_and_a_paid_button(): void
    {
        $order = $this->acceptedWhishOrder();

        $this->assertSame('pending_payment', $order->status);
        $this->assertSame('pending', $order->payment_status);
        $message = $this->lastTo(self::CUSTOMER);
        $this->assertStringContainsString('70123456', $message);
        $this->assertStringContainsString((string) $order->number, $message, 'the order number must be in the note');
        $this->assertStringContainsString('12.00$', $message);
        $this->assertSame(["wh:paid:{$order->id}"], $this->lastButtonsTo(self::CUSTOMER));
    }

    public function test_no_request_is_ever_sent_to_the_whish_api(): void
    {
        $this->acceptedWhishOrder();
        $this->tap('wh:paid:'.Order::query()->withoutGlobalScopes()->latest('number')->value('id'), self::CUSTOMER);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'whish.money'));
    }

    public function test_tapping_i_paid_sends_the_order_to_the_shop_to_check(): void
    {
        $order = $this->acceptedWhishOrder();

        $this->tap("wh:paid:{$order->id}", self::CUSTOMER);

        $this->assertSame('awaiting_check', $order->fresh()->payment_status);
        $this->assertSame('pending_payment', $order->fresh()->status, 'the customer cannot make the order go ahead');
        $this->assertStringContainsString('checking', $this->lastTo(self::CUSTOMER));
        $this->assertSame([
            "stf:wh_ok:{$order->id}",
            "stf:wh_no:{$order->id}",
        ], $this->lastButtonsTo(self::STAFF));
    }

    public function test_typing_paid_changes_nothing(): void
    {
        $order = $this->acceptedWhishOrder();

        $this->say('I paid');
        $this->say('paid');

        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_the_shop_confirms_a_checked_payment_and_the_order_goes_ahead(): void
    {
        $order = $this->acceptedWhishOrder();
        $this->tap("wh:paid:{$order->id}", self::CUSTOMER);

        $this->tap("stf:wh_ok:{$order->id}", self::STAFF);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('confirmed', $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertStringContainsString('Payment received', $this->lastTo(self::CUSTOMER));
    }

    public function test_the_shop_refuses_a_payment_it_did_not_receive_and_the_order_is_cancelled(): void
    {
        $order = $this->acceptedWhishOrder();
        $this->tap("wh:paid:{$order->id}", self::CUSTOMER);

        $this->tap("stf:wh_no:{$order->id}", self::STAFF);

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('failed', $order->payment_status);
        $this->assertStringContainsString('could not find your Whish payment', $this->lastTo(self::CUSTOMER));
    }

    public function test_only_a_payment_waiting_for_the_check_can_be_confirmed(): void
    {
        $order = $this->acceptedWhishOrder();

        $this->tap("stf:wh_ok:{$order->id}", self::STAFF);

        $this->assertSame('pending', $order->fresh()->payment_status, 'the customer has not said they paid');
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_confirming_twice_changes_nothing_the_second_time(): void
    {
        $order = $this->acceptedWhishOrder();
        $this->tap("wh:paid:{$order->id}", self::CUSTOMER);
        $this->tap("stf:wh_ok:{$order->id}", self::STAFF);
        $paidAt = $order->fresh()->paid_at;
        $sent = count($this->sentTo(self::CUSTOMER));

        $this->tap("stf:wh_ok:{$order->id}", self::STAFF);

        $this->assertEquals($paidAt, $order->fresh()->paid_at);
        $this->assertCount($sent, $this->sentTo(self::CUSTOMER), 'the customer is not told twice');
    }

    public function test_a_refused_payment_cannot_be_confirmed_afterwards(): void
    {
        $order = $this->acceptedWhishOrder();
        $this->tap("wh:paid:{$order->id}", self::CUSTOMER);
        $this->tap("stf:wh_no:{$order->id}", self::STAFF);

        $this->tap("stf:wh_ok:{$order->id}", self::STAFF);

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('failed', $order->fresh()->payment_status);
    }

    public function test_a_customer_cannot_confirm_their_own_payment_with_a_staff_button(): void
    {
        $order = $this->acceptedWhishOrder();
        $this->tap("wh:paid:{$order->id}", self::CUSTOMER);

        $this->tap("stf:wh_ok:{$order->id}", self::CUSTOMER);

        $this->assertSame('awaiting_check', $order->fresh()->payment_status);
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_when_the_customer_never_pays_the_order_is_cancelled_at_the_end_of_the_window(): void
    {
        $order = $this->acceptedWhishOrder();

        (new CheckWhishTransfer($order->id))->handle(app(OrderConfirmation::class));

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->assertStringContainsString('did not receive', $this->lastTo(self::CUSTOMER));
    }

    public function test_an_order_already_waiting_for_the_check_is_not_cancelled_by_the_window(): void
    {
        $order = $this->acceptedWhishOrder();
        $this->tap("wh:paid:{$order->id}", self::CUSTOMER);

        (new CheckWhishTransfer($order->id))->handle(app(OrderConfirmation::class));

        $this->assertSame('awaiting_check', $order->fresh()->payment_status);
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_a_lbp_order_shows_the_lbp_total_to_pay(): void
    {
        $order = $this->acceptedWhishOrder('LBP');

        $this->assertSame('LBP', $order->payment_currency);
        $message = $this->lastTo(self::CUSTOMER);
        $this->assertStringContainsString('LBP', $message);
        $this->assertStringContainsString(number_format((float) $order->total_lbp, 0, '.', ','), $message);
    }

    public function test_a_shop_without_a_whish_number_does_not_offer_whish(): void
    {
        $this->merchant->forceFill(['settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto', 'lbp_rate' => 89500]])->save();
        $this->say('add 2 shawarma');
        $this->say('confirm | | Bliss St, Building 4 | whish | USD');

        $this->assertSame(0, Order::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->where('payment_method', 'whish')->count());
    }

    public function test_a_whish_number_that_is_not_a_number_is_refused_and_does_not_switch_whish_off(): void
    {
        foreach (['not a number', 'abc', '70123456abc'] as $bad) {
            $this->withToken($this->token)->patchJson('/api/v1/settings/profile', ['whish_number' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors('whish_number');
        }

        $this->withToken($this->token)->patchJson('/api/v1/settings/profile', ['whish_number' => '+961 70 123 456'])->assertOk();
        $this->assertSame('96170123456', ($this->merchant->fresh()->settings['whish'] ?? [])['number']);

        // An empty value still turns Whish off on purpose.
        $this->withToken($this->token)->patchJson('/api/v1/settings/profile', ['whish_number' => ''])->assertOk();
        $this->assertSame('', ($this->merchant->fresh()->settings['whish'] ?? [])['number']);
    }

    public function test_the_payment_window_is_kept_between_5_and_120_minutes(): void
    {
        foreach ([0, -5, 4, 121, 99999999] as $bad) {
            $this->withToken($this->token)->patchJson('/api/v1/settings/profile', ['wish_timeout_mins' => $bad])->assertStatus(422);
        }
        $this->assertSame(15, $this->merchant->fresh()->settings['wish_timeout_mins']);

        $this->withToken($this->token)->patchJson('/api/v1/settings/profile', ['wish_timeout_mins' => 30])->assertOk();
        $this->assertSame(30, $this->merchant->fresh()->settings['wish_timeout_mins']);
    }

    public function test_the_old_whish_callback_is_gone(): void
    {
        $this->postJson('/api/v1/webhooks/whish/1/success')->assertNotFound();
    }

    public function test_the_payment_check_reaches_the_shop_outside_the_24_hour_window_by_the_approved_template(): void
    {
        $settings = $this->merchant->fresh()->settings;
        $settings['bot']['templates'][ShopTemplateService::PAYMENT_CHECK] = ['status' => 'APPROVED'];
        $this->merchant->forceFill(['settings' => $settings])->save();
        $order = $this->acceptedWhishOrder();
        // Days later the shop's staff has not written: only a template can reach them.
        Carbon::setTestNow(Carbon::parse('2026-10-08 12:00', 'Asia/Beirut'));

        $this->tap("wh:paid:{$order->id}", self::CUSTOMER);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/messages')
            && ($r['template']['name'] ?? null) === ShopTemplateService::PAYMENT_CHECK
            && $r['to'] === ltrim(self::STAFF, '+'));
    }

    public function test_the_shop_is_asked_again_half_an_hour_after_i_paid_if_it_has_not_checked(): void
    {
        $order = $this->acceptedWhishOrder();
        $this->tap("wh:paid:{$order->id}", self::CUSTOMER);
        Bus::assertDispatched(RemindShopOfWhishCheck::class, fn ($job) => $job->orderId === $order->id);

        $before = count($this->sentTo(self::STAFF));
        (new RemindShopOfWhishCheck($order->id))->handle(app(OrderConfirmation::class));

        $this->assertGreaterThan($before, count($this->sentTo(self::STAFF)));
        $this->assertSame('awaiting_check', $order->fresh()->payment_status);
    }

    public function test_the_expired_message_offers_i_paid_for_a_customer_who_paid_late(): void
    {
        $order = $this->acceptedWhishOrder();

        (new CheckWhishTransfer($order->id))->handle(app(OrderConfirmation::class));

        $this->assertSame(["wh:paid:{$order->id}"], $this->lastButtonsTo(self::CUSTOMER));
    }

    public function test_a_late_payment_is_checked_and_flagged_for_refund_when_the_shop_received_it(): void
    {
        $order = $this->acceptedWhishOrder();
        (new CheckWhishTransfer($order->id))->handle(app(OrderConfirmation::class));
        $this->assertSame('cancelled', $order->fresh()->status);

        $this->tap("wh:paid:{$order->id}", self::CUSTOMER);
        $this->assertSame('awaiting_check', $order->fresh()->payment_status);
        $this->assertSame('cancelled', $order->fresh()->status, 'a cancelled order is not revived');

        $this->tap("stf:wh_ok:{$order->id}", self::STAFF);

        $this->assertSame('refund_due', $order->fresh()->payment_status);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertStringContainsString('after it was cancelled', $this->lastTo(self::CUSTOMER));
    }

    public function test_a_late_payment_the_shop_did_not_receive_stays_unpaid(): void
    {
        $order = $this->acceptedWhishOrder();
        (new CheckWhishTransfer($order->id))->handle(app(OrderConfirmation::class));
        $this->tap("wh:paid:{$order->id}", self::CUSTOMER);

        $this->tap("stf:wh_no:{$order->id}", self::STAFF);

        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_i_paid_after_a_day_of_cancellation_is_ignored(): void
    {
        $order = $this->acceptedWhishOrder();
        (new CheckWhishTransfer($order->id))->handle(app(OrderConfirmation::class));

        Carbon::setTestNow(Carbon::now()->addDays(2));
        $this->tap("wh:paid:{$order->id}", self::CUSTOMER);

        $this->assertSame('failed', $order->fresh()->payment_status);
    }
}
