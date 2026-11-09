<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShopHour;
use App\Services\AI\CartEditsFromMessage;
use App\Services\AI\CatalogResolver;
use App\Services\AI\Contracts\ChatProvider;
use App\Services\AI\DTO\ConversationTurnInput;
use App\Services\AI\DTO\StructuredTurnOutput;
use App\Services\AI\ExactItemFromMessage;
use App\Services\AI\MessageLanguage;
use App\Services\AI\QuantityFromMessage;
use App\Services\AI\ReplyFigures;
use App\Support\CheckoutException;
use App\Support\Conversation\CartStatus;
use App\Support\Conversation\ConversationIntent;
use App\Support\Conversation\ConversationStatus;
use App\Support\Conversation\RecommendedAction;
use App\Support\CurrentTenant;
use App\Support\Money;
use App\Support\OrderMessage;
use App\Support\Quantity;
use App\Support\ShopSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Orchestrates AI conversation turns: loads context, calls the ChatProvider,
 * applies cart mutations, and persists conversation state.
 */
class ConversationService
{
    /** Set when the last checkout stopped because the customer has not chosen delivery or pickup. */
    private bool $fulfillmentMissing = false;

    /** Set when the customer asked to cancel: the order waits for the customer's Yes/No button. */
    private ?string $cancelAwaitingId = null;

    public function __construct(
        protected ChatProvider $chatProvider,
        protected CartService $cartService,
        protected CatalogResolver $catalogResolver,
        protected QuantityFromMessage $quantities,
        protected CartEditsFromMessage $cartEdits,
        protected CheckoutService $checkoutService,
        protected DriverDispatcher $dispatcher,
        protected OrderService $orders,
        protected OrderConfirmation $confirmation,
    ) {}

    /**
     * Start or resume an active conversation for a customer phone number.
     *
     * @param  array{phone:string,name?:string,locale?:string}  $customerData
     */
    public function startOrResume(array $customerData, string $channel = 'simulation'): Conversation
    {
        $this->requireTenant();

        return DB::transaction(function () use ($customerData, $channel) {
            $customer = Customer::firstOrCreate(
                ['phone' => $customerData['phone']],
                [
                    'name' => $customerData['name'] ?? null,
                    'locale' => $customerData['locale'] ?? 'en',
                ]
            );

            if ($customer->is_blocked) {
                throw new InvalidArgumentException('Customer is blocked.');
            }

            // The WhatsApp profile name fills an empty name; a name already saved is kept.
            if (blank($customer->name) && filled($customerData['name'] ?? null)) {
                $customer->forceFill(['name' => $customerData['name']])->save();
            }

            $existing = Conversation::where('customer_id', $customer->id)
                ->where('status', ConversationStatus::Active)
                ->first();

            if ($existing && $this->isStale($existing)) {
                $existing->update(['status' => ConversationStatus::Closed]);
                $existing->cart()->where('status', CartStatus::Open)->update(['status' => CartStatus::Abandoned]);
                $existing = null;
            }

            if ($existing) {
                return $existing->load(['customer', 'cart.items.product', 'cart.items.variant']);
            }

            $conversation = Conversation::create([
                'customer_id' => $customer->id,
                'status' => ConversationStatus::Active,
                'channel' => $channel,
                'state' => ['messages' => [], 'phase' => 'idle'],
            ]);

            $this->cartService->createForConversation($conversation->id);

            return $conversation->load(['customer', 'cart.items.product', 'cart.items.variant']);
        });
    }

    /**
     * Process one inbound customer message and return the structured AI result.
     *
     * `reply` is what to send the customer: the AI's message, or the server's checkout result
     * (order confirmation / reason it could not be placed) when the AI asked to place the order.
     *
     * @return array{output: StructuredTurnOutput, cart_result: array{applied:int,rejected:array}, conversation: Conversation, reply: string, order: ?Order}
     */
    public function processTurn(Conversation $conversation, string $message, ?array $location = null): array
    {
        $this->requireTenant();

        if ($conversation->status !== ConversationStatus::Active) {
            throw new InvalidArgumentException('Conversation is not active.');
        }

        if ($conversation->customer->is_blocked) {
            throw new InvalidArgumentException('Customer is blocked.');
        }

        $conversation->load(['customer', 'cart.items.product', 'cart.items.variant']);
        $cart = $conversation->cart ?? $this->cartService->createForConversation($conversation->id);

        $state = $conversation->state ?? ['messages' => [], 'phase' => 'idle'];

        // A WhatsApp location pin is kept on the conversation and used as the delivery location.
        if ($location) {
            $state['location'] = $location;
            $message = trim($message.' [Customer shared their delivery location pin'
                .(($location['address'] ?? '') !== '' ? ": {$location['address']}" : '').']');
        }
        $history = array_map(
            fn (array $m) => ['role' => $m['role'], 'content' => $m['content']],
            array_slice($state['messages'] ?? [], -config('ai.conversation.history_turns', 20))
        );

        $input = new ConversationTurnInput(
            message: $message,
            locale: $conversation->customer->locale ?? 'en',
            history: $history,
            catalog: $this->catalogResolver->buildCatalogSnapshot(
                $this->searchText($message, $history),
                $cart->items->pluck('product_id')->filter()->values()->all(),
            )->all(),
            cartLines: $this->cartService->snapshotLines($cart)->all(),
            conversationPhase: $state['phase'] ?? 'idle',
            shop: $this->shopContext(),
        );

        $output = $this->chatProvider->processTurn($input);

        // Every reply follows the customer's language: an answer in another one is asked for again, once.
        $language = MessageLanguage::detect($message);
        if (MessageLanguage::mismatch($language, $output->assistantMessage)) {
            $output = $this->chatProvider->processTurn(new ConversationTurnInput(
                message: $input->message, locale: $input->locale, history: $input->history, catalog: $input->catalog,
                cartLines: $input->cartLines, conversationPhase: $input->conversationPhase, shop: $input->shop,
                replyLanguage: $language,
            ));
        }

        $actions = $this->withoutClearAfterAdd($output->cartActions);
        $actions = (new ExactItemFromMessage)->correct($message, $actions, $input->catalog);
        $actions = $this->quantities->correct($message, $actions, $input->catalog);
        $actions = $this->cartEdits->apply($message, $actions, $input->cartLines, $input->catalog);
        $cartResult = $this->cartService->applyActions($cart, $actions);

        $this->rememberLanguage($conversation, $output, $language);

        $this->fulfillmentMissing = false;
        $this->cancelAwaitingId = null;
        [$reply, $order] = match ($output->recommendedAction) {
            RecommendedAction::PlaceOrder => $this->checkout($conversation, $output),
            RecommendedAction::CancelOrder => [$this->cancelLatestOrder($conversation), null],
            RecommendedAction::TrackOrder => [$this->orderStatus($conversation), null],
            RecommendedAction::Handoff => [$this->handoff($conversation), null],
            default => [$this->withoutMadeUpPrice(Money::fromAiText($output->assistantMessage), $message, $input, $cart, $language, $conversation), null],
        };
        // The cart refused an item (a size or required extra missing, sold out, needs a check): the customer is told in words.
        $refusal = null;
        if (! in_array($output->recommendedAction, [RecommendedAction::PlaceOrder, RecommendedAction::CancelOrder, RecommendedAction::Handoff], true)
            && ($refusal = $this->refusalReply($conversation, $cartResult, in_array($language, ['en', 'fr', 'ar'], true) ? $language : ($conversation->customer->locale ?? 'en'))) !== null) {
            $reply = $refusal;
        }
        // After an add, change or removal the customer sees what is in the cart and what it costs (the AI's own words
        // rarely include prices), unless the reply is a question, the menu, or the checkout taking over.
        if (($cartResult['applied'] ?? 0) > 0 && $refusal === null && ($cartResult['rounded'] ?? []) === []
            && ! in_array($output->recommendedAction, [RecommendedAction::PlaceOrder, RecommendedAction::CancelOrder, RecommendedAction::TrackOrder, RecommendedAction::Handoff,
                RecommendedAction::Checkout, RecommendedAction::ShowMenu, RecommendedAction::CloseConversation], true)) {
            $reply = $this->withCartRecap($reply, $cart, in_array($language, ['en', 'fr', 'ar'], true) ? $language : ($conversation->customer->locale ?? 'en'));
        }
        // A weight the cart cut to a quarter kilo: the customer is told the amount really in the cart, not the one asked.
        if (($cartResult['rounded'] ?? []) !== [] && $output->recommendedAction !== RecommendedAction::PlaceOrder) {
            $line = $cartResult['rounded'][0];
            $reply = __('whatsapp.cart.rounded', ['item' => Quantity::line($line['quantity'], $line['unit'], $line['name'])], in_array($language, ['en', 'fr', 'ar'], true) ? $language : ($conversation->customer->locale ?? 'en'));
        }

        $now = now()->toIso8601String();
        $messages = $state['messages'] ?? [];
        $messages[] = ['role' => 'customer', 'content' => $message, 'at' => $now];
        $messages[] = ['role' => 'assistant', 'content' => $reply, 'at' => $now];

        $conversation->update([
            'state' => [
                'location' => $state['location'] ?? null,
                'messages' => array_slice($messages, -config('ai.conversation.stored_messages', 100)),
                'phase' => $order ? 'completed' : ($output->conversationState['phase'] ?? ($state['phase'] ?? 'idle')),
                'last_ai_output' => $output->toArray(),
            ],
            'last_message_at' => now(),
        ]);

        $conversation->refresh()->load(['customer', 'cart.items.product', 'cart.items.variant']);

        return [
            'output' => $output,
            // The customer must pick Delivery or Pickup: the caller shows those buttons.
            'fulfillment_choice' => $this->fulfillmentMissing,
            // The order the customer must confirm cancelling (buttons are sent for it), or null.
            'cancel_confirm' => $this->cancelAwaitingId,
            'cart_result' => $cartResult,
            'conversation' => $conversation,
            'reply' => $reply,
            'order' => $order,
            // First answer in a conversation, or the customer asked for the menu: attach the menu list.
            // The menu is written out for a greeting (the first words of a chat) or when the customer asks for it,
            // never under a real answer such as a price.
            'menu_link' => ! $order
                && ($output->recommendedAction === RecommendedAction::ShowMenu
                    || (($state['messages'] ?? []) === [] && $output->intent === ConversationIntent::Greeting)),
        ];
    }

    public function findConversation(string $id): Conversation
    {
        $this->requireTenant();

        return Conversation::with(['customer', 'cart.items.product', 'cart.items.variant'])->findOrFail($id);
    }

    /**
     * @return array{0: string, 1: ?Order}
     */
    /** The assistant sometimes adds an item and clears the cart in the same turn, which empties what it just added. */
    private function withoutClearAfterAdd(array $actions): array
    {
        $firstAdd = collect($actions)->search(fn ($a) => $a->action === 'add');

        return $firstAdd === false ? $actions
            : array_values(array_filter($actions, fn ($a, $i) => ! ($a->action === 'clear' && $i > $firstAdd), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * What to say when the cart refused the first item the assistant tried to add: the choices of a size or of a
     * required extra written out, "no longer available", or the wait for the team's check. Null when nothing was
     * refused (or the refusal is not one the customer can fix by choosing).
     *
     * @param  array<string, mixed>  $cartResult
     */
    private function refusalReply(Conversation $conversation, array $cartResult, string $locale): ?string
    {
        $refused = collect($cartResult['rejected'] ?? [])->first(fn ($r) => ($r['action'] ?? null) === 'add' && ! empty($r['product_id']));
        $product = $refused ? Product::with(['variants', 'modifierGroups.modifiers'])->find($refused['product_id']) : null;
        if (! $product) {
            return null;
        }
        $merchant = app(CurrentTenant::class)->get();

        if (! $product->isOrderable()) {
            return __('whatsapp.checkout.unavailable', ['item' => $product->name], $locale);
        }
        if ($product->staff_check && ! $conversation->customer?->isApproved($product->id)) {
            return app(ChatMenu::class)->checkRequest($conversation, $locale, $product)['text'];
        }

        $reason = (string) ($refused['reason'] ?? '');
        $variants = $product->variants->filter(fn ($v) => $v->isOffered())->values();
        if ($variants->isNotEmpty() && str_contains($reason, 'has sizes')) {
            $options = $variants->map(fn ($v) => $v->name.' ('.Money::format((float) ($v->price ?? $product->price), $merchant->currency).')')->implode(', ');

            return __('whatsapp.cart.choose_size', ['item' => $product->name, 'options' => $options], $locale);
        }
        if (preg_match('/^Option "(.+)" is required/', $reason, $m)
            && ($group = $product->modifierGroups->firstWhere('name', $m[1]))) {
            $options = $group->modifiers->filter(fn ($x) => ($x->is_active ?? true) && ($x->is_available ?? true))
                ->map(fn ($x) => $x->name.((float) $x->price_delta > 0 ? ' (+'.Money::format((float) $x->price_delta, $merchant->currency).')' : ''))->implode(', ');

            return __('whatsapp.cart.choose_option', ['item' => $product->name, 'group' => $group->name, 'options' => $options], $locale);
        }

        return null;
    }

    private function checkout(Conversation $conversation, StructuredTurnOutput $output): array
    {
        $this->fulfillmentMissing = false;
        $location = ($conversation->state ?? [])['location'] ?? null;

        $details = $output->checkout ?? [];
        $details['location'] = $location ?? ($details['location'] ?? null);

        [$reply, $order] = $this->placeOrderFromDetails($conversation, $details);

        // A typed order is remembered like a button one, so the next checkout offers this address.
        if ($order && $order->fulfillment === 'delivery') {
            $conversation->customer->rememberAddress((string) $order->delivery_zone_name, (string) $order->delivery_address, $location);
        }

        return [$reply, $order];
    }

    /**
     * Places the order for the conversation's cart (AI checkout or the button checkout) and
     * returns the customer reply and the order (null when the checkout was refused).
     *
     * @param  array<string, mixed>  $details  see CheckoutService::placeOrder()
     * @return array{0: string, 1: Order|null}
     */
    public function placeOrderFromDetails(Conversation $conversation, array $details): array
    {
        $merchant = app(CurrentTenant::class)->get();
        $locale = $conversation->customer->locale ?? 'en';

        try {
            $order = $this->checkoutService->placeOrder($merchant, $conversation, $details);
        } catch (CheckoutException $e) {
            $this->fulfillmentMissing = $e->key === 'fulfillment_required';

            return [$e->customerMessage($locale), null];
        }

        // Whish without a shop to accept first: the payment instructions go out now.
        if ($order->status === 'pending_payment') {
            $this->confirmation->startWhishTransfer($merchant, $order);

            return ['', $order->fresh()];
        }

        $this->confirmation->afterPlaced($merchant, $order);
        $order = $order->fresh();
        $key = match (true) {
            $order->status === 'placed' => 'whatsapp.checkout.placed_waiting',
            $order->fulfillment === 'pickup' => 'whatsapp.checkout.placed_pickup',
            default => 'whatsapp.checkout.placed',
        };

        return [__($key, [
            'address' => ShopSettings::for($merchant)->pickupAddress() ?: $merchant->name,
            'number' => $order->number,
            'total' => Money::format((float) $order->total, $merchant->currency),
            'currency' => $merchant->currency,
            'lbp' => match ($order->payment_currency) {
                'LBP' => __('whatsapp.checkout.lbp', ['amount' => number_format((float) $order->total_lbp)], $locale),
                'MIXED' => __('whatsapp.checkout.split', ['payment' => OrderMessage::paymentLine($order, $merchant)], $locale),
                default => '',
            },
            'eta' => $order->eta_mins,
            'when' => $order->status === 'placed' ? '' : app(OrderTiming::class)->phrase($merchant, $order, $locale),
            'shop' => $merchant->name,
        ], $locale).($order->payment_method === 'whish' ? "\n".__('whatsapp.checkout.whish_after_accept', [], $locale) : ''), $order];
    }

    /**
     * System messages (confirmations, status updates) follow the language the customer writes in.
     */
    private function rememberLanguage(Conversation $conversation, StructuredTurnOutput $output, ?string $detected = null): void
    {
        // The language read from the customer's own words wins; the AI's guess covers the rest (Arabizi, unclear).
        // Arabizi is written in Latin letters, so the bot's own buttons and messages are in English.
        $lang = match (true) {
            in_array($detected, ['en', 'ar', 'fr'], true) => $detected,
            $detected === 'arabizi' => 'en',
            default => strtolower(substr((string) ($output->conversationState['language'] ?? ''), 0, 2)),
        };
        if (in_array($lang, ['en', 'ar', 'fr'], true) && $conversation->customer->locale !== $lang) {
            $conversation->customer->forceFill(['locale' => $lang])->save();
        }
    }

    /** "Where's my order?" asked in words: the real status of the customer's latest order (the assistant never guesses it). */
    private function orderStatus(Conversation $conversation): string
    {
        $merchant = app(CurrentTenant::class)->get();
        $locale = $conversation->customer->locale ?? 'en';
        $order = Order::query()->where('customer_id', $conversation->customer_id)->latest()->first();

        return $order
            ? app(ChatMenu::class)->statusText($merchant, $order, $locale)
            : __('whatsapp.track.none', [], $locale);
    }

    private function cancelLatestOrder(Conversation $conversation): string
    {
        $merchant = app(CurrentTenant::class)->get();
        $locale = $conversation->customer->locale ?? 'en';

        $order = Order::query()
            ->where('customer_id', $conversation->customer_id)
            ->whereIn('status', ['pending_payment', 'placed', 'confirmed', 'preparing', 'ready', 'assigned', 'picked_up'])
            ->latest()
            ->first();

        if (! $order) {
            return __('whatsapp.checkout.cancel_none', [], $locale);
        }

        if ($order->status === 'picked_up') {
            return __('whatsapp.checkout.cancel_too_late', ['number' => $order->number], $locale);
        }

        // Already being prepared: cancelling now could waste food, so the shop decides.
        if (! in_array($order->status, ['pending_payment', 'placed', 'confirmed'], true)) {
            $this->handoff($conversation);

            return __('whatsapp.checkout.cancel_ask_shop', ['number' => $order->number, 'shop' => $merchant->name], $locale);
        }

        // The status notification job tells the customer "your order was cancelled".
        // Confirmed by button, not by the assistant: its reading of "yes" is not reliable enough to cancel on.
        $this->cancelAwaitingId = $order->id;

        return __('whatsapp.checkout.cancel_confirm', ['number' => $order->number], $locale);
    }

    /**
     * The open order a cancel request is about (placed, accepted or pending payment), or null. A
     * request is recognised by its words here, so the buttons never depend on the assistant's wording.
     */
    public function openOrderToCancel(Conversation $conversation, string $text): ?Order
    {
        if (! preg_match('/\b(cancel|annul\w*|cancell?ed)\b|إلغاء|الغاء|ألغي|الغي|annuler/iu', $text)) {
            return null;
        }

        return Order::query()
            ->where('customer_id', $conversation->customer_id)
            ->whereIn('status', ['pending_payment', 'placed', 'confirmed'])
            ->latest()
            ->first();
    }

    /**
     * The customer tapped Yes or No under "cancel order #N?". Yes cancels an open order; anything
     * else keeps it. The status notification job tells the customer about a cancellation.
     */
    public function resolveCancelButton(Conversation $conversation, string $orderId, bool $yes): string
    {
        $merchant = app(CurrentTenant::class)->get();
        $locale = $conversation->customer->locale ?? 'en';

        $order = Order::query()->where('customer_id', $conversation->customer_id)->whereKey($orderId)->first();
        if (! $order || ! in_array($order->status, ['pending_payment', 'placed', 'confirmed'], true)) {
            return __('whatsapp.checkout.cancel_none', [], $locale);
        }
        if (! $yes) {
            return __('whatsapp.checkout.cancel_kept', ['number' => $order->number], $locale);
        }

        try {
            $this->orders->transition($merchant, $order->id, 'cancel', ['reason' => null, 'by' => 'customer']);
        } catch (ValidationException) {
            // The shop closed it at the same moment.
            return __('whatsapp.checkout.cancel_none', [], $locale);
        }

        return '';
    }

    /**
     * The customer wants a person: the bot steps back in this chat until the merchant hands it
     * back (or the pause expires), the merchant sees the request in his WhatsApp Business app.
     */
    /**
     * The server ignores a price the customer made up, but the AI's reply could still say it was accepted.
     * Such a reply is replaced by the real cart.
     */
    private function withoutMadeUpPrice(string $reply, string $message, ConversationTurnInput $input, $cart, ?string $language, Conversation $conversation): string
    {
        $items = $cart->items()->with('product')->get();
        $total = (float) $items->sum('line_total');
        $known = [json_decode(json_encode([$input->catalog, $input->cartLines, $input->shop]), true)];
        if (! ReplyFigures::repeatsMadeUpAmount($reply, $message, $known, $total)) {
            return $reply;
        }

        $locale = in_array($language, ['en', 'fr', 'ar'], true) ? $language : ($conversation->customer->locale ?? 'en');
        $text = __('whatsapp.cart.price_fixed', [], $locale);
        if ($items->isNotEmpty()) {
            $currency = app(CurrentTenant::class)->get()?->currency;
            $text .= "\n".__('whatsapp.cart.your_cart', [], $locale);
            foreach ($items as $item) {
                $text .= "\n• ".Quantity::line($item->quantity, $item->product?->unit, (string) ($item->product?->name ?? '')).' — '.Money::format((float) $item->line_total, $currency);
            }
        }

        return $text;
    }

    /** The reply followed by the cart lines with their prices and the subtotal. */
    private function withCartRecap(string $reply, $cart, string $locale): string
    {
        $items = $cart->items()->with(['product', 'variant'])->get();
        if ($items->isEmpty()) {
            return $reply;
        }
        $currency = app(CurrentTenant::class)->get()?->currency;
        $total = (float) $items->sum('line_total');
        foreach ([$total, ...$items->map(fn ($i) => (float) $i->line_total)->all()] as $amount) {
            if ($reply !== '' && str_contains($reply, Money::format($amount, $currency))) {
                return $reply; // the assistant already gave the prices
            }
        }
        $lines = $items->map(fn ($item) => '• '.Quantity::line($item->quantity, $item->product?->unit, trim((string) ($item->product?->name ?? '').($item->variant ? ' ('.$item->variant->name.')' : ''))).' — '.Money::format((float) $item->line_total, $currency))->implode("\n");

        return trim($reply."\n\n".__('whatsapp.cart.your_cart', [], $locale)."\n".$lines."\n".__('whatsapp.flow.subtotal', [], $locale).': '.Money::format($total, $currency));
    }

    private function handoff(Conversation $conversation): string
    {
        $merchant = app(CurrentTenant::class)->get();
        $conversation->customer->forceFill([
            'bot_paused_until' => now()->addMinutes(ShopSettings::for($merchant)->botPauseMins()),
        ])->save();

        return __('whatsapp.checkout.handoff', ['shop' => $merchant->name], $conversation->customer->locale ?? 'en');
    }

    private function isStale(Conversation $conversation): bool
    {
        $last = $conversation->last_message_at ?? $conversation->created_at;

        return $last !== null && $last->lt(now()->subHours(config('ai.conversation.idle_hours', 24)));
    }

    /**
     * What the AI needs to know about the shop it is selling for.
     *
     * @return array<string, mixed>
     */
    private function shopContext(): array
    {
        $merchant = app(CurrentTenant::class)->get();
        if (! $merchant) {
            return [];
        }

        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        return [
            'name' => $merchant->name,
            'business_type' => $merchant->business_type,
            'currency' => $merchant->currency,
            'timezone' => $merchant->timezone,
            'lbp_rate' => ShopSettings::for($merchant)->lbpRate(),
            'fulfillment' => ShopSettings::for($merchant)->fulfillment(),
            'whish_enabled' => ShopSettings::for($merchant)->whishNumber() !== null,
            'now' => now()->setTimezone($merchant->timezone ?: config('app.timezone'))->format('Y-m-d H:i l'),
            'pickup_address' => ShopSettings::for($merchant)->pickupAddress(),
            'opening_hours_today' => $this->todaysHours($merchant),
            'opening_hours' => ShopHour::week()
                ->map(fn (ShopHour $h) => [
                    'day' => $days[$h->day_of_week] ?? (string) $h->day_of_week,
                    'hours' => $h->is_closed ? 'closed' : "{$h->opens_at}-{$h->closes_at}",
                ])->values()->all(),
            // Big shops: the catalog holds only the items matching the conversation.
            'catalog_is_partial' => $this->catalogResolver->isLarge(),
            'menu_categories' => $this->catalogResolver->isLarge() ? Category::query()->orderBy('sort_order')->pluck('name')->all() : [],
            'delivery_zones' => DeliveryZone::query()->where('is_active', true)->orderBy('sort_order')->get()
                ->map(fn (DeliveryZone $z) => [
                    'name' => $z->name,
                    'delivery_fee' => (string) $z->delivery_fee,
                    'minimum_order' => (string) $z->minimum_order,
                ])->values()->all(),
        ];
    }

    /** "09:00-23:00" or "closed", for the shop's own today (the model mixed up weekdays). */
    private function todaysHours(Merchant $merchant): string
    {
        $today = ShopHour::week()->get(now()->setTimezone($merchant->timezone ?: config('app.timezone'))->dayOfWeek);

        return ! $today || $today->is_closed ? 'closed' : "{$today->opens_at}-{$today->closes_at}";
    }

    /**
     * What the customer is talking about: this message and their last few ones (so "make it
     * two" still finds the item from the message before).
     *
     * @param  list<array{role: string, content: string}>  $history
     */
    private function searchText(string $message, array $history): string
    {
        $recent = collect($history)->where('role', 'customer')->take(-3)->pluck('content');

        return $recent->push($message)->implode(' ');
    }

    private function requireTenant(): void
    {
        if (! app(CurrentTenant::class)->check()) {
            throw new InvalidArgumentException('A tenant context is required for conversation operations.');
        }
    }
}
