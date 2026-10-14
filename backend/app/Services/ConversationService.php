<?php

namespace App\Services;

use App\Jobs\NotifyStaffOfHandoff;
use App\Models\Category;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShopHour;
use App\Services\AI\CatalogResolver;
use App\Services\AI\Contracts\ChatProvider;
use App\Services\AI\DTO\ConversationTurnInput;
use App\Services\AI\DTO\StructuredTurnOutput;
use App\Support\CheckoutException;
use App\Support\Conversation\CartStatus;
use App\Support\Conversation\ConversationStatus;
use App\Support\Conversation\RecommendedAction;
use App\Support\CurrentTenant;
use App\Support\OrderMessage;
use App\Support\ShopSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Orchestrates AI conversation turns: loads context, calls the ChatProvider,
 * applies cart mutations, and persists conversation state.
 */
class ConversationService
{
    public function __construct(
        protected ChatProvider $chatProvider,
        protected CartService $cartService,
        protected CatalogResolver $catalogResolver,
        protected CheckoutService $checkoutService,
        protected DriverDispatcher $dispatcher,
        protected OrderService $orders,
        protected OrderConfirmation $confirmation,
        protected WhishPayService $whish,
        protected AppointmentService $appointments,
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

        $cartResult = $this->cartService->applyActions($cart, $output->cartActions);

        $this->rememberLanguage($conversation, $output);

        [$reply, $order] = match ($output->recommendedAction) {
            RecommendedAction::PlaceOrder => $this->checkout($conversation, $output),
            RecommendedAction::CancelOrder => [$this->cancelLatestOrder($conversation), null],
            RecommendedAction::Handoff => [$this->handoff($conversation), null],
            RecommendedAction::CheckAvailability => [$this->availability($conversation, $output->appointment ?? []), null],
            RecommendedAction::BookAppointment => [$this->bookAppointment($conversation, $output->appointment ?? []), null],
            RecommendedAction::CancelAppointment => [$this->cancelAppointment($conversation), null],
            default => [$output->assistantMessage, null],
        };

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
            'cart_result' => $cartResult,
            'conversation' => $conversation,
            'reply' => $reply,
            'order' => $order,
            // First answer in a conversation, or the customer asked for the menu: attach the menu list.
            'menu_link' => ! $order && ShopSettings::for(app(CurrentTenant::class)->get())->businessMode() !== 'appointments'
                && ($output->recommendedAction === RecommendedAction::ShowMenu || ($state['messages'] ?? []) === []),
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
    private function checkout(Conversation $conversation, StructuredTurnOutput $output): array
    {
        return $this->placeOrderFromDetails($conversation, ($output->checkout ?? []) + ['location' => ($conversation->state ?? [])['location'] ?? null]);
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
            return [$e->customerMessage($locale), null];
        }

        // Whish without a shop to accept first: the payment link goes out now.
        if ($order->status === 'pending_payment') {
            return [$this->confirmation->startWhishPayment($merchant, $order, $locale), $order->fresh()];
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
            'total' => number_format((float) $order->total, 2),
            'currency' => $merchant->currency,
            'lbp' => match ($order->payment_currency) {
                'LBP' => __('whatsapp.checkout.lbp', ['amount' => number_format((float) $order->total_lbp)], $locale),
                'MIXED' => __('whatsapp.checkout.split', ['payment' => OrderMessage::paymentLine($order, $merchant)], $locale),
                default => '',
            },
            'eta' => $order->eta_mins,
            'when' => $order->status === 'placed' ? '' : app(OrderTiming::class)->phrase($merchant, $order, $locale),
            'shop' => $merchant->name,
        ], $locale).($order->payment_method === 'whish' ? "\n".__('whatsapp.checkout.whish_after_accept', [], $locale) : '')
            .($order->status === 'placed' && ! $this->confirmation->needsShopAcceptance($merchant)
                ? "\n".__('whatsapp.checkout.shop_reply', ['shop' => $merchant->name], $locale) : ''), $order];
    }

    /**
     * System messages (confirmations, status updates) follow the language the customer writes in.
     */
    private function rememberLanguage(Conversation $conversation, StructuredTurnOutput $output): void
    {
        $lang = strtolower(substr((string) ($output->conversationState['language'] ?? ''), 0, 2));
        if (in_array($lang, ['en', 'ar', 'fr'], true) && $conversation->customer->locale !== $lang) {
            $conversation->customer->forceFill(['locale' => $lang])->save();
        }
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

        // The status notification job tells the customer "your order was cancelled".
        $this->orders->transition($merchant, $order->id, 'cancel', ['reason' => null, 'by' => 'customer']);

        return '';
    }

    /**
     * The customer wants a person: the bot steps back in this chat until the merchant hands it
     * back (or the pause expires), the merchant sees the request in his WhatsApp Business app.
     */
    private function handoff(Conversation $conversation): string
    {
        $merchant = app(CurrentTenant::class)->get();
        $conversation->customer->forceFill([
            'bot_paused_until' => now()->addMinutes(ShopSettings::for($merchant)->botPauseMins()),
        ])->save();
        NotifyStaffOfHandoff::dispatch($conversation->customer_id)->afterCommit();

        return __('whatsapp.checkout.handoff', ['shop' => $merchant->name], $conversation->customer->locale ?? 'en');
    }

    /**
     * @param  array<string, mixed>  $req  service_id, date
     */
    private function availability(Conversation $conversation, array $req): string
    {
        $merchant = app(CurrentTenant::class)->get();
        $locale = $conversation->customer->locale ?? 'en';
        $service = $this->resolveService($req['service_id'] ?? null);
        if (! $service) {
            return $this->askService($locale);
        }

        $tz = $this->appointments->tz($merchant);
        $date = $this->localDate($req['date'] ?? null, $tz);
        $slots = $this->appointments->availableSlots($merchant, $service, $date);

        if ($slots !== []) {
            return __('whatsapp.appointment.slots', [
                'service' => $service->name, 'date' => $date->format('D j M'), 'slots' => implode(', ', array_slice($slots, 0, 24)),
            ], $locale);
        }

        $next = $this->appointments->nextAvailableDay($merchant, $service, $date->copy()->addDay());

        return $next
            ? __('whatsapp.appointment.none_that_day', [
                'service' => $service->name, 'date' => $date->format('D j M'),
                'next' => $next[0]->format('D j M'), 'slots' => implode(', ', array_slice($next[1], 0, 24)),
            ], $locale)
            : __('whatsapp.appointment.fully_booked', ['service' => $service->name], $locale);
    }

    /**
     * @param  array<string, mixed>  $req  service_id, date, time
     */
    private function bookAppointment(Conversation $conversation, array $req): string
    {
        $merchant = app(CurrentTenant::class)->get();
        $locale = $conversation->customer->locale ?? 'en';
        $service = $this->resolveService($req['service_id'] ?? null);
        if (! $service) {
            return $this->askService($locale);
        }

        $tz = $this->appointments->tz($merchant);
        $date = $this->localDate($req['date'] ?? null, $tz);
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', (string) ($req['time'] ?? ''), $m)) {
            return $this->availability($conversation, $req);
        }

        $appointment = $this->appointments->book($merchant, $conversation->customer, $service, $date->copy()->setTime((int) $m[1], (int) $m[2]));
        if (! $appointment) {
            return __('whatsapp.appointment.taken', [], $locale)."\n".$this->availability($conversation, $req);
        }

        $local = $appointment->starts_at->copy()->setTimezone($tz);

        return __('whatsapp.appointment.booked', [
            'service' => $appointment->service_name, 'date' => $local->format('D j M'), 'time' => $local->format('H:i'),
            'price' => number_format((float) $appointment->price, 2).' '.$merchant->currency, 'shop' => $merchant->name,
            'address' => ShopSettings::for($merchant)->pickupAddress() ?: $merchant->name,
        ], $locale);
    }

    private function cancelAppointment(Conversation $conversation): string
    {
        $locale = $conversation->customer->locale ?? 'en';
        $appointment = $this->appointments->upcomingFor($conversation->customer);
        if (! $appointment) {
            return __('whatsapp.appointment.cancel_none', [], $locale);
        }

        $appointment->forceFill(['status' => 'cancelled', 'cancelled_by' => 'customer'])->save();
        $local = $appointment->starts_at->copy()->setTimezone($this->appointments->tz(app(CurrentTenant::class)->get()));

        return __('whatsapp.appointment.cancelled', ['service' => $appointment->service_name, 'date' => $local->format('D j M'), 'time' => $local->format('H:i')], $locale);
    }

    private function resolveService(?string $id): ?Product
    {
        $services = $this->appointments->services();

        return ($id ? $services->firstWhere('id', $id) : null) ?? ($services->count() === 1 ? $services->first() : null);
    }

    private function askService(string $locale): string
    {
        return __('whatsapp.appointment.which_service', [
            'services' => $this->appointments->services()->map(fn ($p) => "{$p->name} ({$p->duration_mins} min)")->implode(', '),
        ], $locale);
    }

    private function localDate(?string $date, string $tz): Carbon
    {
        $today = now()->setTimezone($tz)->startOfDay();
        try {
            $parsed = $date ? Carbon::createFromFormat('Y-m-d', $date, $tz)->startOfDay() : $today;
        } catch (\Throwable) {
            $parsed = $today;
        }

        return $parsed->lt($today) ? $today : $parsed;
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
            'whish_enabled' => $this->whish->enabled($merchant),
            'business_mode' => ShopSettings::for($merchant)->businessMode(),
            'now' => now()->setTimezone($merchant->timezone ?: config('app.timezone'))->format('Y-m-d H:i l'),
            'services' => ShopSettings::for($merchant)->businessMode() === 'orders' ? [] : $this->appointments->services()
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'duration_mins' => $p->duration_mins, 'price' => (string) $p->price])
                ->values()->all(),
            'pickup_address' => ShopSettings::for($merchant)->pickupAddress(),
            'opening_hours' => ShopHour::query()->orderBy('day_of_week')->get()
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
