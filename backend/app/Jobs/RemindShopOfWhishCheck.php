<?php

namespace App\Jobs;

use App\Models\Merchant;
use App\Models\Order;
use App\Services\OrderConfirmation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Half an hour after the customer said "I paid", the shop is asked again if it has still not
 * checked the payment: the first alert may have been missed.
 */
class RemindShopOfWhishCheck implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $orderId) {}

    public function handle(OrderConfirmation $confirmation): void
    {
        $order = (new Order)->newQueryWithoutTenancy()->find($this->orderId);
        $merchant = $order ? Merchant::find($order->merchant_id) : null;

        if ($order && $merchant && $order->payment_status === 'awaiting_check') {
            $confirmation->alertShopToCheck($merchant, $order, $order->status === 'cancelled');
        }
    }
}
