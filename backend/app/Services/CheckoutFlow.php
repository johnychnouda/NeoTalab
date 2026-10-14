<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Merchant;
use App\Models\Modifier;
use App\Models\Order;
use App\Support\Money;
use App\Support\ShopSettings;
use Illuminate\Support\Str;

/**
 * Toters-style checkout on WhatsApp, driven by buttons instead of the AI: delivery or pickup →
 * saved address or area + location → cash or Whish → currency → receipt → confirm.
 * State lives in conversations.state['checkout']; any free-text message outside the address
 * step leaves the flow and goes back to the assistant.
 *
 * Every method returns the WhatsApp message to send (text or interactive), or null when the
 * message is not part of the flow.
 */
class CheckoutFlow
{
    public const MAX_SAVED_ADDRESSES = 5;

    public function __construct(
        protected ConversationService $conversations,
        protected CheckoutService $checkout,
        protected WhishPayService $whish,
    ) {}

    public function active(Conversation $conversation): bool
    {
        return isset(($conversation->state ?? [])['checkout']);
    }

    /**
     * @return array<string, mixed>
     */
    public function start(Merchant $merchant, Conversation $conversation, string $locale): array
    {
        $conversation->load('cart.items');
        if (! $conversation->cart || $conversation->cart->items->isEmpty()) {
            return $this->menuMessage($merchant, $this->t('empty', $locale), $locale);
        }

        if (! $this->checkout->isOpen($merchant) || (($merchant->settings ?? [])['mode'] ?? 'auto') === 'closed') {
            return $this->text(__('whatsapp.checkout.shop_closed', ['shop' => $merchant->name], $locale));
        }

        $this->save($conversation, []);

        return $this->next($merchant, $conversation, $locale);
    }

    /**
     * @param  array<string, mixed>|null  $location
     * @return array<string, mixed>|null
     */
    public function handle(Merchant $merchant, Conversation $conversation, string $locale, ?string $buttonId, ?string $text, ?array $location): ?array
    {
        $isFlowButton = $buttonId !== null && str_starts_with($buttonId, 'co:');
        if (! $isFlowButton && ! $this->active($conversation)) {
            return null;
        }
        if ($isFlowButton && ! $this->active($conversation)) {
            // A button from an old message (the order was already placed or left).
            return $this->menuMessage($merchant, $this->t('expired', $locale), $locale);
        }

        $state = ($conversation->state ?? [])['checkout'];
        $step = $state['step'] ?? null;

        if ($isFlowButton) {
            [$_, $kind, $value] = array_pad(explode(':', $buttonId, 3), 3, '');

            switch ($kind) {
                case 'ful':
                    $state['fulfillment'] = $value === 'pickup' ? 'pickup' : 'delivery';
                    break;
                case 'addr':
                    if ($value === 'new') {
                        $state['new_address'] = true;
                    } elseif ($saved = collect($conversation->customer->addresses ?? [])->firstWhere('id', $value)) {
                        $state['zone'] = $saved['zone'] ?? null;
                        $state['address'] = $saved['address'] ?? '';
                        $state['location'] = $saved['location'] ?? null;
                        $state['address_done'] = true;
                    }
                    break;
                case 'zone':
                    if ($zone = DeliveryZone::query()->find($value)) {
                        $state['zone'] = $zone->name;
                    }
                    break;
                case 'skip':
                    $state['address_done'] = true;
                    break;
                case 'pay':
                    $state['payment'] = $value === 'whish' ? 'whish' : 'cash';
                    break;
                case 'cur':
                    $state['currency'] = in_array($value, ['LBP', 'MIXED'], true) ? $value : $merchant->currency;
                    unset($state['cash_main']);
                    break;
                case 'ok':
                    return $this->confirm($merchant, $conversation, $state, $locale);
                case 'edit':
                    $this->exit($conversation);

                    return $this->menuMessage($merchant, $this->t('edit', $locale), $locale);
                case 'cancel':
                    $this->exit($conversation);
                    $conversation->cart?->items()->delete();
                    $conversation->cart?->update(['subtotal' => 0]);

                    return $this->text($this->t('cancelled', $locale));
            }

            if (in_array($kind, ['zone', 'addr'], true) && ($refusal = $this->belowMinimum($merchant, $conversation, $state, $locale))) {
                return $refusal;
            }

            $this->save($conversation, $state);

            return $this->next($merchant, $conversation, $locale);
        }

        // Free input: a location pin or typed address while we wait for one, or address details.
        if ($step === 'location' && ($location || ($text !== null && mb_strlen(trim($text)) >= 5))) {
            if ($location) {
                $state['location'] = $location;
                $state['address'] = trim((string) ($location['address'] ?? ''));
                $state['step'] = 'details';
                $this->save($conversation, $state);

                return $this->buttons($this->t('details', $locale), [['co:skip:', $this->t('btn_skip', $locale)]]);
            }
            $state['address'] = trim((string) $text);
            $state['address_done'] = true;
        } elseif ($step === 'details' && $text !== null && trim($text) !== '') {
            $state['address'] = trim(trim($state['address'] ?? '').' — '.trim($text), ' —');
            $state['address_done'] = true;
        } elseif ($step === 'split' && $text !== null) {
            // Split cash: the dollars the customer pays, the rest in lira at the shop's rate.
            $amount = $this->amount($text);
            $total = $this->total($conversation, $state);
            if ($amount === null || $amount <= 0 || $amount >= $total) {
                return $this->text($this->t('split_invalid', $locale, ['max' => $this->money($merchant, $total)]));
            }
            $state['cash_main'] = round($amount, 2);
        } elseif ($step === 'location' && $text !== null) {
            return $this->location($this->t('address_short', $locale));
        } else {
            // Anything else ("add a pepsi", a question): back to the assistant.
            $this->exit($conversation);

            return null;
        }

        $this->save($conversation, $state);

        return $this->next($merchant, $conversation, $locale);
    }

    /**
     * Works out the next missing piece and asks for it.
     *
     * @return array<string, mixed>
     */
    protected function next(Merchant $merchant, Conversation $conversation, string $locale): array
    {
        $state = ($conversation->state ?? [])['checkout'] ?? [];
        $settings = ShopSettings::for($merchant);
        $mode = $settings->fulfillment();

        if (empty($state['fulfillment'])) {
            if ($mode === 'both') {
                return $this->ask($conversation, $state, 'fulfillment', $this->buttons($this->t('how', $locale), [
                    ['co:ful:delivery', $this->t('btn_delivery', $locale)],
                    ['co:ful:pickup', $this->t('btn_pickup', $locale)],
                ]));
            }
            $state['fulfillment'] = $mode === 'pickup' ? 'pickup' : 'delivery';
        }

        if ($state['fulfillment'] === 'delivery' && empty($state['address_done'])) {
            $saved = $conversation->customer->addresses ?? [];
            if ($saved !== [] && empty($state['new_address'])) {
                $rows = array_map(fn ($a) => [
                    'id' => "co:addr:{$a['id']}",
                    'title' => Str::limit($a['zone'] ?: $this->t('saved', $locale), 21, '…'),
                    'description' => Str::limit($a['address'] ?: $this->t('pin', $locale), 69, '…'),
                ], array_slice($saved, 0, self::MAX_SAVED_ADDRESSES));
                $rows[] = ['id' => 'co:addr:new', 'title' => $this->t('new_address', $locale), 'description' => ''];

                return $this->ask($conversation, $state, 'address', $this->list($this->t('where', $locale), $this->t('btn_choose', $locale), $rows));
            }

            $zones = DeliveryZone::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
            if (empty($state['zone']) && $zones->count() > 1) {
                $rows = $zones->take(10)->map(fn (DeliveryZone $z) => [
                    'id' => "co:zone:{$z->id}",
                    'title' => Str::limit($z->name, 21, '…'),
                    'description' => $this->t('zone_fee', $locale, [
                        'fee' => $this->money($merchant, (float) $z->delivery_fee),
                        'minimum' => (float) $z->minimum_order > 0 ? $this->t('zone_min', $locale, ['minimum' => $this->money($merchant, (float) $z->minimum_order)]) : '',
                    ]),
                ])->values()->all();

                return $this->ask($conversation, $state, 'zone', $this->list($this->t('which_area', $locale), $this->t('btn_choose', $locale), $rows));
            }
            if (empty($state['zone']) && $zones->count() === 1) {
                $state['zone'] = $zones->first()->name;
            }

            return $this->ask($conversation, $state, 'location', $this->location($this->t('location', $locale)));
        }

        if (empty($state['payment'])) {
            if ($this->whish->enabled($merchant)) {
                return $this->ask($conversation, $state, 'payment', $this->buttons($this->t('pay', $locale), [
                    ['co:pay:cash', $this->t($state['fulfillment'] === 'pickup' ? 'btn_cash_shop' : 'btn_cash', $locale)],
                    ['co:pay:whish', $this->t('btn_whish', $locale)],
                ]));
            }
            $state['payment'] = 'cash';
        }

        if (empty($state['currency'])) {
            $total = $this->total($conversation, $state);
            if ($state['payment'] === 'cash' && ($lbp = $settings->toLbp($total)) !== null && strtoupper((string) $merchant->currency) !== 'LBP') {
                // The total in both currencies: all in dollars, all in lira, or part of each.
                return $this->ask($conversation, $state, 'currency', $this->buttons($this->t('currency', $locale, [
                    'usd' => $this->money($merchant, $total), 'lbp' => Money::lbp($lbp),
                ]), [
                    ['co:cur:'.$merchant->currency, Str::limit("💵 {$this->money($merchant, $total)}", 20, '')],
                    ['co:cur:LBP', Str::limit('🇱🇧 '.Money::lbp($lbp), 20, '')],
                    ['co:cur:MIXED', $this->t('btn_split', $locale)],
                ]));
            }
            $state['currency'] = $merchant->currency;
        }

        if ($state['currency'] === 'MIXED' && ! isset($state['cash_main'])) {
            return $this->ask($conversation, $state, 'split', $this->text($this->t('split_ask', $locale, [
                'total' => $this->money($merchant, $this->total($conversation, $state)),
            ])));
        }

        return $this->ask($conversation, $state, 'confirm', $this->buttons($this->receipt($merchant, $conversation, $state, $locale), [
            ['co:ok:', $this->t('btn_confirm', $locale)],
            ['co:edit:', $this->t('btn_edit', $locale)],
            ['co:cancel:', $this->t('btn_cancel', $locale)],
        ]));
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function confirm(Merchant $merchant, Conversation $conversation, array $state, string $locale): array
    {
        [$reply, $order] = $this->conversations->placeOrderFromDetails($conversation, [
            'fulfillment' => $state['fulfillment'] ?? null,
            'delivery_zone' => $state['zone'] ?? null,
            'delivery_address' => $state['address'] ?? '',
            'location' => $state['location'] ?? null,
            'payment_method' => $state['payment'] ?? 'cash',
            'payment_currency' => $state['currency'] ?? $merchant->currency,
            'cash_main' => $state['cash_main'] ?? null,
        ]);

        $this->exit($conversation, $order ? 'completed' : null);

        if ($order && $order->fulfillment === 'delivery') {
            $this->rememberAddress($conversation->customer, $state);
        }

        return $order && $order->status !== 'pending_payment'
            ? ChatMenu::withStatusButton($order, $reply, $locale)
            : ($order ? $this->text($reply) : $this->menuMessage($merchant, $reply, $locale));
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function receipt(Merchant $merchant, Conversation $conversation, array $state, string $locale): string
    {
        $items = CartItem::query()->where('cart_id', $conversation->cart()->value('id'))->with(['product', 'variant'])->get();
        $modifiers = Modifier::query()->whereIn('id', $items->pluck('modifier_ids')->flatten()->filter()->unique()->all())->pluck('name', 'id');

        $lines = $items->map(function (CartItem $i) use ($merchant, $modifiers) {
            $extras = collect($i->modifier_ids ?? [])->map(fn ($id) => $modifiers[$id] ?? null)->filter();
            $name = ($i->product?->name ?? '?').($i->variant ? " ({$i->variant->name})" : '');
            $line = "{$i->quantity}× {$name} — {$this->money($merchant, (float) $i->line_total)}";
            if ($extras->isNotEmpty()) {
                $line .= "\n   + ".$extras->join(', ');
            }
            if (filled($i->notes)) {
                $line .= "\n   ✏️ {$i->notes}";
            }

            return $line;
        })->join("\n");

        $subtotal = (float) $items->sum('line_total');
        $zone = ($state['fulfillment'] ?? '') === 'delivery' && ! empty($state['zone'])
            ? DeliveryZone::query()->where('name', $state['zone'])->first() : null;
        $fee = $zone ? (float) $zone->delivery_fee : 0.0;
        $total = $subtotal + $fee;

        $out = '🧾 *'.$this->t('your_order', $locale)."*\n{$lines}\n\n";
        $out .= $this->t('subtotal', $locale).": {$this->money($merchant, $subtotal)}\n";
        if ($zone) {
            $out .= $this->t('delivery_fee', $locale, ['zone' => $zone->name]).": {$this->money($merchant, $fee)}\n";
        }
        $out .= '*'.$this->t('total', $locale).": {$this->money($merchant, $total)}*";
        $settings = ShopSettings::for($merchant);
        $cash = ($state['payment'] ?? '') !== 'whish';
        if ($cash && ($lbp = $settings->toLbp($total)) !== null) {
            $out .= ' ('.Money::lbp($lbp).')';
        }
        $out .= "\n\n";
        $out .= ($state['fulfillment'] ?? '') === 'pickup'
            ? '🏪 '.$this->t('pickup_at', $locale, ['address' => ShopSettings::for($merchant)->pickupAddress() ?: $merchant->name])
            : '🛵 '.trim(($state['zone'] ?? '').' — '.(($state['address'] ?? '') ?: $this->t('pin', $locale)), ' —');
        $out .= "\n".(! $cash ? '💳 Whish' : '💵 '.$this->t(($state['fulfillment'] ?? '') === 'pickup' ? 'cash_shop' : 'cash', $locale)
            .' · '.match ($state['currency'] ?? '') {
                'LBP' => Money::lbp((float) $settings->toLbp($total)),
                'MIXED' => $this->money($merchant, (float) $state['cash_main']).' + '.Money::lbp((float) $settings->toLbp($total - (float) $state['cash_main'])),
                default => $this->money($merchant, $total),
            });
        $out .= "\n⏱ ".app(OrderTiming::class)->estimate($merchant, (string) ($state['fulfillment'] ?? 'delivery'), $zone?->delivery_mins, $locale);

        return $out;
    }

    /**
     * The cart is below the chosen area's minimum: say so and send the customer back to the menu.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    protected function belowMinimum(Merchant $merchant, Conversation $conversation, array $state, string $locale): ?array
    {
        $zone = ! empty($state['zone']) ? DeliveryZone::query()->where('name', $state['zone'])->first() : null;
        $subtotal = (float) CartItem::query()->where('cart_id', $conversation->cart()->value('id'))->sum('line_total');
        if (! $zone || $subtotal >= (float) $zone->minimum_order) {
            return null;
        }

        $this->exit($conversation);

        return $this->menuMessage($merchant, __('whatsapp.checkout.minimum_order', [
            'zone' => $zone->name, 'minimum' => Money::format((float) $zone->minimum_order, $merchant->currency),
        ], $locale), $locale);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function rememberAddress(Customer $customer, array $state): void
    {
        $entry = [
            'id' => Str::lower(Str::random(6)),
            'zone' => (string) ($state['zone'] ?? ''),
            'address' => (string) ($state['address'] ?? ''),
            'location' => $state['location'] ?? null,
        ];
        $key = fn ($a) => mb_strtolower(trim(($a['zone'] ?? '').'|'.($a['address'] ?? '')));

        $saved = collect($customer->addresses ?? [])->reject(fn ($a) => $key($a) === $key($entry))->values()->all();
        array_unshift($saved, $entry);
        $customer->forceFill(['addresses' => array_slice($saved, 0, self::MAX_SAVED_ADDRESSES)])->save();
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function total(Conversation $conversation, array $state): float
    {
        $subtotal = (float) CartItem::query()->where('cart_id', $conversation->cart()->value('id'))->sum('line_total');
        $zone = ($state['fulfillment'] ?? '') === 'delivery' && ! empty($state['zone'])
            ? DeliveryZone::query()->where('name', $state['zone'])->first() : null;

        return $subtotal + ($zone ? (float) $zone->delivery_fee : 0.0);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    protected function ask(Conversation $conversation, array $state, string $step, array $message): array
    {
        $state['step'] = $step;
        $this->save($conversation, $state);

        return $message;
    }

    /**
     * @param  array<string, mixed>  $checkout
     */
    protected function save(Conversation $conversation, array $checkout): void
    {
        $state = $conversation->state ?? [];
        $state['checkout'] = $checkout;
        $state['phase'] = 'checkout';
        $conversation->forceFill(['state' => $state, 'last_message_at' => now()])->save();
    }

    protected function exit(Conversation $conversation, ?string $phase = null): void
    {
        $state = $conversation->state ?? [];
        unset($state['checkout']);
        $state['phase'] = $phase ?? 'ordering';
        $conversation->forceFill(['state' => $state])->save();
    }

    /** @param array<string, mixed> $params */
    protected function t(string $key, string $locale, array $params = []): string
    {
        return __("whatsapp.flow.{$key}", $params, $locale);
    }

    /** A typed amount, in Western or Arabic digits ("5", "7.5", "٥"); null when there is none. */
    protected function amount(string $text): ?float
    {
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', ',' => '.']);

        return preg_match('/\d+(?:\.\d+)?/', $text, $m) ? (float) $m[0] : null;
    }

    protected function money(Merchant $merchant, float $amount): string
    {
        return Money::format($amount, $merchant->currency);
    }

    /** @return array<string, mixed> */
    protected function text(string $body): array
    {
        return ['type' => 'text', 'text' => $body];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $buttons  [id, title]
     * @return array<string, mixed>
     */
    protected function buttons(string $body, array $buttons): array
    {
        return ['type' => 'interactive', 'interactive' => [
            'type' => 'button',
            'body' => ['text' => mb_substr($body, 0, 1024)],
            'action' => ['buttons' => array_map(fn ($b) => ['type' => 'reply', 'reply' => ['id' => $b[0], 'title' => mb_substr($b[1], 0, 20)]], $buttons)],
        ]];
    }

    /**
     * @param  list<array{id: string, title: string, description: string}>  $rows
     * @return array<string, mixed>
     */
    protected function list(string $body, string $button, array $rows): array
    {
        return ['type' => 'interactive', 'interactive' => [
            'type' => 'list',
            'body' => ['text' => mb_substr($body, 0, 1024)],
            'action' => ['button' => mb_substr($button, 0, 20), 'sections' => [['title' => mb_substr($button, 0, 24), 'rows' => array_map(
                fn ($r) => array_filter(['id' => $r['id'], 'title' => mb_substr($r['title'], 0, 24), 'description' => mb_substr($r['description'], 0, 72)], fn ($v) => $v !== ''),
                $rows,
            )]]],
        ]];
    }

    /** @return array<string, mixed> */
    protected function location(string $body): array
    {
        return ['type' => 'interactive', 'interactive' => [
            'type' => 'location_request_message',
            'body' => ['text' => mb_substr($body, 0, 1024)],
            'action' => ['name' => 'send_location'],
        ]];
    }

    /** The message with the menu list attached, so the customer can add more. */
    protected function menuMessage(Merchant $merchant, string $body, string $locale): array
    {
        return ShopSettings::for($merchant)->businessMode() === 'appointments'
            ? $this->text($body)
            : app(ChatMenu::class)->categories($merchant, $locale, 1, $body);
    }
}
