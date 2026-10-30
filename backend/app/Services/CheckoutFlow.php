<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Merchant;
use App\Models\Modifier;
use App\Models\Order;
use App\Services\AI\CartEditsFromMessage;
use App\Support\DeliverySchedule;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\ShopSettings;
use Illuminate\Support\Carbon;
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
    public const MAX_SAVED_ADDRESSES = Customer::MAX_SAVED_ADDRESSES;

    public function __construct(
        protected ConversationService $conversations,
        protected CheckoutService $checkout,
    ) {}

    public function active(Conversation $conversation): bool
    {
        return isset(($conversation->state ?? [])['checkout']);
    }

    /**
     * @param  array<string, mixed>|null  $known  what the customer already said, from the assistant:
     *                                            fulfillment and delivery_address (a typed address)
     * @return array<string, mixed>
     */
    public function start(Merchant $merchant, Conversation $conversation, string $locale, ?array $known = null): array
    {
        $conversation->load('cart.items');
        if (! $conversation->cart || $conversation->cart->items->isEmpty()) {
            return $this->menuMessage($merchant, $this->t('empty', $locale), $locale);
        }

        $closedNow = ! $this->checkout->isOpen($merchant);
        // Shops that take orders for later (florists, cakes) also take them while closed, for an open time.
        $scheduled = ShopSettings::for($merchant)->scheduledOrders() && DeliverySchedule::days($merchant) !== [];
        if ((($merchant->settings ?? [])['mode'] ?? 'auto') === 'closed' || ($closedNow && ! $scheduled)) {
            return $this->text(__('whatsapp.checkout.shop_closed', ['shop' => $merchant->name], $locale));
        }

        $this->save($conversation, $this->known($merchant, $known ?? []) + ($closedNow ? ['later_only' => true] : []));

        return $this->next($merchant, $conversation, $locale);
    }

    /** "what time do you close?", "Vous fermez à quelle heure", "ايمتى بتسكرو؟", "addesh el delivery": not an address. */
    public static function looksLikeQuestion(string $text): bool
    {
        $text = mb_strtolower(trim($text));

        return (bool) preg_match('/[?؟]\s*$/u', $text)
            || (bool) preg_match('/^(what|when|how|why|do you|does|is it|is there|are you|can i|can you|could you|est-ce|quand|combien|pourquoi|vous (avez|fermez|ouvrez|livrez)|quel(le)?s? |shu |chou |aymta|emta|addesh|adesh|2adesh|ade[s]?h |شو |ايمتى|إيمتى|امتى|قديش|كم |هل |متى|ليش|في عندكم)/u', $text);
    }

    /**
     * A customer who typed "deliver to Sassine, bldg Rizk" is not asked Delivery or Pickup, nor
     * for the address again; the area, fee and minimum are still checked by the next steps.
     *
     * @param  array<string, mixed>  $known
     * @return array<string, mixed>
     */
    protected function known(Merchant $merchant, array $known): array
    {
        $offered = ShopSettings::for($merchant)->fulfillment();
        $address = trim((string) ($known['delivery_address'] ?? ''));
        $wanted = ($known['fulfillment'] ?? null) ?: ($address !== '' ? 'delivery' : null);
        if (! in_array($wanted, ['delivery', 'pickup'], true) || ($offered !== 'both' && $offered !== $wanted)) {
            return [];
        }

        $state = ['fulfillment' => $wanted];
        if ($wanted === 'delivery' && mb_strlen($address) >= 5) {
            $state['address'] = $address;
            $state['address_done'] = true;
        }

        return $state;
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
                        if (($pin = $saved['location'] ?? null) && DeliveryZone::mapped()) {
                            $zone = DeliveryZone::at((float) $pin['lat'], (float) $pin['lng']);
                            if (! $zone) {
                                return $this->outOfArea($merchant, $conversation, $state, $locale);
                            }
                            $saved['zone'] = $zone->name;
                        }
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
                case 'when':
                    if ($value === 'asap') {
                        $state['when'] = 'asap';
                    } else {
                        return $this->ask($conversation, $state, 'when_day', $this->dayList($merchant, $locale));
                    }
                    break;
                case 'day':
                    return $this->ask($conversation, $state, 'when_time', $this->windowList($merchant, $value, $locale));
                case 'win':
                    if (DeliverySchedule::parse($merchant, $value)) {
                        $state['when'] = $value;
                    }
                    break;
                case 'gift':
                    $state['gift'] = $value === 'yes';
                    break;
                case 'card':
                    $state['card'] = '';
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

        // Waiting for Delivery or Pickup: a short typed answer works too ("delivery", "take away", "توصيل").
        // Another short reply is asked again with the buttons; a long message (an order in one go) goes
        // on to the assistant as before.
        if ($step === 'fulfillment' && $text !== null && $location === null && mb_strlen(trim($text)) <= 30) {
            $typed = $this->typedFulfillment($text);
            if ($typed !== null) {
                $state['fulfillment'] = $typed;
                $this->save($conversation, $state);

                return $this->next($merchant, $conversation, $locale);
            }

            return $this->ask($conversation, $state, 'fulfillment', $this->buttons($this->t('how', $locale), [
                ['co:ful:delivery', $this->t('btn_delivery', $locale)],
                ['co:ful:pickup', $this->t('btn_pickup', $locale)],
            ]));
        }

        // A change of mind ("actually make it 3") or a question ("what time do you close?") while we
        // wait for the address: back to the assistant, never saved as where to deliver.
        if (in_array($step, ['location', 'details'], true) && ! $location && $text !== null
            && (CartEditsFromMessage::looksLikeCartChange($text) || self::looksLikeQuestion($text))) {
            $this->exit($conversation);

            return null;
        }

        // Free input: a location pin or typed address while we wait for one, or address details.
        if ($step === 'location' && ($location || ($text !== null && mb_strlen(trim($text)) >= 5))) {
            if ($location) {
                if (DeliveryZone::mapped()) {
                    $zone = DeliveryZone::at((float) $location['lat'], (float) $location['lng']);
                    if (! $zone) {
                        return $this->outOfArea($merchant, $conversation, $state, $locale);
                    }
                    $state['zone'] = $zone->name;
                    if ($refusal = $this->belowMinimum($merchant, $conversation, $state, $locale)) {
                        return $refusal;
                    }
                }
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
        } elseif ($step === 'recipient' && $text !== null) {
            // "Lara 70 123 456": a name and a phone number, in any order.
            $phone = preg_match('/\+?[\d\s\-]{7,}/u', strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']), $m) ? preg_replace('/[^\d+]/', '', $m[0]) : '';
            $name = trim(preg_replace('/\+?[\d\s\-٠-٩]{7,}/u', ' ', $text), " ,-\n");
            if (strlen(ltrim($phone, '+')) < 7 || $name === '') {
                return $this->text($this->t('recipient_invalid', $locale));
            }
            $state['recipient'] = ['name' => mb_substr($name, 0, 80), 'phone' => $phone];
        } elseif ($step === 'card' && $text !== null) {
            $state['card'] = mb_substr(trim($text), 0, 300);
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

    /** "delivery" / "pickup" in the words a customer types (English, French, Arabic); null otherwise. */
    protected function typedFulfillment(string $text): ?string
    {
        $t = mb_strtolower(trim($text));
        foreach (['delivery', 'deliver', 'livraison', 'livrer', 'توصيل', 'ديليفري', 'ديلفري'] as $word) {
            if (str_contains($t, $word)) {
                return 'delivery';
            }
        }
        foreach (['pickup', 'pick up', 'pick-up', 'take away', 'takeaway', 'take-away', 'collect', 'récupérer', 'recuperer', 'emporter', 'استلام', 'أستلم', 'استلم', 'سأستلم'] as $word) {
            if (str_contains($t, $word)) {
                return 'pickup';
            }
        }

        return null;
    }

    /**
     * Works out the next missing piece and asks for it.
     *
     * @return array<string, mixed>
     */
    protected function next(Merchant $merchant, Conversation $conversation, string $locale): array
    {
        // Minimums and the receipt use today's prices, the same ones the order will be saved with.
        if ($cart = $conversation->cart()->first()) {
            app(CartService::class)->refreshPrices($cart);
        }
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
            if ($zones->whereNotNull('area')->isNotEmpty()) {
                // Zones drawn on the map: the customer's pin decides the zone, fee and minimum.
                return $this->ask($conversation, $state, 'location', $this->location($this->t('location_pin', $locale)));
            }
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

        // A typed address with zones on the map: the customer picks the zone from the list.
        if ($state['fulfillment'] === 'delivery' && empty($state['zone']) && empty($state['location'])) {
            $zones = DeliveryZone::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
            if ($zones->count() > 1) {
                return $this->ask($conversation, $state, 'zone', $this->list($this->t('which_area', $locale), $this->t('btn_choose', $locale), $this->zoneRows($merchant, $zones, $locale)));
            }
            if ($zones->count() === 1) {
                $state['zone'] = $zones->first()->name;
            }
        }

        // Florists, cakes: when it should arrive (or be ready), as soon as possible or a chosen window.
        if ($settings->scheduledOrders() && empty($state['when'])) {
            if (! empty($state['later_only'])) {
                return $this->ask($conversation, $state, 'when_day', $this->dayList($merchant, $locale, $this->t('closed_pick_day', $locale, ['shop' => $merchant->name])));
            }

            return $this->ask($conversation, $state, 'when', $this->buttons($this->t('when', $locale), [
                ['co:when:asap', $this->t('btn_asap', $locale)],
                ['co:when:pick', $this->t('btn_pick_day', $locale)],
            ]));
        }

        // Sent to someone else: their name and phone (for the driver), then the card.
        if ($settings->giftOrders() && ! isset($state['gift'])) {
            return $this->ask($conversation, $state, 'gift', $this->buttons($this->t('gift_ask', $locale), [
                ['co:gift:yes', $this->t('btn_gift_yes', $locale)],
                ['co:gift:no', $this->t('btn_gift_no', $locale)],
            ]));
        }
        if (! empty($state['gift']) && empty($state['recipient'])) {
            return $this->ask($conversation, $state, 'recipient', $this->text($this->t('recipient_ask', $locale)));
        }
        if (! empty($state['gift']) && ! isset($state['card'])) {
            return $this->ask($conversation, $state, 'card', $this->buttons($this->t('card_ask', $locale), [['co:card:', $this->t('btn_no_card', $locale)]]));
        }

        if (empty($state['payment'])) {
            if (ShopSettings::for($merchant)->whishNumber() !== null) {
                return $this->ask($conversation, $state, 'payment', $this->buttons($this->t('pay', $locale), [
                    ['co:pay:cash', $this->t($state['fulfillment'] === 'pickup' ? 'btn_cash_shop' : 'btn_cash', $locale)],
                    ['co:pay:whish', $this->t('btn_whish', $locale)],
                ]));
            }
            $state['payment'] = 'cash';
        }

        if (empty($state['currency'])) {
            $total = $this->total($conversation, $state);
            if (in_array($state['payment'], ['cash', 'whish'], true) && ($lbp = $settings->toLbp($total)) !== null && strtoupper((string) $merchant->currency) !== 'LBP') {
                // The total in both currencies: all in dollars, or all in lira (cash can also be split).
                $options = [
                    ['co:cur:'.$merchant->currency, Str::limit("💵 {$this->money($merchant, $total)}", 20, '')],
                    ['co:cur:LBP', Str::limit('🇱🇧 '.Money::lbp($lbp), 20, '')],
                ];
                if ($state['payment'] === 'cash') {
                    $options[] = ['co:cur:MIXED', $this->t('btn_split', $locale)];
                }

                return $this->ask($conversation, $state, 'currency', $this->buttons($this->t('currency', $locale, [
                    'usd' => $this->money($merchant, $total), 'lbp' => Money::lbp($lbp),
                ]), $options));
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
            'deliver_at' => ($state['when'] ?? 'asap') !== 'asap' ? $state['when'] : null,
            'recipient_name' => $state['recipient']['name'] ?? null,
            'recipient_phone' => $state['recipient']['phone'] ?? null,
            'gift_message' => ($state['card'] ?? '') ?: null,
        ]);

        $this->exit($conversation, $order ? 'completed' : null);

        if ($order && $order->fulfillment === 'delivery') {
            $conversation->customer->rememberAddress((string) ($state['zone'] ?? ''), (string) ($state['address'] ?? ''), $state['location'] ?? null);
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
            $line = Quantity::line($i->quantity, Quantity::byWeight($i->product) ? $i->product->unit : null, $name).' — '.$this->money($merchant, (float) $i->line_total);
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
        $colon = $locale === 'fr' ? ' : ' : ': '; // French puts a space before the colon
        $out .= $this->t('subtotal', $locale).$colon.$this->money($merchant, $subtotal)."\n";
        if ($zone) {
            $out .= $this->t('delivery_fee', $locale, ['zone' => $zone->name]).$colon.$this->money($merchant, $fee)."\n";
        }
        $out .= '*'.$this->t('total', $locale).$colon.$this->money($merchant, $total).'*';
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
        $chosen = ($state['when'] ?? 'asap') !== 'asap' ? DeliverySchedule::parse($merchant, $state['when']) : null;
        $out .= $chosen
            ? "\n🗓 ".DeliverySchedule::label($merchant, $chosen, $locale)
            : "\n⏱ ".app(OrderTiming::class)->estimate($merchant, (string) ($state['fulfillment'] ?? 'delivery'), $zone?->delivery_mins, $locale);
        if (! empty($state['recipient'])) {
            $out .= "\n🎁 ".$state['recipient']['name'].' · '.$state['recipient']['phone'].(filled($state['card'] ?? null) ? "\n💌 “{$state['card']}”" : '');
        }

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
    /** The next open days as a list ("Fri 9 Oct"). */
    protected function dayList(Merchant $merchant, string $locale, ?string $body = null): array
    {
        $rows = array_map(fn ($day) => [
            'id' => 'co:day:'.$day->toDateString(),
            'title' => $day->copy()->locale($locale)->isoFormat('ddd D MMM'),
            'description' => '',
        ], DeliverySchedule::days($merchant));

        return $this->list($body ?? $this->t('pick_day', $locale), $this->t('btn_choose', $locale), $rows);
    }

    /** That day's two-hour windows as a list ("10:00–12:00"). */
    protected function windowList(Merchant $merchant, string $date, string $locale): array
    {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? Carbon::createFromFormat('Y-m-d', $date, $merchant->timezone ?: config('app.timezone'))->startOfDay() : null;
        $windows = $day ? array_slice(DeliverySchedule::windows($merchant, $day), 0, 10) : [];
        if ($windows === []) {
            return $this->dayList($merchant, $locale);
        }

        return $this->list($this->t('pick_time', $locale, ['day' => $day->copy()->locale($locale)->isoFormat('ddd D MMM')]), $this->t('btn_choose', $locale), array_map(fn ($w) => [
            'id' => 'co:win:'.$w->format('Y-m-d H:i'),
            'title' => $w->format('H:i').'–'.$w->copy()->addMinutes(DeliverySchedule::WINDOW_MINS)->format('H:i'),
            'description' => '',
        ], $windows));
    }

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
    /** @return list<array{id: string, title: string, description: string}> */
    protected function zoneRows(Merchant $merchant, $zones, string $locale): array
    {
        return $zones->take(10)->map(fn (DeliveryZone $z) => [
            'id' => "co:zone:{$z->id}",
            'title' => Str::limit($z->name, 21, '…'),
            'description' => $this->t('zone_fee', $locale, [
                'fee' => $this->money($merchant, (float) $z->delivery_fee),
                'minimum' => (float) $z->minimum_order > 0 ? $this->t('zone_min', $locale, ['minimum' => $this->money($merchant, (float) $z->minimum_order)]) : '',
            ]),
        ])->values()->all();
    }

    /**
     * The pin is outside every zone the shop drew: say so, and offer pickup when the shop has it.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function outOfArea(Merchant $merchant, Conversation $conversation, array $state, string $locale): array
    {
        $state['step'] = 'location';
        unset($state['zone'], $state['location']);
        $this->save($conversation, $state);
        $pickup = ShopSettings::for($merchant)->fulfillment() === 'both';
        $text = $this->t($pickup ? 'out_of_area_pickup' : 'out_of_area', $locale, ['shop' => $merchant->name]);

        return $pickup
            ? $this->buttons($text, [['co:ful:pickup', $this->t('btn_pickup', $locale)]])
            : $this->location($text);
    }

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
