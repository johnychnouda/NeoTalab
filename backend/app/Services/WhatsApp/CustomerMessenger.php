<?php

namespace App\Services\WhatsApp;

use App\Models\Merchant;
use App\Services\SystemAlertService;
use Illuminate\Support\Facades\Log;

/**
 * Reaches a customer despite Meta's 24-hour rule. A customer who wrote to the shop number in the
 * last 24 hours gets the normal message. Anyone else gets the approved template for that event (in
 * their language, else English). Plain text outside the window is accepted by Meta and then dropped
 * without a word, so it is never sent: with no approved template the owner is told in Inbox → System
 * that the customer was not reached, and the shop can write to them by hand.
 */
class CustomerMessenger
{
    public function __construct(
        protected WhatsAppService $whatsapp,
        protected ShopTemplateService $templates,
        protected StaffMessenger $window,
        protected SystemAlertService $alerts,
    ) {}

    /**
     * @param  array<string, mixed>  $message  the normal message (text / interactive), used inside the window
     * @param  string|null  $event  a CustomerTemplates event; null for messages that are not worth a template (they are skipped outside the window)
     * @param  list<string>  $params  the template's variables, in order
     * @return bool whether the customer was sent something
     */
    public function send(Merchant $merchant, string $phone, array $message, ?string $event = null, array $params = [], ?string $locale = null): bool
    {
        if (trim($phone) === '') {
            return false;
        }

        if ($this->window->numberInWindow($merchant, $phone)) {
            $this->whatsapp->sendToCustomer($merchant, $phone, $message);

            return true;
        }

        if ($event !== null) {
            foreach (CustomerTemplates::names($event, $locale) as $name) {
                if ($this->templates->isApproved($merchant, $name)) {
                    $language = CustomerTemplates::definitions()[$name]['language'];
                    $this->whatsapp->sendToCustomer($merchant, $phone, ShopTemplateService::message($name, $params, [], $language));

                    return true;
                }
            }

            $this->alerts->record('warning', "{$merchant->name}: a customer ({$phone}) was not told about \"{$event}\" — they have not written in 24 hours and the shop has no approved template for it. Please message them yourselves.", [
                'merchant_id' => $merchant->id,
                'event' => $event,
            ]);
        }

        Log::info('Customer message skipped: outside the 24-hour window', ['merchant_id' => $merchant->id, 'event' => $event]);

        return false;
    }
}
