<?php

namespace App\Services;

use App\Models\Merchant;
use Illuminate\Support\Carbon;

/**
 * Stops a paid shop that has not paid its next period. The shop keeps working for GRACE_DAYS after
 * its end date (the owner is reminded during that time), then it is suspended the same way an
 * unpaid trial is. Marking it paid turns it back on.
 */
class SubscriptionStopService
{
    public const GRACE_DAYS = 3;

    public function stopDue(?Carbon $now = null): int
    {
        $now ??= now();
        $stopped = 0;

        $due = Merchant::query()
            ->where('status', 'active')
            ->whereNotNull('subscription_ends_at')
            ->where('subscription_ends_at', '<', $now->copy()->subDays(self::GRACE_DAYS))
            ->get()
            ->filter(fn (Merchant $m) => (($m->settings ?? [])['subscription_status'] ?? null) === 'paid');

        foreach ($due as $merchant) {
            $settings = $merchant->settings ?? [];
            $settings['subscription_status'] = 'pending';
            $merchant->forceFill(['status' => 'suspended', 'settings' => $settings])->save();

            activity('merchant')->performedOn($merchant)->log("{$merchant->name}: payment overdue — stopped until paid");
            $stopped++;
        }

        return $stopped;
    }
}
