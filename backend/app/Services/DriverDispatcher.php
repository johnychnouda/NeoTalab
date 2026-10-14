<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Order;
use Illuminate\Validation\ValidationException;

/**
 * Automatic driver assignment while the bot runs the shop: each accepted order goes to the
 * on-duty driver who has been free the longest; orders that find no free driver wait and are
 * handed out as drivers come back (delivery done, or "start" of a shift).
 */
class DriverDispatcher
{
    public function __construct(protected OrderService $orders) {}

    /**
     * Hand waiting orders to free drivers, oldest order first. Returns how many were assigned.
     */
    public function dispatchWaiting(Merchant $merchant): int
    {
        $assigned = 0;

        $waiting = Order::query()
            ->whereNull('driver_id')
            ->where('fulfillment', 'delivery')
            ->whereIn('status', ['confirmed', 'preparing', 'ready'])
            ->orderBy('created_at')
            ->get();

        foreach ($waiting as $order) {
            if (! $this->assign($merchant, $order)) {
                break; // no free driver left
            }
            $assigned++;
        }

        return $assigned;
    }

    public function assign(Merchant $merchant, Order $order): bool
    {
        $candidates = Driver::query()
            ->where('is_active', true)
            ->where('role', 'driver')
            ->where('status', 'on_duty')
            ->whereNull('active_order_id')
            ->orderByRaw('last_assigned_at is not null')
            ->orderBy('last_assigned_at')
            ->get();

        foreach ($candidates as $driver) {
            try {
                $this->orders->transition($merchant, $order->id, 'assign', ['driver_id' => $driver->id]);

                return true;
            } catch (ValidationException) {
                // Taken by a concurrent assignment in the meantime, try the next driver.
            }
        }

        return false;
    }
}
