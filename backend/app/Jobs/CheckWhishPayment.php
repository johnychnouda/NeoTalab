<?php

namespace App\Jobs;

use App\Models\Merchant;
use App\Models\Order;
use App\Services\OrderConfirmation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Safety net next to Whish's callback: checks a pending payment with Whish. The final check
 * (end of the shop's payment window) cancels the order if it is still unpaid.
 */
class CheckWhishPayment implements ShouldQueue
{
    use Queueable;

    // The final check refuses to cancel while Whish cannot answer, so it keeps retrying for ~1h.
    public int $tries = 6;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 900, 1800];

    public function __construct(public string $orderId, public bool $final = false) {}

    public function handle(OrderConfirmation $confirmation): void
    {
        $order = (new Order)->newQueryWithoutTenancy()->find($this->orderId);
        $merchant = $order ? Merchant::find($order->merchant_id) : null;

        if ($order && $merchant) {
            $confirmation->settleWhish($merchant, $order, $this->final);
        }
    }
}
