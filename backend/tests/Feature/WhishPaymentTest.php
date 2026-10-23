<?php

namespace Tests\Feature;

use App\Jobs\CheckLateOrder;
use App\Jobs\CheckUnacceptedOrder;
use App\Jobs\CheckWhishPayment;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderConfirmation;
use App\Services\OrderService;
use App\Services\WhatsApp\ShopTemplateService;
use App\Services\WhatsApp\WhatsAppInboundService;
use App\Services\WhishPayService;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class WhishPaymentTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const CUSTOMER = '+96170123456';

    private Merchant $merchant;

    private string $token;

    private string $whishStatus = 'pending';

    private int $seq = 0;

    /** true = details work, false = Whish refuses them, null = Whish is down. */
    private ?bool $whishAccepts = true;

    /** What Whish answers about the shop's account (its real reply to wrong details: status false). */
    private function whishAccount()
    {
        if ($this->whishAccepts === null) {
            return Http::response('Service Unavailable', 503);
        }

        return $this->whishAccepts
            ? Http::response(['status' => true, 'data' => ['balanceDetails' => ['balance' => 0]]], 200)
            : Http::response(['status' => false, 'code' => 'auth.session_not_exist'], 200);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Inside the default opening hours (09:00–23:00) whatever time the suite runs.
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Asia/Beirut'));
        $this->seed(RolePermissionSeeder::class);
        config(['whatsapp.platform_access_token' => 'test-token', 'app.url' => 'https://api.test', 'app.frontend_url' => 'https://neotalab.com']);
        Bus::fake([CheckUnacceptedOrder::class, CheckWhishPayment::class, CheckLateOrder::class]);

        Http::fake([
            'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]], 200),
            'lb.sandbox.whish.money/itel-service/api/payment/whish' => Http::response(['status' => true, 'code' => null, 'data' => ['collectUrl' => 'https://pay.whish.test/abc']], 200),
            // Saving Whish details asks Whish whether they work (live and sandbox).
            'lb.sandbox.whish.money/itel-service/api/payment/account/balance' => fn () => $this->whishAccount(),
            'whish.money/itel-service/api/payment/account/balance' => fn () => $this->whishAccount(),
            'lb.sandbox.whish.money/itel-service/api/payment/collect/status' => fn () => $this->whishStatus === 'down'
                ? Http::response('Service Unavailable', 503)
                : Http::response(['status' => true, 'data' => ['collectStatus' => $this->whishStatus]], 200),
        ]);

        $settings = ['bot' => ['phone_id' => '555'], 'mode' => 'auto', 'lbp_rate' => 89500, 'wish_timeout_mins' => 15];
        WhishPayService::applySettings($settings, '10199', 'top-secret', true);

        [$this->merchant, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['name' => 'Beirut Bites', 'currency' => 'USD', 'settings' => $settings]);
        $this->token = $this->tokenFor($owner);
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Shawarma', 'price' => 6]);
    }

    private function say(string $text, string $from = self::CUSTOMER): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [['id' => 'wamid.IN'.(++$this->seq), 'from' => ltrim($from, '+'), 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]]);
    }

    /** A Whish order; at a shop without reachable staff, the shop phone accepts it with ✅ (unless $shopAccepts is false). */
    private function orderWithWhish(string $currency = 'USD', bool $shopAccepts = true): Order
    {
        $this->say('add 2 shawarma');
        $this->say("confirm | | Bliss St, Building 4 | whish | {$currency}");

        $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->latest('number')->firstOrFail();
        if ($shopAccepts && ! app(OrderConfirmation::class)->needsShopAcceptance($this->merchant->fresh())) {
            $this->shopTypes('✅');
            $order->refresh();
        }

        return $order;
    }

    /** Whoever holds the shop phone types this in the customer's chat (a WhatsApp Business app echo). */
    private function shopTypes(string $text, string $to = self::CUSTOMER): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['field' => 'smb_message_echoes', 'value' => [
            'metadata' => ['phone_number_id' => '555'],
            'message_echoes' => [['from' => '96171000000', 'to' => ltrim($to, '+'), 'id' => 'wamid.ECHO'.(++$this->seq), 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]]);
    }

    private function lastTo(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        $sent = Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['to'] === $digits);

        return $sent->last()['text']['body'] ?? ($sent->last()['interactive']['body']['text'] ?? '');
    }

    private function staffTaps(string $buttonId, string $staffPhone): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [['id' => 'wamid.IN'.(++$this->seq), 'from' => ltrim($staffPhone, '+'), 'type' => 'interactive',
                'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $buttonId, 'title' => 'x']]]],
        ]]]]]]);
    }

    private function whishCallback(Order $order, string $result = 'success')
    {
        return $this->get(URL::signedRoute('whish.callback', ['order' => $order->id, 'result' => $result], null, false));
    }

    public function test_whish_order_waits_for_payment_and_customer_gets_the_link(): void
    {
        $rami = Driver::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Rami', 'role' => 'driver', 'phone' => '+96176000001', 'whatsapp_number' => '+96176000001', 'password' => 'x', 'status' => 'on_duty', 'availability' => 'available']);

        $order = $this->orderWithWhish();

        $this->assertSame('pending_payment', $order->status);
        $this->assertSame('pending', $order->payment_status);
        $this->assertNull($order->driver_id, 'no driver before payment');
        $this->assertStringContainsString('Pay 12.00$ with Whish here', $this->lastTo(self::CUSTOMER));
        $this->assertStringContainsString('https://pay.whish.test/abc', $this->lastTo(self::CUSTOMER));
        $this->assertSame('', $this->lastTo($rami->whatsapp_number));

        $create = Http::recorded()->map(fn ($p) => $p[0])->first(fn (Request $r) => str_ends_with($r->url(), '/payment/whish'));
        $this->assertSame('10199', $create->header('channel')[0]);
        $this->assertSame('top-secret', $create->header('secret')[0]);
        $this->assertSame('neotalab.com', $create->header('websiteurl')[0]);
        $this->assertEquals(12, $create['amount']);
        $this->assertSame('USD', $create['currency']);
        $this->assertSame((int) $order->whish_external_id, $create['externalId']);
        $this->assertStringStartsWith('https://api.test/api/v1/webhooks/whish/'.$order->id.'/success?', $create['successCallbackUrl']);
        $this->assertStringStartsWith('https://neotalab.com/pay/success?lang=', $create['successRedirectUrl']);

        Bus::assertDispatched(CheckWhishPayment::class, fn ($job) => $job->orderId === $order->id && $job->final);
    }

    public function test_verified_payment_confirms_the_order_and_dispatches_it(): void
    {
        $rami = Driver::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Rami', 'role' => 'driver', 'phone' => '+96176000001', 'whatsapp_number' => '+96176000001', 'password' => 'x', 'status' => 'on_duty', 'availability' => 'available']);
        $order = $this->orderWithWhish();

        $this->whishStatus = 'success';
        $this->whishCallback($order)->assertOk()->assertJsonPath('payment_status', 'paid');

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame($rami->id, $order->driver_id);
        $this->assertStringContainsString('Payment received! Order #1 is confirmed', $this->lastTo(self::CUSTOMER));
        $this->assertStringContainsString('New delivery #1', $this->lastTo($rami->whatsapp_number));

        $this->whishCallback($order)->assertOk()->assertJsonPath('payment_status', 'paid'); // repeated callback is harmless
    }

    public function test_a_shop_with_staff_accepts_first_and_only_then_the_customer_pays(): void
    {
        Driver::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Nour', 'role' => 'staff', 'phone' => '+96176000009', 'whatsapp_number' => '+96176000009', 'password' => 'x', 'status' => 'on_duty', 'availability' => 'available']);
        $this->staffWroteToShop($this->merchant, '+96176000009');

        $order = $this->orderWithWhish();

        // 1. Waiting for the shop: no payment link yet.
        $this->assertSame('placed', $order->status);
        $this->assertSame('pending', $order->payment_status);
        $this->assertStringContainsString('Whish payment link here as soon as the shop accepts', $this->lastTo(self::CUSTOMER));
        $this->assertFalse(Http::recorded()->contains(fn ($p) => str_ends_with($p[0]->url(), '/payment/whish')));
        $this->assertStringContainsString("stf:accept:{$order->id}", json_encode(Http::recorded()->map(fn ($p) => $p[0]->data())->all()));

        // 2. The shop accepts: the customer gets the link, the staff are told to wait for the payment.
        $this->staffTaps("stf:accept:{$order->id}", '+96176000009');
        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->accepted_at);
        $this->assertStringContainsString('Beirut Bites accepted your order #1! Pay 12.00$ with Whish here', $this->lastTo(self::CUSTOMER));
        $this->assertStringContainsString('https://pay.whish.test/abc', $this->lastTo(self::CUSTOMER));
        $this->assertStringContainsString("Don't prepare it before", $this->lastTo('+96176000009'));
        Bus::assertDispatched(CheckWhishPayment::class, fn ($job) => $job->final);

        // 3. Paid: the order goes ahead and the staff get it with its buttons.
        $this->whishStatus = 'success';
        $this->whishCallback($order)->assertOk()->assertJsonPath('payment_status', 'paid');
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertStringContainsString('Payment received! Order #1 is confirmed', $this->lastTo(self::CUSTOMER));
        $this->assertStringContainsString('Order #1 is paid with Whish — prepare it now.', $this->lastTo('+96176000009'));
    }

    public function test_a_refused_whish_order_was_never_paid_so_nothing_is_refunded(): void
    {
        Driver::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Nour', 'role' => 'staff', 'phone' => '+96176000009', 'whatsapp_number' => '+96176000009', 'password' => 'x', 'status' => 'on_duty', 'availability' => 'available']);
        $this->staffWroteToShop($this->merchant, '+96176000009');
        $order = $this->orderWithWhish();

        $this->staffTaps("stf:reject:{$order->id}", '+96176000009');

        $this->assertSame('rejected', $order->fresh()->status);
        $this->assertNotSame('refund_due', $order->fresh()->payment_status);
        $this->assertFalse(Http::recorded()->contains(fn ($p) => str_ends_with($p[0]->url(), '/payment/whish')), 'no link was ever sent');
    }

    public function test_staff_are_told_when_an_accepted_order_is_never_paid(): void
    {
        Driver::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Nour', 'role' => 'staff', 'phone' => '+96176000009', 'whatsapp_number' => '+96176000009', 'password' => 'x', 'status' => 'on_duty', 'availability' => 'available']);
        $this->staffWroteToShop($this->merchant, '+96176000009');
        $order = $this->orderWithWhish();
        $this->staffTaps("stf:accept:{$order->id}", '+96176000009');

        (new CheckWhishPayment($order->id, true))->handle(app(OrderConfirmation::class));

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertStringContainsString('did not receive the Whish payment', $this->lastTo(self::CUSTOMER));
        $this->assertStringContainsString("the customer did not pay with Whish. Don't prepare it", $this->lastTo('+96176000009'));
    }

    public function test_without_staff_the_shop_phone_accepts_with_a_tick_in_the_chat(): void
    {
        $order = $this->orderWithWhish(shopAccepts: false);

        $this->assertSame('placed', $order->status);
        $this->assertStringContainsString('reply ✅ here to accept', $this->lastTo(self::CUSTOMER));
        $this->assertFalse(Http::recorded()->contains(fn ($p) => str_ends_with($p[0]->url(), '/payment/whish')), 'no link before the shop accepts');

        // The customer typing ✅ does not accept their own order.
        $this->say('✅');
        $this->assertSame('placed', $order->fresh()->status);

        $this->shopTypes('✅');
        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertStringContainsString('Beirut Bites accepted your order #1! Pay 12.00$ with Whish here', $this->lastTo(self::CUSTOMER));
    }

    public function test_the_shop_phone_refuses_with_a_cross(): void
    {
        $order = $this->orderWithWhish(shopAccepts: false);

        $this->shopTypes('❌');

        $this->assertSame('rejected', $order->fresh()->status);
        $this->assertStringContainsString('could not accept your order #1', $this->lastTo(self::CUSTOMER));
        $this->assertFalse(Http::recorded()->contains(fn ($p) => str_ends_with($p[0]->url(), '/payment/whish')));
    }

    public function test_the_reminder_shows_in_the_customer_chat_when_there_is_no_staff(): void
    {
        $order = $this->orderWithWhish(shopAccepts: false);

        (new CheckUnacceptedOrder($order->id))->handle(app(OrderService::class));
        $this->assertStringContainsString('still waiting for Beirut Bites to accept', $this->lastTo(self::CUSTOMER));
        $this->assertSame('placed', $order->fresh()->status, 'the bot never accepts a Whish order by itself');

        (new CheckUnacceptedOrder($order->id, true))->handle(app(OrderService::class));
        $this->assertSame('rejected', $order->fresh()->status);
        $this->assertStringContainsString('could not confirm your order #1', $this->lastTo(self::CUSTOMER));
    }

    public function test_a_tick_in_an_ordinary_chat_changes_nothing(): void
    {
        $this->shopTypes('✅');

        $this->assertSame(0, Order::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->count());
    }

    public function test_callback_alone_is_not_trusted(): void
    {
        $order = $this->orderWithWhish();
        $this->whishStatus = 'pending';

        $this->whishCallback($order)->assertOk()->assertJsonPath('payment_status', 'pending');

        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_forged_callback_is_rejected(): void
    {
        $order = $this->orderWithWhish();
        $this->whishStatus = 'success';

        $this->get("/api/v1/webhooks/whish/{$order->id}/success")->assertForbidden();
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_callback_still_works_when_whish_adds_parameters(): void
    {
        $order = $this->orderWithWhish();
        $this->whishStatus = 'success';
        $url = URL::signedRoute('whish.callback', ['order' => $order->id, 'result' => 'success'], null, false);

        $this->get($url.'&externalId=123&status=success')->assertOk()->assertJsonPath('payment_status', 'paid');
    }

    public function test_a_signature_for_another_order_is_rejected(): void
    {
        $order = $this->orderWithWhish();
        $this->whishStatus = 'success';
        $other = URL::signedRoute('whish.callback', ['order' => 'some-other-order', 'result' => 'success'], null, false);

        $this->get(str_replace('some-other-order', $order->id, $other))->assertForbidden();
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_payment_is_checked_several_times_during_the_window(): void
    {
        $this->orderWithWhish();

        Bus::assertDispatchedTimes(CheckWhishPayment::class, 5); // 1, 3, 6, 10 min + the final check at 15
        Bus::assertDispatched(CheckWhishPayment::class, fn ($job) => $job->final);
    }

    public function test_failed_payment_cancels_the_order(): void
    {
        $order = $this->orderWithWhish();
        $this->whishStatus = 'failed';

        $this->whishCallback($order, 'failure')->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->assertStringContainsString('did not go through', $this->lastTo(self::CUSTOMER));
    }

    public function test_unpaid_order_is_cancelled_when_the_window_closes(): void
    {
        $order = $this->orderWithWhish();

        (new CheckWhishPayment($order->id))->handle(app(OrderConfirmation::class));
        $this->assertSame('pending_payment', $order->fresh()->status, 'early check keeps waiting');

        (new CheckWhishPayment($order->id, true))->handle(app(OrderConfirmation::class));
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertStringContainsString('did not receive the Whish payment', $this->lastTo(self::CUSTOMER));
    }

    public function test_whish_in_lbp_charges_the_lbp_total(): void
    {
        $this->orderWithWhish('LBP');

        $create = Http::recorded()->map(fn ($p) => $p[0])->first(fn (Request $r) => str_ends_with($r->url(), '/payment/whish'));
        $this->assertSame('LBP', $create['currency']);
        $this->assertEquals(1074000, $create['amount']);
    }

    public function test_shop_without_whish_falls_back_to_cash(): void
    {
        $settings = $this->merchant->settings;
        unset($settings['whish']);
        $this->merchant->update(['settings' => $settings]);

        $this->say('add 1 shawarma');
        $this->say('confirm | | Bliss St, Building 4 | whish');

        $this->assertSame(0, Order::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->count());
        $this->assertStringContainsString('Whish payment is not available', $this->lastTo(self::CUSTOMER));
    }

    public function test_dashboard_saves_whish_without_ever_returning_the_secret(): void
    {
        $this->withToken($this->token)->patchJson('/api/v1/settings/profile', ['whish_channel' => '20000', 'whish_secret' => 'new-secret', 'whish_sandbox' => false])
            ->assertOk()
            ->assertJsonPath('profile.whish_channel', '20000')
            ->assertJsonPath('profile.whish_connected', true)
            ->assertJsonMissing(['new-secret']);

        $raw = $this->merchant->fresh()->settings['whish']['secret'];
        $this->assertNotSame('new-secret', $raw, 'stored encrypted');
        $this->assertSame('new-secret', app(WhishPayService::class)->credentials($this->merchant->fresh())['secret']);
    }

    public function test_wrong_whish_details_are_refused_when_saving(): void
    {
        $this->whishAccepts = false;

        $this->withToken($this->token)->patchJson('/api/v1/settings/profile', ['whish_channel' => '10199', 'whish_secret' => 'typo-secret', 'whish_sandbox' => true])
            ->assertUnprocessable()->assertJsonValidationErrorFor('whish_secret');

        $saved = app(WhishPayService::class)->credentials($this->merchant->fresh());
        $this->assertSame('top-secret', $saved['secret'], 'the working secret is kept');
    }

    public function test_whish_details_are_saved_when_whish_cannot_be_reached(): void
    {
        $this->whishAccepts = null;

        $this->withToken($this->token)->patchJson('/api/v1/settings/profile', ['whish_channel' => '10199', 'whish_secret' => 'new-secret', 'whish_sandbox' => true])
            ->assertOk();

        $this->assertSame('new-secret', app(WhishPayService::class)->credentials($this->merchant->fresh())['secret']);
    }

    public function test_two_checks_at_once_confirm_the_order_only_once(): void
    {
        $order = $this->orderWithWhish();
        $this->whishStatus = 'success';
        $stale = $order->fresh();

        $this->assertSame('paid', app(OrderConfirmation::class)->settleWhish($this->merchant, $order->fresh()));
        // A second check that loaded the order before the first one finished must not confirm it again.
        $this->assertSame('paid', app(OrderConfirmation::class)->settleWhish($this->merchant, $stale));

        $paidMessages = Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && str_contains((string) ($r['text']['body'] ?? ''), 'Payment received'));
        $this->assertCount(1, $paidMessages);
    }

    public function test_whish_outage_never_cancels_an_order(): void
    {
        $order = $this->orderWithWhish();
        $this->whishStatus = 'down';

        try {
            (new CheckWhishPayment($order->id, true))->handle(app(OrderConfirmation::class));
            $this->fail('the final check should retry while Whish is down');
        } catch (\RuntimeException) {
        }

        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_payment_after_the_window_closed_is_flagged_for_refund(): void
    {
        $staff = Driver::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Nour', 'role' => 'staff', 'phone' => '+96176000009', 'whatsapp_number' => '+96176000009', 'password' => 'x', 'status' => 'on_duty', 'availability' => 'available']);
        $this->staffWroteToShop($this->merchant, '+96176000009');
        $order = $this->orderWithWhish();
        $this->staffTaps("stf:accept:{$order->id}", '+96176000009');
        (new CheckWhishPayment($order->id, true))->handle(app(OrderConfirmation::class));
        $this->assertSame('cancelled', $order->fresh()->status);

        $this->whishStatus = 'success';
        $this->whishCallback($order)->assertOk()->assertJsonPath('payment_status', 'refund_due');

        $this->assertSame('cancelled', $order->fresh()->status, 'a cancelled order is not revived');
        $this->assertStringContainsString('after it was cancelled', $this->lastTo(self::CUSTOMER));
        $this->assertStringContainsString('Refund needed: order #1', $this->lastTo($staff->whatsapp_number));
    }

    public function test_silent_staff_get_the_refund_alert_as_a_template(): void
    {
        Driver::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Nour', 'role' => 'staff', 'phone' => '+96176000009', 'whatsapp_number' => '+96176000009', 'password' => 'x', 'status' => 'on_duty', 'availability' => 'available']);
        $settings = $this->merchant->fresh()->settings;
        $settings['bot']['templates'][ShopTemplateService::REFUND_NEEDED] = ['status' => 'APPROVED'];
        $this->merchant->update(['settings' => $settings]);
        $order = $this->orderWithWhish();
        (new CheckWhishPayment($order->id, true))->handle(app(OrderConfirmation::class));

        $this->whishStatus = 'success';
        $this->whishCallback($order)->assertOk();

        $sent = Http::recorded()->map(fn ($p) => $p[0])->last(fn ($r) => str_ends_with($r->url(), '/messages') && $r['to'] === '96176000009');
        $this->assertSame(ShopTemplateService::REFUND_NEEDED, $sent['template']['name']);
        $this->assertSame('1', $sent['template']['components'][0]['parameters'][1]['text']);
    }

    public function test_cancelling_a_paid_order_flags_the_refund_and_returns_stock(): void
    {
        $product = Product::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->firstOrFail();
        $product->forceFill(['track_inventory' => true, 'stock_quantity' => 10])->save();

        $order = $this->orderWithWhish();
        $this->assertSame(8, $product->fresh()->stock_quantity);
        $this->whishStatus = 'success';
        $this->whishCallback($order)->assertOk();

        $staff = Driver::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Nour', 'role' => 'staff', 'phone' => '+96176000009', 'whatsapp_number' => '+96176000009', 'password' => 'x', 'status' => 'on_duty', 'availability' => 'available']);
        $this->staffWroteToShop($this->merchant, '+96176000009');

        app(OrderService::class)->transition($this->merchant, $order->id, 'cancel', ['reason' => 'Out of bread']);

        $this->assertSame('refund_due', $order->fresh()->payment_status);
        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertStringContainsString('Refund needed: order #1', $this->lastTo($staff->whatsapp_number));
        $this->assertStringContainsString('will be refunded', $this->lastTo(self::CUSTOMER));
    }

    public function test_sandbox_is_ignored_in_production(): void
    {
        config(['services.whish.sandbox_allowed' => false]);

        $this->assertFalse(app(WhishPayService::class)->credentials($this->merchant->fresh())['sandbox']);
    }

    public function test_a_lab_whish_address_is_never_used_in_production(): void
    {
        $whish = app(WhishPayService::class);
        $creds = ['channel' => 'c', 'secret' => 's', 'sandbox' => false];
        config(['services.whish.base_url' => 'http://127.0.0.1:8029']);

        $this->assertSame('http://127.0.0.1:8029', $whish->baseUrl($creds), 'labs may use a stand-in');

        $this->app['env'] = 'production';
        $this->assertSame(WhishPayService::LIVE_URL, $whish->baseUrl($creds));
        $this->assertSame(WhishPayService::SANDBOX_URL, $whish->baseUrl(['sandbox' => true] + $creds));
    }
}
