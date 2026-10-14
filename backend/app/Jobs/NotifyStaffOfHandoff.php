<?php

namespace App\Jobs;

use App\Jobs\Concerns\SendsMerchantWhatsApp;
use App\Models\Customer;
use App\Models\Merchant;
use App\Services\WhatsApp\ShopTemplateService;
use App\Services\WhatsApp\StaffMessenger;
use App\Support\MerchantWhatsAppCredentials;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A customer needs a person, they asked for one, or sent a photo the bot cannot read: tell the
 * staff numbers, with a button to give the chat back to the bot.
 */
class NotifyStaffOfHandoff implements ShouldQueue
{
    use Queueable, SendsMerchantWhatsApp;

    public int $tries = 1;

    /** @param  string  $reason  person | photo */
    public function __construct(public string $customerId, public string $reason = 'person') {}

    public function handle(StaffMessenger $messenger): void
    {
        $customer = Customer::query()->withoutGlobalScopes()->find($this->customerId);
        $merchant = $customer ? Merchant::find($customer->merchant_id) : null;
        if (! $customer || ! $merchant || ! MerchantWhatsAppCredentials::resolve($merchant)) {
            return;
        }

        $locale = $merchant->locale ?? 'en';
        $key = $this->reason === 'photo' ? 'photo' : 'handoff';
        $body = __("whatsapp.staff.{$key}", ['phone' => $customer->phone], $locale);
        $buttons = [["stf:resume:{$customer->id}", __('whatsapp.staff.button_resume', [], $locale)]];
        // The template is in English, so its reason is too.
        $why = __("whatsapp.staff.alert_{$key}", ['phone' => $customer->phone], 'en');

        foreach ($messenger->staff($merchant) as $member) {
            $messenger->send($merchant, $member, $body, $buttons, ShopTemplateService::STAFF_ALERT, [$merchant->name, $why]);
        }
    }
}
