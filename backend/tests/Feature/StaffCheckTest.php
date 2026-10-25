<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Services\MenuImportService;
use App\Services\WhatsApp\WhatsAppInboundService;
use App\Support\CurrentTenant;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * An item the team must check first (for example an age limit) is never sold by the bot. The
 * customer is asked for a photo; the staff (or whoever holds the shop phone) approve or refuse.
 */
class StaffCheckTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const CUSTOMER = '+96170123456';

    private const STAFF = '+96171555000';

    private Merchant $merchant;

    private Product $antibiotic;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Asia/Beirut'));
        $this->seed(RolePermissionSeeder::class);
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]], 200)]);

        [$this->merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'name' => 'City Grocer', 'currency' => 'USD', 'timezone' => 'Asia/Beirut', 'business_type' => 'Supermarket / Grocery',
            'settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto', 'fulfillment' => 'pickup'],
        ]);
        $this->antibiotic = Product::factory()->forMerchant($this->merchant)->create(['name' => 'Cigarettes', 'price' => 6, 'staff_check' => true]);
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Biscuits', 'price' => 3]);
    }

    private function staff(): void
    {
        Driver::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Cashier', 'role' => 'staff', 'phone' => self::STAFF,
            'whatsapp_number' => self::STAFF, 'password' => 'x', 'status' => 'on_duty', 'availability' => 'available']);
        $this->staffWroteToShop($this->merchant, self::STAFF);
    }

    private function receive(array $msg, string $from = self::CUSTOMER): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [['id' => 'wamid.IN'.(++$this->seq), 'from' => ltrim($from, '+')] + $msg],
        ]]]]]]);
    }

    private function say(string $text, string $from = self::CUSTOMER): void
    {
        $this->receive(['type' => 'text', 'text' => ['body' => $text]], $from);
    }

    private function tap(string $id, string $from = self::CUSTOMER): void
    {
        $this->receive(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => 'x']]], $from);
    }

    private function echo(string $text): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['field' => 'smb_message_echoes', 'value' => [
            'metadata' => ['phone_number_id' => '555'],
            'message_echoes' => [['from' => '96171000000', 'to' => ltrim(self::CUSTOMER, '+'), 'id' => 'wamid.ECHO'.(++$this->seq), 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]]);
    }

    /** @return array<string, mixed> the last message sent to that number */
    private function lastTo(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone);

        return Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['to'] === $digits)->last()?->data() ?? [];
    }

    private function body(array $msg): string
    {
        return $msg['text']['body'] ?? ($msg['interactive']['body']['text'] ?? '');
    }

    private function customer(): Customer
    {
        return Customer::query()->withoutGlobalScopes()->where('phone', self::CUSTOMER)->sole();
    }

    private function cartCount(): int
    {
        return (int) $this->customer()->conversations()->withoutGlobalScopes()->latest()->first()?->cart?->items()->withoutGlobalScopes()->count();
    }

    public function test_the_bot_asks_for_the_check_and_the_staff_get_approve_and_refuse(): void
    {
        $this->staff();
        $this->say('add 1 Cigarettes');

        $this->assertSame(0, $this->cartCount(), 'never added without the team');
        $this->assertStringContainsString('needs a check by our team', $this->body($this->lastTo(self::CUSTOMER)));
        $ticket = $this->lastTo(self::STAFF);
        $this->assertStringContainsString('Cigarettes', $this->body($ticket));
        $this->assertSame("stf:approve:{$this->customer()->id}:{$this->antibiotic->id}", $ticket['interactive']['action']['buttons'][0]['reply']['id']);
        $this->assertSame((string) $this->antibiotic->id, $this->customer()->hasPendingCheck());

        // Ordinary items still go straight in.
        $this->say('add 1 Biscuits');
        $this->assertSame(1, $this->cartCount());
    }

    public function test_after_approval_the_customer_orders_it_once(): void
    {
        $this->staff();
        $this->say('add 1 Cigarettes');
        $this->tap("stf:approve:{$this->customer()->id}:{$this->antibiotic->id}", self::STAFF);

        $told = $this->lastTo(self::CUSTOMER);
        $this->assertStringContainsString('approved', $this->body($told));
        $this->assertSame("mn:item:{$this->antibiotic->id}", $told['interactive']['action']['buttons'][0]['reply']['id']);
        $this->assertStringContainsString('approved for', $this->body($this->lastTo(self::STAFF)));

        $this->tap("mn:item:{$this->antibiotic->id}");
        $this->tap('mn:qty:1');
        $this->assertSame(1, $this->cartCount());

        $this->say('confirm | | | cash');
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->count());
        $this->assertFalse($this->customer()->isApproved($this->antibiotic->id), 'one approval, one order');

        Carbon::setTestNow(now()->addMinute()); // the next conversation is the latest one
        $this->say('add 1 Cigarettes');
        $this->assertSame(0, $this->cartCount(), 'a new order needs a new check');
    }

    public function test_a_refusal_is_told_to_the_customer_and_nothing_is_added(): void
    {
        $this->staff();
        $this->say('add 1 Cigarettes');
        $this->tap("stf:refuse:{$this->customer()->id}:{$this->antibiotic->id}", self::STAFF);

        $this->assertStringContainsString('could not approve', $this->body($this->lastTo(self::CUSTOMER)));
        $this->assertNull($this->customer()->hasPendingCheck());
        $this->tap("mn:item:{$this->antibiotic->id}");
        $this->assertSame(0, $this->cartCount());
    }

    public function test_with_only_the_shop_phone_a_typed_tick_approves(): void
    {
        $this->say('add 1 Cigarettes');
        $this->echo('✅');

        $this->assertTrue($this->customer()->isApproved($this->antibiotic->id));
        $this->assertStringContainsString('approved', $this->body($this->lastTo(self::CUSTOMER)));
    }

    public function test_an_approval_ends_after_a_day(): void
    {
        $this->staff();
        $this->say('add 1 Cigarettes');
        $this->tap("stf:approve:{$this->customer()->id}:{$this->antibiotic->id}", self::STAFF);
        $this->tap("mn:item:{$this->antibiotic->id}");
        $this->tap('mn:qty:1');

        Carbon::setTestNow(now()->addHours(25));
        $this->say('confirm | | | cash');
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count(), 'an old approval does not sell it');
    }

    public function test_another_shops_staff_cannot_approve(): void
    {
        $this->say('add 1 Cigarettes');
        [$other] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['settings' => ['bot' => ['phone_id' => '556'], 'mode' => 'auto']]);
        Driver::query()->forceCreate(['merchant_id' => $other->id, 'name' => 'x', 'role' => 'staff', 'phone' => '+96171000009',
            'whatsapp_number' => '+96171000009', 'password' => 'x', 'status' => 'on_duty', 'availability' => 'available']);
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '556'],
            'messages' => [['id' => 'wamid.X1', 'from' => '96171000009', 'type' => 'interactive',
                'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => "stf:approve:{$this->customer()->id}:{$this->antibiotic->id}", 'title' => 'x']]]],
        ]]]]]]);

        $this->assertFalse($this->customer()->isApproved($this->antibiotic->id));
    }

    public function test_a_menu_file_can_mark_items_that_need_a_check(): void
    {
        app(CurrentTenant::class)->set($this->merchant);
        $service = app(MenuImportService::class);
        $rows = [['Name', 'Price', 'Team check'], ['Cigarettes', '9', 'yes'], ['Biscuits', '4', 'no']];
        $file = tempnam(sys_get_temp_dir(), 'menu').'.csv';
        $handle = fopen($file, 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        $preview = $service->preview(new UploadedFile($file, 'menu.csv', 'text/csv', null, true));
        $service->import($preview);

        $this->assertTrue((bool) Product::query()->where('name', 'Cigarettes')->value('staff_check'));
        $this->assertFalse((bool) Product::query()->where('name', 'Biscuits')->value('staff_check'));
    }
}
