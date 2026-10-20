<?php

namespace App\Services\WhatsApp;

use App\Models\Driver;
use App\Models\Merchant;
use App\Models\WhatsAppMessage;
use App\Support\CurrentTenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Reaches the shop's staff numbers despite Meta's 24-hour rule. A staff member who wrote to the
 * shop number in the last 24 hours gets the normal message with buttons; anyone else gets the
 * approved template version (same buttons, as quick replies). Without either, the message cannot
 * be delivered, and the caller falls back (e.g. the bot accepts the order itself).
 */
class StaffMessenger
{
    /** A little under Meta's 24 hours, so a message is never sent just as the window closes. */
    public const WINDOW_MINS = 23 * 60 + 30;

    public function __construct(
        protected WhatsAppService $whatsapp,
        protected ShopTemplateService $templates,
    ) {}

    /** True when the staff member wrote to the shop number inside the 24-hour window. */
    public function inWindow(Merchant $merchant, Driver $member): bool
    {
        return $this->numberInWindow($merchant, $member->whatsapp_number, $member->phone);
    }

    /** True when one of these numbers wrote to the shop number inside the 24-hour window. */
    public function numberInWindow(Merchant $merchant, ?string ...$numbers): bool
    {
        $numbers = collect($numbers)
            ->map(fn ($n) => preg_replace('/\D+/', '', (string) $n))
            ->filter()->unique()->map(fn ($d) => "+{$d}")->values()->all();

        if ($numbers === []) {
            return false;
        }

        return (new WhatsAppMessage)->newQueryWithoutTenancy()
            ->where('merchant_id', $merchant->id)
            ->where('direction', 'inbound')
            ->whereIn('from_number', $numbers)
            ->where('created_at', '>=', now()->subMinutes(self::WINDOW_MINS))
            ->exists();
    }

    /**
     * Whether new orders can reach this team member: staff need the 24-hour window or the approved
     * new-order template, drivers the window (they open it by sending "start").
     */
    public function reachable(Merchant $merchant, Driver $member): bool
    {
        return $member->role === 'staff'
            ? $this->canReach($merchant, $member, ShopTemplateService::NEW_ORDER)
            : $this->inWindow($merchant, $member);
    }

    public function canReach(Merchant $merchant, Driver $member, string $template): bool
    {
        return $this->inWindow($merchant, $member) || $this->templates->isApproved($merchant, $template);
    }

    /** True when at least one active staff member can be reached with this template. */
    public function anyReachable(Merchant $merchant, string $template): bool
    {
        return $this->staff($merchant)->contains(fn (Driver $m) => $this->canReach($merchant, $m, $template));
    }

    /** @return Collection<int, Driver> */
    public function staff(Merchant $merchant)
    {
        return Driver::query()->withoutGlobalScopes()
            ->where('merchant_id', $merchant->id)->where('role', 'staff')->where('is_active', true)->get();
    }

    /**
     * Sends to one staff member. $buttons are [id, title] pairs; the template gets the same ids
     * as quick-reply payloads (its button titles are fixed when Meta approves it). $template null =
     * there is no template version, so the message only goes to staff inside the window.
     *
     * @param  list<array{0: string, 1: string}>  $buttons
     * @param  list<string>  $templateParams
     * @return bool whether the message was handed to Meta
     */
    public function send(Merchant $merchant, Driver $member, string $body, array $buttons, ?string $template = null, array $templateParams = []): bool
    {
        $to = (string) ($member->whatsapp_number ?: $member->phone);

        if ($this->inWindow($merchant, $member) && $buttons === []) {
            $message = ['type' => 'text', 'text' => $body];
        } elseif ($this->inWindow($merchant, $member)) {
            $message = [
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'button',
                    'body' => ['text' => mb_substr($body, 0, 1024)],
                    'action' => ['buttons' => array_map(fn ($b) => [
                        'type' => 'reply',
                        'reply' => ['id' => $b[0], 'title' => mb_substr($b[1], 0, 20)],
                    ], $buttons)],
                ],
            ];
        } elseif ($template !== null && $this->templates->isApproved($merchant, $template)) {
            $message = ShopTemplateService::message($template, $templateParams, array_column($buttons, 0));
        } else {
            Log::info('Staff member outside the 24-hour window and the template is not approved yet', [
                'merchant_id' => $merchant->id, 'template' => $template,
            ]);

            return false;
        }

        $tenant = app(CurrentTenant::class);
        $previous = $tenant->get();
        $tenant->set($merchant);
        try {
            $this->whatsapp->sendToCustomer($merchant, $to, $message);

            return true;
        } catch (\Throwable $e) {
            Log::info('Staff message not delivered', ['merchant_id' => $merchant->id, 'error' => $e->getMessage()]);

            return false;
        } finally {
            $tenant->set($previous);
        }
    }

    /** Template parameters cannot hold line breaks or long runs of spaces. */
    public static function oneLine(string $text, int $max = 700): string
    {
        $text = preg_replace('/\s*\n+\s*/u', ' · ', trim($text));

        return mb_substr(preg_replace('/\s{2,}/u', ' ', $text), 0, $max);
    }
}
