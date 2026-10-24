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
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopHour;
use App\Services\AI\CatalogResolver;
use App\Support\CheckoutException;
use App\Support\Conversation\CartStatus;
use App\Support\Conversation\ConversationStatus;
use App\Support\Money;
use App\Support\ShopSettings;
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

        if (! $this->isOpen($merchant)) {
            throw new CheckoutException('shop_closed', ['shop' => $merchant->name]);
        }

        $location = $details['location'] ?? null;
        $hasPin = is_array($location) && is_numeric($location['lat'] ?? null) && is_numeric($location['lng'] ?? null);
        $address = trim((string) ($details['delivery_address'] ?? ''));
        $fulfillment = $this->fulfillment($merchant, (string) ($details['fulfillment'] ?? ''), $hasPin || $address !== '');

        $zone = null;
        if ($fulfillment === 'delivery') {
            $zone = $this->resolveZone($details['delivery_zone'] ?? null);

            // A shared WhatsApp location pin is enough on its own; otherwise a written address is required.
            if (! $hasPin && mb_strlen($address) < 5) {
                throw new CheckoutException('address_required');
            }
        } else {
            $hasPin = false;
            $address = '';
        }

        foreach ($cart->items as $item) {
            $product = $item->product;
            if (! $product || ! $product->isOrderable() || (float) $item->line_total < 0.01
                || ($product->track_inventory && ($product->stock_quantity ?? 0) < $item->quantity)
                || ! app(CatalogResolver::class)->choicesStillOffered($product, $item->variant_id, $item->modifier_ids ?? [])
                || ($item->variant && ! $item->variant->hasStockFor($product, $item->quantity))
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
        if ($payment === 'whish' && ! app(WhishPayService::class)->enabled($merchant)) {
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

        $waitForShop = app(OrderConfirmation::class)->needsShopAcceptance($merchant);
        $extra = [
            'delivery_lat' => $hasPin ? (float) $location['lat'] : null,
            'delivery_lng' => $hasPin ? (float) $location['lng'] : null,
            'payment_currency' => $split ? 'MIXED' : ($inLbp ? 'LBP' : $merchant->currency),
            'cash_main' => $split ? $cashMain : null,
            'exchange_rate' => ($inLbp || $split) ? $shop->lbpRate() : null,
            'total_lbp' => $split ? $shop->toLbp($total - $cashMain) : ($inLbp ? $shop->toLbp($total) : null),
            // A Whish order is always accepted by the shop first ('placed') and gets its payment link
            // only then, so a refused order never has to be refunded: by staff with the Accept
            // button, or by whoever holds the shop phone typing ✅ in the customer's chat. Cash orders
            // wait for staff when the shop has some; otherwise the bot accepts them.
            'status' => $waitForShop || $payment === 'whish' ? 'placed' : 'confirmed',
            'payment_status' => $payment === 'whish' ? 'pending' : 'cash',
            'fulfillment' => $fulfillment,
            'accepted_at' => $payment === 'whish' || $waitForShop ? null : now(),
            'eta_mins' => $shop->prepTimeMins(),
        ];

        return DB::transaction(function () use ($merchant, $conversation, $cart, $zone, $address, $payment, $subtotal, $deliveryFee, $notes, $extra) {
            $number = (int) Order::query()->lockForUpdate()->max('number') + 1;

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
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'item_total' => $item->line_total,
                    'modifiers' => collect($item->modifier_ids ?? [])->map(fn ($id) => $modifierNames[$id] ?? null)->filter()->values()->all(),
                ]);

                // Only take stock that is still there: a parallel checkout may have taken the last
                // one since the check above. Throwing here rolls the whole order back.
                if ($item->product->track_inventory) {
                    $taken = Product::query()->whereKey($item->product_id)
                        ->where('stock_quantity', '>=', $item->quantity)
                        ->decrement('stock_quantity', $item->quantity);
                    if ($taken === 0) {
                        throw new CheckoutException('unavailable', ['item' => $item->product->name]);
                    }
                    // A size with its own stock number: the same conditional take, for that size.
                    if ($item->variant_id !== null && $item->variant?->stock_quantity !== null) {
                        $sizeTaken = ProductVariant::query()->whereKey($item->variant_id)
                            ->where('stock_quantity', '>=', $item->quantity)
                            ->decrement('stock_quantity', $item->quantity);
                        if ($sizeTaken === 0) {
                            throw new CheckoutException('unavailable', ['item' => $this->lineName($item)]);
                        }
                    }
                }
            }

            $cart->update(['status' => CartStatus::Converted]);
            $conversation->update(['status' => ConversationStatus::Closed]);

            // One approval (prescription seen), one order.
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
        });
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
