<?php

namespace App\Console\Commands;

use App\Jobs\Concerns\SendsMerchantWhatsApp;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\OrderConfirmation;
use App\Services\OrderTiming;
use App\Support\CurrentTenant;
use App\Support\ShopSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Shops that deliver in days (online shops). Hourly:
 * - an order still open after its promised day moves to the next working day and the
 *   customer gets an apology with the new date.
 */
class OrdersDue extends Command
{
    use SendsMerchantWhatsApp;

    protected $signature = 'orders:due';

    protected $description = 'Reschedule overdue orders of shops that deliver in days.';

    public const OPEN = ['confirmed', 'preparing', 'ready', 'assigned', 'picked_up'];

    public function handle(OrderConfirmation $confirmation, OrderTiming $timing): int
    {
        $tenant = app(CurrentTenant::class);
        foreach (Merchant::query()->where('status', 'active')->get() as $merchant) {
            if (ShopSettings::for($merchant)->timingUnit() !== 'days') {
                continue;
            }
            $tenant->set($merchant);
            try {
                $local = now()->setTimezone($merchant->timezone ?: config('app.timezone'));

                foreach (Order::query()->whereIn('status', self::OPEN)->whereNotNull('promised_at')->where('promised_at', '<', now())->get() as $order) {
                    $confirmation->delay($merchant, $order);
                }

            } catch (\Throwable $e) {
                Log::error('orders:due failed for merchant', ['merchant_id' => $merchant->id, 'error' => $e->getMessage()]);
            } finally {
                $tenant->set(null);
            }
        }

        return self::SUCCESS;
    }
}
