<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a shop's NeoTalab subscription payment. The platform owner checks the NeoTalab Whish
 * account and passes the Whish reference; the amount comes from the shop's own fee, never from the
 * request, and the reference can only be used once.
 */
class SubscriptionPaymentService
{
    public function record(Merchant $merchant, User $confirmedBy, string $reference): SubscriptionPayment
    {
        $reference = strtoupper(trim($reference));
        if (! preg_match('/^[A-Z0-9-]{4,40}$/', $reference)) {
            throw ValidationException::withMessages(['whish_reference' => [__('Enter the Whish reference: letters, digits and dashes only.')]]);
        }

        return DB::transaction(function () use ($merchant, $confirmedBy, $reference) {
            $locked = Merchant::query()->whereKey($merchant->id)->lockForUpdate()->firstOrFail();
            $settings = $locked->settings ?? [];

            // A cycle chosen for the next period applies to this payment.
            if (! empty($settings['next_billing_cycle'])) {
                $locked->billing_cycle = $settings['next_billing_cycle'];
                unset($settings['next_billing_cycle']);
            }
            $cycle = $locked->billing_cycle === 'yearly' ? 'yearly' : 'monthly';

            $amount = (float) ($cycle === 'yearly' ? ($settings['yearly_fee'] ?? 0) : ($settings['monthly_fee'] ?? 0));
            if ($amount <= 0) {
                throw ValidationException::withMessages(['merchant' => [__('Set the shop\'s subscription fee before recording a payment.')]]);
            }

            // Paying early adds the new period to the end of the current one, so no days are lost.
            $now = now();
            $from = $locked->subscription_ends_at && $locked->subscription_ends_at->isFuture() ? $locked->subscription_ends_at : $now;
            $end = $cycle === 'yearly' ? $from->copy()->addYear() : $from->copy()->addMonth();

            try {
                $payment = SubscriptionPayment::query()->create([
                    'merchant_id' => $locked->id,
                    'whish_reference' => $reference,
                    'amount' => $amount,
                    'currency' => strtoupper((string) $locked->currency ?: 'USD'),
                    'cycle' => $cycle,
                    'period_start' => $from,
                    'period_end' => $end,
                    'confirmed_by' => $confirmedBy->id,
                    'confirmed_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['whish_reference' => [__('This Whish reference was already recorded.')]]);
            }

            $settings['subscription_status'] = 'paid';
            $settings['last_payment_at'] = $now->toIso8601String();
            $locked->forceFill([
                'settings' => $settings,
                'billing_cycle' => $cycle,
                'subscription_ends_at' => $end,
            ])->save();

            return $payment;
        });
    }
}
