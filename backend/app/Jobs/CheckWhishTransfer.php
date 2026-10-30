<?php

namespace App\Jobs;

use App\Models\Merchant;
use App\Models\Order;
use App\Services\OrderConfirmation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * End of the payment window for a Whish-to-Whish order: if the customer never tapped "I paid",
 * the order is cancelled. Orders the customer has reported are left to the shop to decide.
 */
class CheckWhishTransfer implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $orderId) {}

    public function handle(OrderConfirmation $confirmation): void
    {
        $order = (new Order)->newQueryWithoutTenancy()->find($this->orderId);
        $merchant = $order ? Merchant::find($order->merchant_id) : null;

        if ($order && $merchant && $order->status === 'pending_payment') {
            $confirmation->expireTransfer($merchant, $order);
        }
    }
}
