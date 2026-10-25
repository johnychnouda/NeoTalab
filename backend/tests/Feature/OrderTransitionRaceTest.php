<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\OrderService;
use App\Support\CurrentTenant;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Two people close the same order at the same moment (the customer cancels while the staff tap
 * "Can't take it", or a driver taps Delivered twice): only one of them changes it.
 */
class OrderTransitionRaceTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Merchant $merchant;

    private Product $item;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Bus::fake();
        [$this->merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['currency' => 'USD']);
        app(CurrentTenant::class)->set($this->merchant);
        $this->item = Product::factory()->forMerchant($this->merchant)->create(['name' => 'Cake', 'price' => 10, 'track_inventory' => true, 'stock_quantity' => 4]);
        $customer = Customer::factory()->forMerchant($this->merchant)->create(['total_orders' => 1, 'total_spent' => 10]);
        $this->order = Order::create([
            'merchant_id' => $this->merchant->id, 'number' => 1, 'customer_id' => $customer->id, 'status' => 'confirmed',
            'subtotal' => 10, 'delivery_fee' => 0, 'total' => 10, 'payment_method' => 'cash', 'fulfillment' => 'delivery',
        ]);
        OrderItem::create([
            'merchant_id' => $this->merchant->id, 'order_id' => $this->order->id, 'product_id' => $this->item->id,
            'product_name' => 'Cake', 'quantity' => 1, 'unit_price' => 10, 'item_total' => 10, 'modifiers' => [],
        ]);
    }

    /** Runs $other once, right after $first has read the order and before it saves its change. */
    private function interleave(callable $first, callable $other): ?ValidationException
    {
        $done = false;
        Order::retrieved(function () use (&$done, $other) {
            if (! $done) {
                $done = true;
                $other();
            }
        });
        try {
            $first();
        } catch (ValidationException $e) {
            return $e;
        }

        return null;
    }

    public function test_a_cancel_and_a_refusal_at_the_same_moment_give_the_stock_back_once(): void
    {
        $orders = app(OrderService::class);
        $refused = $this->interleave(
            fn () => $orders->transition($this->merchant, $this->order->id, 'reject', ['reason' => 'busy']),
            fn () => $orders->transition($this->merchant, $this->order->id, 'cancel', ['by' => 'customer']),
        );

        $this->assertNotNull($refused, 'the second change is refused, not applied on top');
        $this->assertSame('cancelled', $this->order->fresh()->status);
        $this->assertSame(5, $this->item->fresh()->stock_quantity, 'one cake back, not two');
    }

    public function test_two_delivered_taps_at_the_same_moment_count_the_cash_once(): void
    {
        $driver = Driver::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Sam', 'phone' => '+96171000001',
            'password' => 'x', 'status' => 'on_duty', 'availability' => 'busy', 'active_order_id' => $this->order->id]);
        $this->order->forceFill(['driver_id' => $driver->id, 'status' => 'picked_up'])->save();

        $orders = app(OrderService::class);
        $this->interleave(
            fn () => $orders->transition($this->merchant, $this->order->id, 'delivered'),
            fn () => $orders->transition($this->merchant, $this->order->id, 'delivered'),
        );

        $this->assertSame('delivered', $this->order->fresh()->status);
        $this->assertSame(1, (int) $driver->fresh()->total_deliveries);
        $this->assertSame('10.00', number_format((float) $driver->fresh()->cash_on_hand, 2, '.', ''));
    }
}
