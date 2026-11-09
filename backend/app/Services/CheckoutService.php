<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Merchant;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ShopHour;
use App\Services\AI\CatalogResolver;
use App\Support\CheckoutException;
use App\Support\Conversation\CartStatus;
use App\Support\Conversation\ConversationStatus;
use App\Support\DeliverySchedule;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\ShopSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns a conversation's cart into an order. Everything the AI supplies is re-validated here:
 * the AI decides *when* to check out, never prices, availability, fees or minimums.
 */
class CheckoutService
{
    public const PAYMENT_METHODS = ['cash', 'whish'];

    /**
     * Orders taken by the bot are accepted straight away ("confirmed") with the shop's prep time.
     *
     * @param  array<string, mixed>  $details  delivery_zone, delivery_address, payment_method, payment_currency,
     *                                         notes, location (['lat' => …, 'lng' => …] from a WhatsApp pin)
     *
     * @throws CheckoutException with a customer-facing translation key
     */
    public function placeOrder(Merchant $merchant, Conversation $conversation, array $details): Order
    {
        /** @var Cart|null $cart */
        $cart = $conversation->cart()->with(['items.product', 'items.variant'])->first();

        if (! $cart || $cart->items->isEmpty()) {
            throw new CheckoutException('empty_cart');
        }

        // The customer pays today's price, even if it changed since the item went into the cart.
        app(CartService::class)->refreshPrices($cart);
        $cart->load(['items.product', 'items.variant']);

        // A time the customer picked (florists, cakes) must be one of the shop's own windows; it can be
        // chosen while the shop is closed now, for when it is open.
        $deliverAt = filled($details['deliver_at'] ?? null)
            ? (DeliverySchedule::parse($merchant, (string) $details['deliver_at']) ?? throw new CheckoutException('schedule_invalid'))
            : null;

        if (! $deliverAt && ! $this->isOpen($merchant)) {
            throw new CheckoutException('shop_closed', ['shop' => $merchant->name]);
        }

        $location = $details['location'] ?? null;
        $hasPin = is_array($location) && is_numeric($location['lat'] ?? null) && is_numeric($location['lng'] ?? null);
        $address = trim((string) ($details['delivery_address'] ?? ''));
        $fulfillment = $this->fulfillment($merchant, (string) ($details['fulfillment'] ?? ''), $hasPin || $address !== '');

        $zone = null;
        if ($fulfillment === 'delivery') {
            $zone = $this->resolveZone($details['delivery_zone'] ?? null);

            // Delivery needs the customer's WhatsApp location pin; the written address is only the note for the driver.
            if (! $hasPin) {
                throw new CheckoutException('address_required');
            }
        } else {
            $hasPin = false;
            $address = '';
        }

        foreach ($cart->items as $item) {
            $product = $item->product;
            if (! $product || ! $product->isOrderable() || (float) $item->line_total < 0.01
                || ! app(CatalogResolver::class)->choicesStillOffered($product, $item->variant_id, $item->modifier_ids ?? [])
                || ($product->staff_check && ! $conversation->customer?->isApproved($product->id))) {
                throw new CheckoutException('unavailable', ['item' => $product?->name ?? '?']);
            }
        }

        $subtotal = (float) $cart->items->sum('line_total');
        if ($zone && $subtotal < (float) $zone->minimum_order) {
            throw new CheckoutException('minimum_order', [
                'zone' => $zone->name,
                'minimum' => Money::format((float) $zone->minimum_order, $merchant->currency),
                'currency' => $merchant->currency,
            ]);
        }

        $payment = strtolower(trim((string) ($details['payment_method'] ?? 'cash')));
        $payment = $payment === 'wish' ? 'whish' : $payment;
        if (! in_array($payment, self::PAYMENT_METHODS, true)) {
            $payment = 'cash';
        }
        if ($payment === 'whish' && ShopSettings::for($merchant)->whishNumber() === null) {
            throw new CheckoutException('whish_unavailable', ['shop' => $merchant->name]);
        }

        $deliveryFee = $zone ? (float) $zone->delivery_fee : 0.0;
        $notes = trim((string) ($details['notes'] ?? '')) ?: null;
        $shop = ShopSettings::for($merchant);

        // Paying in LBP is only possible once the merchant has set an exchange rate.
        // Cash can be paid in the shop currency, in LBP, or split (part shop currency, rest LBP).
        $total = $subtotal + $deliveryFee;
        $currency = strtoupper(trim((string) ($details['payment_currency'] ?? '')));
        $lbpOk = $shop->lbpRate() !== null && strtoupper((string) $merchant->currency) !== 'LBP';
        $cashMain = is_numeric($details['cash_main'] ?? null) ? round(max(0, (float) $details['cash_main']), 2) : null;
        $split = $payment === 'cash' && $currency === 'MIXED' && $lbpOk && $cashMain !== null && $cashMain > 0 && $cashMain < $total;
        $inLbp = $currency === 'LBP' && $lbpOk;

        // The assistant accepts orders itself unless the shop chose to answer each one (✅ typed in the customer's chat).
        $waitForShop = ! $shop->autoAccept();
        $extra = [
            'delivery_lat' => $hasPin ? (float) $location['lat'] : null,
            'delivery_lng' => $hasPin ? (float) $location['lng'] : null,
            'payment_currency' => $split ? 'MIXED' : ($inLbp ? 'LBP' : $merchant->currency),
            'cash_main' => $split ? $cashMain : null,
            'exchange_rate' => ($inLbp || $split) ? $shop->lbpRate() : null,
            'total_lbp' => $split ? $shop->toLbp($total - $cashMain) : ($inLbp ? $shop->toLbp($total) : null),
            // A shop that answers each order gets it as 'placed'; a Whish order then gets its payment instructions
            // only once accepted, so a refused order never has to be refunded. Otherwise the assistant accepts it now.
            'status' => $waitForShop ? 'placed' : ($payment === 'whish' ? 'pending_payment' : 'confirmed'),
            'payment_status' => $payment === 'whish' ? 'pending' : 'cash',
            'fulfillment' => $fulfillment,
            'accepted_at' => $payment === 'whish' || $waitForShop ? null : now(),
            'eta_mins' => $shop->prepTimeMins(),
            'deliver_at' => $deliverAt?->copy()->utc(),
        ];

        // Retried on a deadlock: two checkouts that write the same rows in a different order can still collide.
        return DB::transaction(function () use ($merchant, $conversation, $cart, $zone, $address, $payment, $subtotal, $deliveryFee, $notes, $extra) {
            // One checkout at a time per shop takes the next number. Locking the shop's row (not "max(number) for update", which
            // locks a gap in the index) lets 100 customers confirm together without MySQL deadlocking them against each other.
            // Numbers restart at 1 each trading session (after close → next open).
            Merchant::query()->whereKey($merchant->id)->lockForUpdate()->value('id');
            $since = $this->sessionStartedAt($merchant);
            $number = (int) Order::query()->where('created_at', '>=', $since)->max('number') + 1;

            $order = Order::create([
                'merchant_id' => $merchant->id,
                'number' => $number,
                'customer_id' => $conversation->customer_id,
                'conversation_id' => $conversation->id,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'total' => $subtotal + $deliveryFee,
                'payment_method' => $payment,
                'delivery_zone_id' => $zone?->id,
                'delivery_zone_name' => $zone?->name,
                'delivery_address' => $address !== '' ? $address : null,
                'notes' => $notes,
            ] + $extra);

            $modifierNames = Modifier::query()
                ->whereIn('id', $cart->items->pluck('modifier_ids')->flatten()->filter()->unique()->all())
                ->pluck('name', 'id');

            foreach ($cart->items as $item) {
                OrderItem::create([
                    'merchant_id' => $merchant->id,
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'product_name' => $this->lineName($item),
                    'note' => filled($item->notes) ? (string) $item->notes : null,
                    'quantity' => $item->quantity,
                    'unit' => Quantity::byWeight($item->product) ? $item->product->unit : null,
                    'unit_price' => $item->unit_price,
                    'item_total' => $item->line_total,
                    'modifier_ids' => $item->modifier_ids ?: null,
                    'modifiers' => collect($item->modifier_ids ?? [])->map(fn ($id) => $modifierNames[$id] ?? null)->filter()->values()->all(),
                ]);
            }

            $cart->update(['status' => CartStatus::Converted]);
            $conversation->update(['status' => ConversationStatus::Closed]);

            // One approval, one order.
            foreach ($cart->items as $item) {
                if ($item->product?->staff_check) {
                    $conversation->customer->useApproval($item->product_id);
                }
            }

            Customer::query()->whereKey($conversation->customer_id)->incrementEach(
                ['total_orders' => 1, 'total_spent' => $subtotal + $deliveryFee],
                ['last_order_at' => now()],
            );

            return $order->load('items');
        }, 3);
    }

    /**
     * When the current trading session began (shop timezone → UTC): today's open, or yesterday's
     * open when still inside an overnight closing window. Order numbers count from here and restart
     * after the shop closes and opens again.
     */
    public function sessionStartedAt(Merchant $merchant): Carbon
    {
        $tz = $merchant->timezone ?: config('app.timezone');
        $now = now()->setTimezone($tz);
        $time = $now->format('H:i');
        $hours = ShopHour::week();

        $yesterday = $hours->get($now->copy()->subDay()->dayOfWeek);
        if ($yesterday && ! $yesterday->is_closed) {
            $opens = substr($yesterday->opens_at, 0, 5);
            $closes = substr($yesterday->closes_at, 0, 5);
            if ($closes <= $opens && $time < $closes) {
                return $now->copy()->subDay()->setTimeFromTimeString($opens)->utc();
            }
        }

        $today = $hours->get($now->dayOfWeek);
        if ($today && ! $today->is_closed) {
            $opens = substr($today->opens_at, 0, 5);

            return $now->copy()->setTimeFromTimeString($opens)->utc();
        }

        return $now->copy()->startOfDay()->utc();
    }

    /**
     * Whether the shop accepts orders right now. A shop that never saved hours keeps the default ones.
     */
    public function isOpen(Merchant $merchant): bool
    {
        $hours = ShopHour::week();

        $now = now()->setTimezone($merchant->timezone ?: config('app.timezone'));
        $time = $now->format('H:i');

        $today = $hours->get($now->dayOfWeek);
        if ($today && ! $today->is_closed) {
            $opens = substr($today->opens_at, 0, 5);
            $closes = substr($today->closes_at, 0, 5);
            $overnight = $closes <= $opens;

            if ($overnight ? ($time >= $opens) : ($time >= $opens && $time < $closes)) {
                return true;
            }
        }

        // Still inside yesterday's overnight window (e.g. 18:00–02:00)?
        $yesterday = $hours->get($now->copy()->subDay()->dayOfWeek);
        if ($yesterday && ! $yesterday->is_closed) {
            $opens = substr($yesterday->opens_at, 0, 5);
            $closes = substr($yesterday->closes_at, 0, 5);
            if ($closes <= $opens && $time < $closes) {
                return true;
            }
        }

        return false;
    }

    /**
     * delivery | pickup, from what the shop offers and what the customer chose. With both
     * offered and no choice made, a given address means delivery; otherwise ask.
     */
    private function fulfillment(Merchant $merchant, string $requested, bool $hasAddress): string
    {
        $offered = ShopSettings::for($merchant)->fulfillment();
        if ($offered !== 'both') {
            return $offered;
        }

        $requested = strtolower(trim($requested));
        if (in_array($requested, ['delivery', 'pickup'], true)) {
            return $requested;
        }
        if ($hasAddress) {
            return 'delivery';
        }

        throw new CheckoutException('fulfillment_required');
    }

    private function resolveZone(?string $requested): ?DeliveryZone
    {
        $zones = DeliveryZone::query()->where('is_active', true)->orderBy('sort_order')->get();
        if ($zones->isEmpty()) {
            return null;
        }

        $needle = mb_strtolower(trim((string) $requested));
        $zone = $needle === '' ? null : $zones->first(fn (DeliveryZone $z) => mb_strtolower(trim($z->name)) === $needle);

        if (! $zone) {
            throw new CheckoutException('zone_required', ['zones' => $zones->pluck('name')->implode(', ')]);
        }

        return $zone;
    }

    private function lineName(CartItem $item): string
    {
        $name = $item->product?->name ?? 'Item';
        $name = $item->variant ? "{$name} ({$item->variant->name})" : $name;

        // The customer's note ("no tomato") travels with the item to the shop's ticket.
        return filled($item->notes) ? "{$name} — {$item->notes}" : $name;
    }
}
