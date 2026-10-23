<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\DailyReportService;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * The nightly report's figures are checked against the rows they are summed from, for one shop's day
 * (Beirut time), so a figure can't drift from the database unnoticed.
 */
class ReportFiguresTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Merchant $shop;

    private Merchant $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        [$this->shop] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['currency' => 'USD', 'timezone' => 'Asia/Beirut', 'settings' => ['lbp_rate' => 89500]]);
        [$this->other] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['currency' => 'USD', 'timezone' => 'Asia/Beirut']);
    }

    private function order(Merchant $m, array $attrs): Order
    {
        $customer = Customer::query()->withoutGlobalScopes()->forceCreate(['merchant_id' => $m->id, 'phone' => '+961'.random_int(70000000, 79999999)]);

        return Order::query()->withoutGlobalScopes()->forceCreate(array_merge([
            'merchant_id' => $m->id, 'customer_id' => $customer->id, 'number' => random_int(1000, 99999),
            'status' => 'delivered', 'subtotal' => 0, 'delivery_fee' => 0, 'total' => 0, 'payment_method' => 'cash',
            'payment_status' => 'cash', 'fulfillment' => 'delivery',
        ], $attrs));
    }

    private function item(Order $order, string $name, int $qty): void
    {
        OrderItem::query()->withoutGlobalScopes()->forceCreate(['merchant_id' => $order->merchant_id, 'order_id' => $order->id,
            'product_id' => null, 'product_name' => $name, 'quantity' => $qty, 'unit_price' => 1, 'item_total' => $qty, 'modifiers' => []]);
    }

    public function test_every_figure_matches_the_database_for_the_day(): void
    {
        $day = Carbon::parse('2026-10-20', 'Asia/Beirut');
        $noon = $day->copy()->setTime(12, 0);

        // Delivered on the day: two in dollars, one paid partly in lira.
        $a = $this->order($this->shop, ['delivered_at' => $noon, 'total' => 11.25, 'rated_at' => $noon, 'rating' => 5]);
        $this->item($a, 'Falafel', 3);
        $this->item($a, 'Zaatar', 2);
        $b = $this->order($this->shop, ['delivered_at' => $noon->copy()->addHour(), 'total' => 6.50, 'rated_at' => $noon, 'rating' => 3]);
        $this->item($b, 'Falafel', 4);
        $c = $this->order($this->shop, ['delivered_at' => $noon->copy()->addHours(2), 'total' => 10.00, 'payment_currency' => 'LBP', 'total_lbp' => 895000]);
        $this->item($c, 'Kunafa', 1);

        // Must not count: another day, cancelled, another shop, and a late one (counted separately).
        $this->order($this->shop, ['delivered_at' => $day->copy()->subDay()->setTime(12, 0), 'total' => 99]);
        $this->order($this->shop, ['status' => 'cancelled', 'total' => 50, 'delivered_at' => $noon]);
        $this->order($this->other, ['delivered_at' => $noon, 'total' => 77]);
        $this->order($this->shop, ['delivered_at' => $noon, 'total' => 1, 'late_notified_at' => $noon->copy()->addMinutes(30), 'status' => 'delivered']);

        $figures = app(DailyReportService::class)->figures($this->shop, $day);

        // Expected, worked out from the rows above (not from the report code).
        $delivered = Order::query()->withoutGlobalScopes()->where('merchant_id', $this->shop->id)->where('status', 'delivered')
            ->whereDate('delivered_at', '2026-10-20')->get();
        $this->assertSame((string) $delivered->count(), $figures['orders'], 'orders');
        $this->assertSame((string) $delivered->count(), '4');
        $this->assertSame('28.75$ (incl. 895,000 LBP cash)', $figures['sales'], 'sales = 11.25 + 6.50 + 10.00 + 1.00 (the late one still counts)');
        $this->assertStringContainsString('incl.', $figures['sales']);
        $this->assertSame('4.0 / 5', $figures['rating'], 'average of 5 and 3');
        $this->assertSame('1', $figures['late']);
        $this->assertSame('Falafel ×7, Zaatar ×2, Kunafa ×1', $figures['top']);
        $this->assertSame('2026-10-20', $figures['date']);
    }

    public function test_a_quiet_day_says_none_instead_of_zero_ratings(): void
    {
        $figures = app(DailyReportService::class)->figures($this->shop, Carbon::parse('2026-10-21', 'Asia/Beirut'));

        $this->assertSame('0', $figures['orders']);
        $this->assertSame('0.00$', $figures['sales']);
        $this->assertSame('0', $figures['late']);
        $this->assertSame(__('whatsapp.report.none', [], 'en'), $figures['rating']);
        $this->assertSame(__('whatsapp.report.none', [], 'en'), $figures['top']);
    }
}
