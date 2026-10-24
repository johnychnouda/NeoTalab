<?php

namespace App\Jobs;

use App\Jobs\Concerns\SendsMerchantWhatsApp;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Product;
use App\Services\WhatsApp\ShopTemplateService;
use App\Services\WhatsApp\StaffMessenger;
use App\Support\MerchantWhatsAppCredentials;
use App\Support\OrderMessage;
use App\Support\ShopSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A customer asked for an item the team must check first (prescription, age): the staff get
 * Approve / Refuse buttons. Staff outside the 24-hour window get the person-needed template, and
 * then the bot steps back in that chat, as that template says.
 */
class NotifyStaffOfCheck implements ShouldQueue
{
    use Queueable, SendsMerchantWhatsApp;

    public int $tries = 1;

    public function __construct(public string $customerId, public string $productId) {}

    public function handle(StaffMessenger $messenger): void
    {
        $customer = Customer::query()->withoutGlobalScopes()->find($this->customerId);
        $merchant = $customer ? Merchant::find($customer->merchant_id) : null;
        $product = $merchant ? Product::query()->withoutGlobalScopes()->where('merchant_id', $merchant->id)->find($this->productId) : null;
        if (! $customer || ! $product || ! MerchantWhatsAppCredentials::resolve($merchant)) {
            return;
        }

        $locale = $merchant->locale ?? 'en';
        $body = __('whatsapp.check.staff', ['phone' => OrderMessage::phone($customer->phone), 'item' => $product->name], $locale);
        $buttons = [
            ["stf:approve:{$customer->id}:{$product->id}", __('whatsapp.check.button_approve', [], $locale)],
            ["stf:refuse:{$customer->id}:{$product->id}", __('whatsapp.check.button_refuse', [], $locale)],
        ];
        $why = __('whatsapp.check.alert', ['phone' => $customer->phone, 'item' => $product->name], 'en');

        $paused = false;
        foreach ($messenger->staff($merchant) as $member) {
            $inWindow = $messenger->inWindow($merchant, $member);
            if ($messenger->send($merchant, $member, $body, $buttons, ShopTemplateService::STAFF_ALERT, [$merchant->name, $why]) && ! $inWindow) {
                $paused = true;
            }
        }
        if ($paused) {
            $customer->forceFill(['bot_paused_until' => now()->addMinutes(ShopSettings::for($merchant)->botPauseMins())])->save();
        }
    }
}
