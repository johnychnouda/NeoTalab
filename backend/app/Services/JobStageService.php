<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\Order;
use App\Services\WhatsApp\CustomerMessenger;
use App\Services\WhatsApp\CustomerTemplates;
use App\Services\WhatsApp\StaffMessenger;
use App\Support\OrderMessage;
use App\Support\ShopSettings;
use Illuminate\Support\Facades\Log;

/**
 * The steps of a repair or custom job ("Car received" → "Work in progress" → "Ready for pickup") as the shop
 * wrote them in its settings. The team (or a lone owner) moves an order from one to the next on WhatsApp, and
 * the customer is told at each step, in their language: the message inside WhatsApp's 24 hours, the approved
 * template after.
 */
class JobStageService
{
    /** @return list<string> */
    public function stages(Merchant $merchant): array
    {
        return ShopSettings::for($merchant)->jobStages();
    }

    public function enabled(Merchant $merchant): bool
    {
        return $this->stages($merchant) !== [];
    }

    /** The name of the order's current stage, or null when the job has not started. */
    public function current(Merchant $merchant, Order $order): ?string
    {
        return $order->job_stage === null ? null : ($this->stages($merchant)[$order->job_stage] ?? null);
    }

    /**
     * Moves the order to a stage (the next one when $to is null). Nothing changes, and false comes back, when the
     * order is closed, the stage does not exist, or it is already there.
     */
    public function move(Merchant $merchant, Order $order, ?int $to = null): bool
    {
        $stages = $this->stages($merchant);
        $to ??= $order->job_stage === null ? 0 : $order->job_stage + 1;

        if (! isset($stages[$to]) || $order->job_stage === $to || in_array($order->status, ['placed', 'pending_payment', 'delivered', 'cancelled', 'rejected'], true)) {
            return false;
        }

        $order->forceFill(['job_stage' => $to])->save();
        $this->tellCustomer($merchant, $order, $stages[$to]);

        return true;
    }

    /**
     * The card the team gets for an accepted order: where the job is, and buttons to move it on.
     *
     * @return array{text: string, buttons: list<array{0: string, 1: string}>, template_params: list<string>}
     */
    public function card(Merchant $merchant, Order $order, string $locale): array
    {
        $stages = $this->stages($merchant);
        $current = $this->current($merchant, $order);
        $params = OrderMessage::params($order, $merchant, $locale);
        $lines = explode("\n", __($order->fulfillment === 'pickup' ? 'whatsapp.staff.new_order_pickup' : 'whatsapp.staff.new_order_delivery', $params, $locale));
        $lines[0] = __('whatsapp.staff.job_title', ['number' => $order->number], $locale);
        $lines[] = __('whatsapp.staff.job_stage_line', ['stage' => $current ?? __('whatsapp.staff.job_not_started', [], $locale)], $locale);

        // Always three buttons (the approved template has three): at the last stage "Next stage" answers that there is none.
        $buttons = [
            ["stf:job_next:{$order->id}", __('whatsapp.staff.job_next', [], $locale)],
            ["stf:job_pick:{$order->id}", __('whatsapp.staff.job_stages', [], $locale)],
            [($order->fulfillment === 'pickup' ? 'stf:ready:' : 'stf:picked_up:').$order->id, __('whatsapp.staff.job_ready', [], $locale)],
        ];

        return [
            'text' => implode("\n", $lines),
            'buttons' => $buttons,
            'template_params' => [(string) $order->number, $merchant->name, StaffMessenger::oneLine(implode("\n", array_slice($lines, 1, -1))), $current ?? __('whatsapp.staff.job_not_started', [], $locale)],
        ];
    }

    protected function tellCustomer(Merchant $merchant, Order $order, string $stage): void
    {
        $order->loadMissing('customer');
        $locale = $order->customer?->locale ?? $merchant->locale ?? 'en';

        try {
            app(CustomerMessenger::class)->send(
                $merchant, (string) $order->customer?->phone,
                ['type' => 'text', 'text' => __('whatsapp.status.job_stage', ['number' => $order->number, 'shop' => $merchant->name, 'stage' => $stage], $locale)],
                CustomerTemplates::JOB_STAGE, [(string) $order->number, $merchant->name, $stage], $locale,
            );
        } catch (\Throwable $e) {
            Log::warning('Job stage not told to the customer', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
