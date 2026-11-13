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
use App\Support\TimeRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Checkout on WhatsApp. Default is conversational (human words, no button menus): delivery or
 * pickup → location pin → cash or Whish → currency → receipt → confirm by typing.
 * State lives in conversations.state['checkout']. Old co:* button taps still work.
 *
 * Every method returns the WhatsApp message to send (text or interactive), or null when the
 * message is not part of the flow.
 */
class CheckoutFlow
{
    public const MAX_SAVED_ADDRESSES = Customer::MAX_SAVED_ADDRESSES;

    /** Customer locale for conversational option hints (set for the duration of a turn). */
    protected string $uiLocale = 'en';

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
        $this->uiLocale = $locale;
        $conversation->load('cart.items');
        if (! $conversation->cart || $conversation->cart->items->isEmpty()) {
            return $this->menuMessage($merchant, $this->t('empty', $locale), $locale);
        }

        $closedNow = ! $this->checkout->isOpen($merchant);
        // A closed shop still takes an order for an open time, when it has one to offer.
        $later = DeliverySchedule::days($merchant) !== [];
        if ((($merchant->settings ?? [])['mode'] ?? 'auto') === 'closed' || ($closedNow && ! $later)) {
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
     * Short "go on / finish / continue" (EN/AR/FR/Arabizi/👍): the customer wants the button checkout,
     * not another AI sentence. Used when the cart already has items and checkout is not open yet.
     */
    public static function looksLikeContinueCheckout(string $text): bool
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return false;
        }

        // Thumbs-up / OK / check alone (WhatsApp often sends these when the customer means "go ahead").
        if (preg_match('/^[\x{1F44D}\x{1F44C}\x{2705}\x{2714}\x{2611}]+\x{FE0F}?$/u', $text)) {
            return true;
        }

        $lower = mb_strtolower($text);
        if (mb_strlen($lower) > 40) {
            return false;
        }

        return (bool) preg_match(
            '/^(كمل|كمّل|كمل الطلب|تابع|تابعي|يلا|يلا كمل|خلص|خلصت|خلصنا|اكمل|أكمل|continue|go ahead|go on|checkout|check out|done|confirm|place (the )?order|c\'est tout|ok continue|kammel|kamel|kamml|yalla)$/u',
            $lower
        );
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
            $state['note'] = $address; // building, floor: the location pin is still asked
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>|null  $location
     * @return array<string, mixed>|null
     */
    public function handle(Merchant $merchant, Conversation $conversation, string $locale, ?string $buttonId, ?string $text, ?array $location): ?array
    {
        $this->uiLocale = $locale;
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

        // Conversational lists: the customer replies "1" / "2" instead of tapping a row.
        if (! $isFlowButton && $text !== null && ! empty($state['options']) && $location === null) {
            if ($picked = $this->typedOption($text, $state['options'])) {
                $buttonId = $picked;
                $isFlowButton = true;
            }
        }

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
                case 'time':
                    return $this->ask($conversation, $state, 'when_list', $this->timeList($merchant, $locale));
                case 'when':
                    if ($value === 'asap') {
                        $state['when'] = 'asap';
                    } else {
                        return $this->ask($conversation, $state, 'when_day', $this->dayList($merchant, $locale));
                    }
                    break;
                case 'day':
                    [$date, $page] = array_pad(explode(':', $value, 2), 2, 0);

                    return $this->ask($conversation, $state, 'when_time', $this->windowList($merchant, $date, $locale, (int) $page));
                case 'win':
                    if (DeliverySchedule::parse($merchant, $value)) {
                        $state['when'] = $value;
                    }
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

        // Waiting for Delivery or Pickup: typed answer ("delivery", "take away", "توصيل").
        if ($step === 'fulfillment' && $text !== null && $location === null && mb_strlen(trim($text)) <= 40) {
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

        // Payment / currency / confirm / skip — typed like a human reply (conversational checkout).
        if ($text !== null && $location === null && mb_strlen(trim($text)) <= 60) {
            if ($step === 'payment') {
                if (($pay = $this->typedPayment($text)) !== null) {
                    $state['payment'] = $pay;
                    $this->save($conversation, $state);

                    return $this->next($merchant, $conversation, $locale);
                }

                return $this->next($merchant, $conversation, $locale);
            }
            if ($step === 'currency') {
                if (($cur = $this->typedCurrency($text, $merchant)) !== null) {
                    $state['currency'] = $cur;
                    unset($state['cash_main']);
                    $this->save($conversation, $state);

                    return $this->next($merchant, $conversation, $locale);
                }

                return $this->next($merchant, $conversation, $locale);
            }
            if ($step === 'details' && $this->typedSkip($text)) {
                $state['address_done'] = true;
                $this->save($conversation, $state);

                return $this->next($merchant, $conversation, $locale);
            }
            if ($step === 'confirm') {
                if ($this->typedConfirm($text)) {
                    return $this->confirm($merchant, $conversation, $state, $locale);
                }
                if ($this->typedCancel($text)) {
                    $this->exit($conversation);
                    $conversation->cart?->items()->delete();
                    $conversation->cart?->update(['subtotal' => 0]);

                    return $this->text($this->t('cancelled', $locale));
                }
                if ($this->typedAsap($text)) {
                    $state['when'] = 'asap';
                    $this->save($conversation, $state);

                    return $this->next($merchant, $conversation, $locale);
                }
                // "another time" / a clock time: fall through to typedTime below.
            }
            if (in_array($step, ['address', 'zone', 'when_list', 'when_time', 'when_day'], true) && ! empty($state['options'])) {
                // Unrecognised number: show the list again instead of dumping to the AI.
                return $this->next($merchant, $conversation, $locale);
            }
        }

        // A change of mind ("actually make it 3") or a question ("what time do you close?") while we
        // wait for the address: back to the assistant, never saved as where to deliver.
        if (in_array($step, ['location', 'details'], true) && ! $location && $text !== null
            && (CartEditsFromMessage::looksLikeCartChange($text) || self::looksLikeQuestion($text))) {
            $this->exit($conversation);

            return null;
        }

        // Free input: a location pin while we wait for one (a typed address is never taken), or the note for the driver.
        if ($step === 'location' && $location) {
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
            if (! empty($state['note'])) {
                // What the customer already typed (building, floor) is the note: no second question.
                $state['address'] = trim($state['address'].' — '.$state['note'], ' —');
                unset($state['note']);
                $state['address_done'] = true;
            } else {
                $state['step'] = 'details';
                $this->save($conversation, $state);

                return $this->conversational()
                    ? $this->text($this->t('details', $locale))
                    : $this->buttons($this->t('details', $locale), [['co:skip:', $this->t('btn_skip', $locale)]]);
            }
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
        } elseif (in_array($step, ['confirm', 'when_list', 'when_time', 'when_day'], true) && $text !== null && $location === null && ($typed = $this->typedTime($merchant, $conversation, $state, $text, $locale)) !== null) {
            return $typed;
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

    /** cash / Whish in the customer's words; null otherwise. */
    protected function typedPayment(string $text): ?string
    {
        $t = mb_strtolower(trim($text));
        foreach (['whish', 'wish', 'ويش', 'ويش', 'بطاقة', 'card', 'carte'] as $word) {
            if (str_contains($t, $word)) {
                return 'whish';
            }
        }
        foreach (['cash', 'نقد', 'كاش', 'espece', 'espèce', 'especes', 'espèces', 'argent'] as $word) {
            if (str_contains($t, $word)) {
                return 'cash';
            }
        }

        return null;
    }

    /** dollars / lira / split; null otherwise. */
    protected function typedCurrency(string $text, Merchant $merchant): ?string
    {
        $t = mb_strtolower(trim($text));
        foreach (['split', 'both', 'mix', 'mixed', 'دولار و', 'و لير', '$ +', 'lbp +', 'les deux'] as $word) {
            if (str_contains($t, $word)) {
                return 'MIXED';
            }
        }
        foreach (['lbp', 'lira', 'llira', 'لير', 'ليرة', 'livres'] as $word) {
            if (str_contains($t, $word)) {
                return 'LBP';
            }
        }
        $main = strtolower((string) $merchant->currency);
        foreach (array_filter(['usd', 'dollar', 'dollars', '$', 'دولار', $main]) as $word) {
            if ($word !== '' && str_contains($t, $word)) {
                return $merchant->currency;
            }
        }

        return null;
    }

    protected function typedSkip(string $text): bool
    {
        $t = mb_strtolower(trim($text));

        return (bool) preg_match('/^(skip|passer|تخط|تخطي|لا|no|non|none|nothing|yalla|يلا|ok|okay|👍)$/u', $t)
            || str_contains($t, 'skip') || str_contains($t, 'passer') || str_contains($t, 'تخط');
    }

    protected function typedConfirm(string $text): bool
    {
        $t = mb_strtolower(trim($text));

        return (bool) preg_match('/^(yes|yep|yeah|ok|okay|confirm|confirmed|place|order|sure|yalla|يلا|تمام|أكد|تأكيد|اي|أي|اه|آه|oui|ouais|d\'accord|👍|✅)$/u', $t)
            || str_contains($t, 'confirm') || str_contains($t, 'تأكيد') || str_contains($t, 'أكد');
    }

    protected function typedCancel(string $text): bool
    {
        $t = mb_strtolower(trim($text));

        return (bool) preg_match('/^(cancel|stop|nevermind|never mind|الغ|الغاء|إلغاء|الغي|annuler|non)$/u', $t)
            || str_contains($t, 'cancel') || str_contains($t, 'إلغاء') || str_contains($t, 'الغاء') || str_contains($t, 'annul');
    }

    protected function typedAsap(string $text): bool
    {
        $t = mb_strtolower(trim($text));

        return (bool) preg_match('/^(asap|soon|soonest|now|الآن|هلأ|هلق|tout de suite|au plus vite)$/u', $t)
            || str_contains($t, 'asap') || str_contains($t, 'soonest') || str_contains($t, 'بأسرع');
    }

    /**
     * Numbered reply ("1", "2.") or fuzzy title match against the last conversational options list.
     *
     * @param  array<string, string>  $options  n => co:* id
     */
    protected function typedOption(string $text, array $options): ?string
    {
        $t = trim($text);
        if ($t === '') {
            return null;
        }
        if (preg_match('/^(\d+)\s*[.)]?$/u', $t, $m) && isset($options[$m[1]])) {
            return $options[$m[1]];
        }
        $lower = mb_strtolower($t);
        foreach ($options as $id) {
            // Titles are not stored; matching by id slug fragments is enough for "new" / zone names typed loosely.
            $slug = mb_strtolower((string) Str::afterLast($id, ':'));
            if ($slug !== '' && $slug !== 'new' && (str_contains($lower, $slug) || $lower === $slug)) {
                return $id;
            }
            if ($slug === 'new' && preg_match('/\b(new|nouveau|جديد|autre|other)\b/u', $lower)) {
                return $id;
            }
        }

        return null;
    }

    protected function conversational(): bool
    {
        return (bool) config('whatsapp.conversational', true);
    }

    /**
     * Works out the next missing piece and asks for it.
     *
     * @return array<string, mixed>
     */
    protected function next(Merchant $merchant, Conversation $conversation, string $locale): array
    {
        $this->uiLocale = $locale;
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
            // A pin the customer already sent in this chat, before being asked, is the delivery location:
            // it is taken exactly as if it arrived now, and never asked for a second time.
            $earlier = ($conversation->state ?? [])['location'] ?? null;
            if (empty($state['location']) && empty($state['pin_used']) && empty($state['new_address'])
                && is_array($earlier) && isset($earlier['lat'], $earlier['lng'])) {
                $this->save($conversation, ['step' => 'location', 'pin_used' => true] + $state);

                return $this->handle($merchant, $conversation, $locale, null, null, $earlier)
                    ?? $this->location($this->t('location_pin', $locale));
            }

            $saved = array_values(array_filter($conversation->customer->addresses ?? [], fn ($a) => ! empty($a['location'])));
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

        // A shop that is closed right now takes the order for an open time: the customer picks it here. Every other
        // order gets the shop's own time, and "Another time" on the summary is the way to ask for a different one.
        if (! empty($state['later_only']) && empty($state['when'])) {
            return $this->ask($conversation, $state, 'when_list', $this->timeList($merchant, $locale, $this->t('closed_pick_day', $locale, ['shop' => $merchant->name])));
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

        $chosen = ($state['when'] ?? 'asap') !== 'asap' && empty($state['later_only']);
        $receipt = $this->receipt($merchant, $conversation, $state, $locale)."\n\n".$this->t('change_items_hint', $locale);

        if ($this->conversational()) {
            $hint = $chosen
                ? $this->t('confirm_hint_asap', $locale)
                : $this->t('confirm_hint', $locale);

            return $this->ask($conversation, $state, 'confirm', $this->text($receipt."\n\n".$hint));
        }

        return $this->ask($conversation, $state, 'confirm', $this->buttons($receipt, [
            ['co:ok:', $this->t('btn_confirm', $locale)],
            $chosen ? ['co:when:asap', $this->t('btn_asap', $locale)] : ['co:time:', $this->t('btn_other_time', $locale)],
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
        ]);

        $this->exit($conversation, $order ? 'completed' : null);

        if ($order && $order->fulfillment === 'delivery') {
            $conversation->customer->rememberAddress((string) ($state['zone'] ?? ''), (string) ($state['address'] ?? ''), $state['location'] ?? null);
        }

        // A Whish order accepted at once has already been sent its payment details: nothing more to say.
        if ($order && $reply === '') {
            return ['type' => 'none'];
        }

        return $order ? $this->text($reply) : $this->menuMessage($merchant, $reply, $locale);
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
            : "\n⏱ ".app(OrderTiming::class)->estimate($merchant, (string) ($state['fulfillment'] ?? 'delivery'), $locale);

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
    /** The next open days as a list ("Fri 9 Oct"). A shop that counts in days picks the day itself: no time after it. */
    protected function dayList(Merchant $merchant, string $locale, ?string $body = null): array
    {
        $byDay = DeliverySchedule::byDay($merchant);
        $rows = array_map(fn ($day) => [
            'id' => $byDay ? 'co:win:'.DeliverySchedule::windows($merchant, $day)[0]->format('Y-m-d H:i') : 'co:day:'.$day->toDateString(),
            'title' => $day->copy()->locale($locale)->isoFormat('ddd D MMM'),
            'description' => '',
        ], DeliverySchedule::days($merchant));

        return $this->list($body ?? $this->t('pick_day', $locale), $this->t('btn_choose', $locale), $rows);
    }

    /** That day's windows as a list ("10:00–11:00"), nine to a page with "More times" last. */
    protected function windowList(Merchant $merchant, string $date, string $locale, int $page = 0): array
    {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? Carbon::createFromFormat('Y-m-d', $date, $merchant->timezone ?: config('app.timezone'))->startOfDay() : null;
        $windows = $day ? DeliverySchedule::windows($merchant, $day) : [];
        $shown = array_slice($windows, $page * 9, 9);
        if ($shown === []) {
            return $this->dayList($merchant, $locale);
        }

        $rows = array_map(fn ($w) => [
            'id' => 'co:win:'.$w->format('Y-m-d H:i'),
            'title' => $w->format('H:i').'–'.$w->copy()->addMinutes(DeliverySchedule::windowMins($merchant))->format('H:i'),
            'description' => '',
        ], $shown);
        if (count($windows) > ($page + 1) * 9) {
            $rows[] = ['id' => 'co:day:'.$date.':'.($page + 1), 'title' => $this->t('more_times', $locale), 'description' => ''];
        }

        return $this->list($this->t('pick_time', $locale, ['day' => $day->copy()->locale($locale)->isoFormat('ddd D MMM')]), $this->t('btn_choose', $locale), $rows);
    }

    /**
     * One list of the next times the shop can do, earliest first, with "Another day" last. A shop that counts in
     * days gets its days. Nothing in it is earlier than the shop's own preparation time allows.
     *
     * @return array<string, mixed>
     */
    protected function timeList(Merchant $merchant, string $locale, ?string $body = null): array
    {
        if (DeliverySchedule::byDay($merchant)) {
            return $this->dayList($merchant, $locale, $body);
        }

        $slots = [];
        foreach (DeliverySchedule::days($merchant) as $day) {
            foreach (DeliverySchedule::windows($merchant, $day) as $window) {
                $slots[] = $window;
                if (count($slots) >= 9) {
                    break 2;
                }
            }
        }
        if ($slots === []) {
            return $this->text($this->t('no_times', $locale));
        }

        $rows = array_map(fn (Carbon $w) => ['id' => 'co:win:'.$w->format('Y-m-d H:i'), 'title' => $this->slotTitle($merchant, $w, $locale), 'description' => ''], $slots);
        $rows[] = ['id' => 'co:when:pick', 'title' => $this->t('another_day', $locale), 'description' => ''];

        return $this->list(
            $body ?? $this->t('when_pick', $locale, ['earliest' => DeliverySchedule::label($merchant, $slots[0], $locale)]),
            $this->t('btn_choose', $locale),
            $rows,
        );
    }

    /** "Today 20:00–21:00", "Tomorrow 12:00–13:00", "Fri 9 14:00–15:00": short enough for a list row. */
    protected function slotTitle(Merchant $merchant, Carbon $start, string $locale): string
    {
        $today = now()->setTimezone($merchant->timezone ?: (string) config('app.timezone'))->startOfDay();
        $day = match (true) {
            $start->isSameDay($today) => $this->t('today', $locale),
            $start->isSameDay($today->copy()->addDay()) => $this->t('tomorrow', $locale),
            default => $start->copy()->locale($locale)->isoFormat('ddd D'),
        };

        return $day.' '.$start->format('H:i').'–'.$start->copy()->addMinutes(DeliverySchedule::windowMins($merchant))->format('H:i');
    }

    /**
     * The customer wrote the time they want on the summary ("tomorrow at 6", "demain à 18h"). The slot that holds it
     * becomes the order's time; when the shop cannot do it, the customer gets the earliest times to choose from.
     * Null when the message is not a time at all, so it goes to the assistant as before.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    protected function typedTime(Merchant $merchant, Conversation $conversation, array $state, string $text, string $locale): ?array
    {
        $now = now()->setTimezone($merchant->timezone ?: (string) config('app.timezone'));
        $request = TimeRequest::parse($text, $now);
        if (! $request) {
            return null;
        }

        $byDay = DeliverySchedule::byDay($merchant);
        if ($byDay && ! $request['date']) {
            return null; // a shop that counts in days has no hours to choose
        }

        $days = DeliverySchedule::days($merchant);
        if ($request['date']) {
            $days = array_values(array_filter($days, fn (Carbon $d) => $d->toDateString() === $request['date']));
        }

        $slot = null;
        if ($byDay) {
            $slot = $days !== [] ? DeliverySchedule::windows($merchant, $days[0])[0] : null;
        } elseif ($request['hour'] === null) {
            // A day with no time: that day's windows.
            return $days !== [] ? $this->ask($conversation, $state, 'when_time', $this->windowList($merchant, $request['date'], $locale)) : $this->unavailableTime($merchant, $conversation, $state, $locale);
        } else {
            $hours = $request['ambiguous'] ? ($request['hour'] === 12 ? [0, 12] : [$request['hour'], $request['hour'] + 12]) : [$request['hour']];
            $length = DeliverySchedule::windowMins($merchant);
            foreach ($days as $day) {
                foreach ($hours as $hour) {
                    $at = $day->copy()->setTime($hour, $request['minute']);
                    foreach (DeliverySchedule::windows($merchant, $day) as $window) {
                        if ($at->gte($window) && $at->lt($window->copy()->addMinutes($length))) {
                            $slot = $window;
                            break 3;
                        }
                    }
                }
            }
        }

        if (! $slot) {
            return $this->unavailableTime($merchant, $conversation, $state, $locale);
        }

        $state['when'] = $slot->format('Y-m-d H:i');
        $this->save($conversation, $state);

        return $this->next($merchant, $conversation, $locale);
    }

    /**
     * The written time cannot be done: say when the earliest is and show the times.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function unavailableTime(Merchant $merchant, Conversation $conversation, array $state, string $locale): array
    {
        $first = null;
        foreach (DeliverySchedule::days($merchant) as $day) {
            if ($first = (DeliverySchedule::windows($merchant, $day)[0] ?? null)) {
                break;
            }
        }
        $body = $first ? $this->t('time_unavailable', $locale, ['earliest' => DeliverySchedule::label($merchant, $first, $locale)]) : null;

        return $this->ask($conversation, $state, 'when_list', $this->timeList($merchant, $locale, $body));
    }

    protected function ask(Conversation $conversation, array $state, string $step, array $message): array
    {
        $state['step'] = $step;
        if (isset($message['_co_options']) && is_array($message['_co_options'])) {
            $state['options'] = $message['_co_options'];
            unset($message['_co_options']);
        } else {
            unset($state['options']);
        }
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
        if ($this->conversational()) {
            $lines = [rtrim($body), ''];
            foreach ($buttons as $b) {
                $lines[] = '• '.$b[1];
            }
            $lines[] = '';
            $lines[] = $this->t('reply_option', $this->uiLocale);

            return $this->text(implode("\n", $lines));
        }

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
        if ($this->conversational()) {
            $map = [];
            $lines = [rtrim($body), ''];
            foreach (array_values($rows) as $i => $r) {
                $n = (string) ($i + 1);
                $map[$n] = $r['id'];
                $line = "{$n}. {$r['title']}";
                if (($r['description'] ?? '') !== '') {
                    $line .= ' — '.$r['description'];
                }
                $lines[] = $line;
            }
            $lines[] = '';
            $lines[] = $this->t('reply_number', $this->uiLocale);

            return ['_co_options' => $map] + $this->text(implode("\n", $lines));
        }

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
        return app(ChatMenu::class)->textMenu($merchant, $locale, $body);
    }
}
