<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Quote;
use App\Services\WhatsApp\StaffMessenger;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Money;
use App\Support\OrderMessage;
use App\Support\ShopSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repairs and custom work, on WhatsApp only: the customer describes the job → the staff (or the
 * shop phone) type "quote <number> <price> <what>" → the customer taps Accept → it becomes an
 * order the staff move to Ready as usual. The price always comes from the shop, never the AI.
 */
class QuoteService
{
    public function __construct(
        protected WhatsAppService $whatsapp,
        protected StaffMessenger $staff,
        protected OrderConfirmation $confirmation,
    ) {}

    /** The customer's request, saved and sent to the staff; returns the customer's reply. */
    public function request(Merchant $merchant, Conversation $conversation, string $description): string
    {
        $customer = $conversation->customer;
        $locale = $customer->locale ?? 'en';
        $open = Quote::query()->where('customer_id', $customer->id)->where('status', 'requested')->latest()->first();
        $quote = $open ?? DB::transaction(fn () => Quote::create([
            'merchant_id' => $merchant->id,
            'number' => (int) Quote::query()->lockForUpdate()->max('number') + 1,
            'customer_id' => $customer->id,
            'description' => mb_substr(trim($description), 0, 1000),
            'status' => 'requested',
        ]));
        if ($open) {
            $open->update(['description' => mb_substr($open->description."\n".trim($description), 0, 1000)]);
        }

        $body = __('whatsapp.quote.staff_request', [
            'number' => $quote->number, 'phone' => OrderMessage::phone($customer->phone), 'text' => $quote->description,
        ], $merchant->locale ?? 'en');
        foreach ($this->staff->staff($merchant) as $member) {
            $this->staff->send($merchant, $member, $body, []);
        }

        return __('whatsapp.quote.requested', ['number' => $quote->number, 'shop' => $merchant->name], $locale);
    }

    /** The shop's price: the customer gets it with Accept / No thanks. Null when there is no such request. */
    public function send(Merchant $merchant, ?Quote $quote, float $price, string $title): ?Quote
    {
        // A quote is at least one dollar: a cent is a typo, never a job.
        if (! $quote || ! in_array($quote->status, ['requested', 'sent'], true) || $price < 1) {
            return null;
        }
        $quote->update(['price' => round($price, 2), 'title' => mb_substr(trim($title) ?: $quote->description, 0, 150), 'status' => 'sent', 'sent_at' => now()]);
        $customer = $quote->customer;
        $locale = $customer->locale ?? 'en';
        $text = __('whatsapp.quote.offer', [
            'number' => $quote->number, 'shop' => $merchant->name, 'title' => $quote->title,
            'price' => Money::format((float) $quote->price, $merchant->currency), 'days' => Quote::VALID_DAYS,
        ], $locale);
        try {
            $this->whatsapp->sendToCustomer($merchant, (string) $customer->phone, ['type' => 'interactive', 'interactive' => [
                'type' => 'button', 'body' => ['text' => mb_substr($text, 0, 1024)],
                'action' => ['buttons' => [
                    ['type' => 'reply', 'reply' => ['id' => "qt:yes:{$quote->id}", 'title' => mb_substr(__('whatsapp.quote.btn_accept', [], $locale), 0, 20)]],
                    ['type' => 'reply', 'reply' => ['id' => "qt:no:{$quote->id}", 'title' => mb_substr(__('whatsapp.quote.btn_decline', [], $locale), 0, 20)]],
                ]],
            ]]);
        } catch (\Throwable $e) {
            Log::warning('Quote not delivered to the customer', ['quote_id' => $quote->id, 'error' => $e->getMessage()]);
        }

        return $quote;
    }

    /**
     * The customer's tap. Accept turns the quote into an order (once); the reply and the order are
     * returned. An old or already answered quote changes nothing.
     *
     * @return array{0: string, 1: ?Order}
     */
    public function answer(Merchant $merchant, Customer $customer, string $quoteId, bool $accept): array
    {
        $locale = $customer->locale ?? 'en';
        $quote = Quote::query()->where('customer_id', $customer->id)->whereKey($quoteId)->first();
        if (! $quote || $quote->status !== 'sent' || $quote->sent_at?->lt(now()->subDays(Quote::VALID_DAYS))) {
            return [__('whatsapp.quote.not_open', [], $locale), null];
        }

        if (! $accept) {
            $quote->update(['status' => 'declined']);
            $this->tellStaff($merchant, __('whatsapp.quote.staff_declined', ['number' => $quote->number, 'phone' => OrderMessage::phone($customer->phone)], $merchant->locale ?? 'en'));

            return [__('whatsapp.quote.declined', ['shop' => $merchant->name], $locale), null];
        }

        $order = DB::transaction(function () use ($merchant, $customer, $quote) {
            // Two taps on Accept make one order.
            if (Quote::query()->whereKey($quote->id)->where('status', 'sent')->update(['status' => 'accepted']) === 0) {
                return null;
            }
            $order = Order::create([
                'merchant_id' => $merchant->id,
                'number' => (int) Order::query()->lockForUpdate()->max('number') + 1,
                'customer_id' => $customer->id,
                'subtotal' => $quote->price,
                'delivery_fee' => 0,
                'total' => $quote->price,
                'payment_method' => 'cash',
                'payment_status' => 'cash',
                'payment_currency' => $merchant->currency,
                'fulfillment' => 'pickup',
                'status' => 'confirmed',
                'accepted_at' => now(),
                'notes' => __('whatsapp.quote.order_note', ['number' => $quote->number], $merchant->locale ?? 'en'),
                'eta_mins' => ShopSettings::for($merchant)->prepTimeMins(),
            ]);
            OrderItem::create([
                'merchant_id' => $merchant->id, 'order_id' => $order->id, 'product_name' => $quote->title,
                'quantity' => 1, 'unit_price' => $quote->price, 'item_total' => $quote->price, 'modifiers' => [],
            ]);
            $quote->update(['order_id' => $order->id]);
            Customer::query()->whereKey($customer->id)->incrementEach(['total_orders' => 1, 'total_spent' => (float) $quote->price], ['last_order_at' => now()]);

            return $order;
        });
        if (! $order) {
            return [__('whatsapp.quote.not_open', [], $locale), null];
        }

        $this->confirmation->afterConfirmed($merchant, $order);
        $order = $order->fresh();

        return [__('whatsapp.quote.accepted', [
            'number' => $order->number, 'title' => $quote->title, 'price' => Money::format((float) $order->total, $merchant->currency),
            'when' => app(OrderTiming::class)->phrase($merchant, $order, $locale),
        ], $locale), $order];
    }

    /** "quote 12 45 Screen replacement" (staff) → the request number, the price and what it is. */
    public static function parse(string $text, bool $withNumber): ?array
    {
        $digits = strtr(trim($text), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $number = $withNumber ? '#?\s*(\d+)\s+' : '()';
        if (! preg_match('/^(?:quote|price|سعر|devis)\s*'.$number.'\$?\s*(\d+(?:[.,]\d{1,2})?)\s*\$?\s*(.*)$/iu', $digits, $m)) {
            return null;
        }

        return ['number' => $m[1] === '' ? null : (int) $m[1], 'price' => (float) str_replace(',', '.', $m[2]), 'title' => trim($m[3], ' -—:')];
    }

    private function tellStaff(Merchant $merchant, string $body): void
    {
        foreach ($this->staff->staff($merchant) as $member) {
            $this->staff->send($merchant, $member, $body, []);
        }
    }
}
