<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Conversation;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * What the assistant-led chat still builds in code: the menu written out in words, the "Where's my order?"
 * answer with its button (trk:{orderId}), and the wait for the team's check of an item. Choosing items, sizes
 * and quantities is the assistant's job, checked by CartService.
 */
class ChatMenu
{
    public function __construct(
        protected OrderTiming $timing,
    ) {}

    /**
     * @return array<string, mixed>|null the message to send, or null if the button is not ours
     */
    public function handle(Merchant $merchant, Conversation $conversation, string $locale, string $buttonId): ?array
    {
        if (! str_starts_with($buttonId, 'trk:')) {
            return null;
        }
        $order = Order::query()->whereKey(substr($buttonId, 4))->where('customer_id', $conversation->customer_id)->first();

        return $order ? $this->status($merchant, $order, $locale) : null;
    }

    /**
     * The menu in words, with no list to tap: the categories (or the items, when there is one category), ten at most,
     * then how to order. The assistant leads the chat, so this is what a greeting or "show me the menu" gets.
     *
     * @return array<string, mixed>
     */
    public function textMenu(Merchant $merchant, string $locale, ?string $note = null): array
    {
        $products = $this->orderable();
        $categories = Category::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->filter(fn (Category $c) => $products->contains('category_id', $c->id))->values();
        $offers = $products->filter(fn (Product $p) => $p->activeOfferPercent() !== null)->count();

        if ($categories->count() <= 1 && $offers === 0) {
            $entries = $products->map(fn (Product $p) => '• '.$this->name($p, $locale).' — '.$this->price($merchant, $p, $locale))->values();
        } else {
            $entries = $categories->map(fn (Category $c) => '• '.$this->name($c, $locale).' — '.$this->itemsCount($products->where('category_id', $c->id)->count(), $locale))->values();
            if ($offers > 0) {
                $entries->prepend('• '.$this->t('offers', $locale).' — '.$this->itemsCount($offers, $locale));
            }
        }
        if ($entries->isEmpty()) {
            return $this->text(trim(($note ?? '')."\n\n".$this->t('no_items', $locale)));
        }

        $more = $entries->count() - 10;
        $lines = $entries->take(10)->implode("\n").($more > 0 ? "\n".$this->t('more_entries', $locale, ['n' => $more]) : '');
        $first = $products->first();

        return $this->text(trim(($note ?? $this->t('choose_category', $locale))."\n\n".$lines."\n\n"
            .$this->t('type_hint', $locale, ['item' => $first ? $this->name($first, $locale) : ''])));
    }

    /**
     * The menu section the customer named ("what's in the pizza section?", "shu 3indkon burgers"), or null.
     * Matches the section's name in any language the shop wrote it in, singular or plural; the longest name wins.
     */
    public function categoryNamed(string $text): ?Category
    {
        $products = $this->orderable();
        $found = null;
        $length = 0;
        foreach (Category::query()->where('is_active', true)->get() as $category) {
            if (! $products->contains('category_id', $category->id)) {
                continue;
            }
            foreach (array_filter([$category->name, $category->name_ar, $category->name_fr]) as $name) {
                $stem = rtrim(trim((string) $name), 'sS');
                if (mb_strlen($stem) < 3) {
                    continue;
                }
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($stem, '/').'(?:s|es)?(?![\p{L}\p{N}])/iu', $text) && mb_strlen($stem) > $length) {
                    $found = $category;
                    $length = mb_strlen($stem);
                }
            }
        }

        return $found;
    }

    /**
     * One section's items with their prices, the answer to "what's in the pizza section?".
     *
     * @return array<string, mixed>
     */
    public function categoryMenu(Merchant $merchant, Category $category, string $locale, ?string $note = null): array
    {
        $items = $this->orderable()->where('category_id', $category->id)->values();
        $more = $items->count() - 15;
        $lines = $items->take(15)->map(fn (Product $p) => '• '.$this->name($p, $locale).' — '.$this->price($merchant, $p, $locale))->implode("\n")
            .($more > 0 ? "\n".$this->t('more_entries', $locale, ['n' => $more]) : '');

        return $this->text(trim(($note ?: $this->name($category, $locale).' 👇')."\n\n".$lines."\n\n"
            .$this->t('type_hint', $locale, ['item' => $items->first() ? $this->name($items->first(), $locale) : ''])));
    }

    /**
     * The "Where's my order?" answer: status, time, driver.
     *
     * @return array<string, mixed>
     */
    public function status(Merchant $merchant, Order $order, string $locale): array
    {
        return $this->text($this->statusText($merchant, $order, $locale));
    }

    /** The status sentence for an order: where it is, when, and the driver. */
    public function statusText(Merchant $merchant, Order $order, string $locale): string
    {
        $order->loadMissing('driver');
        $when = $order->promised_at ? $this->timing->phrase($merchant, $order, $locale) : '';
        $text = match ($order->status) {
            'pending_payment' => __('whatsapp.track.payment', ['number' => $order->number], $locale),
            'placed' => __('whatsapp.track.waiting', ['number' => $order->number, 'shop' => $merchant->name], $locale),
            'picked_up' => __('whatsapp.track.on_the_way', ['number' => $order->number, 'when' => $when,
                'driver' => $order->driver ? "{$order->driver->name} · 📞 ".($order->driver->whatsapp_number ?: $order->driver->phone) : ''], $locale),
            'delivered' => __('whatsapp.track.delivered', ['number' => $order->number], $locale),
            'cancelled', 'rejected' => __('whatsapp.track.closed', ['number' => $order->number], $locale),
            default => __('whatsapp.track.preparing', ['number' => $order->number, 'when' => $when], $locale),
        };

        return $text;
    }

    /**
     * An item the team must check first (for example an age limit): never added by the bot. The
     * customer is asked to wait; the staff get Approve / Refuse buttons, or, with
     * only the shop phone, whoever holds it types ✅ or ❌ in this chat.
     *
     * @return array<string, mixed>
     */
    public function checkRequest(Conversation $conversation, string $locale, Product $product): array
    {
        $customer = $conversation->customer;
        if ($customer->hasPendingCheck() !== $product->id) {
            $customer->markCheck($product->id, false);
        }

        return $this->text(__('whatsapp.check.ask', ['item' => $this->name($product, $locale)], $locale));
    }

    protected function orderable(): Collection
    {
        return Product::query()->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')->get()
            ->filter(fn (Product $p) => $p->isOrderable())->values();
    }

    protected function name(object $model, string $locale): string
    {
        return (string) $model->name;
    }

    protected function price(Merchant $merchant, Product $p, string $locale = 'en'): string
    {
        $variants = $p->variants()->where('is_active', true)->pluck('price')->filter(fn ($v) => $v !== null);
        $base = $variants->isNotEmpty() ? (float) $variants->min() : (float) $p->price;
        $now = $p->priceAfterOffer($base);

        $text = $this->money($merchant, $now).($variants->isNotEmpty() ? '+' : '').$this->unitSuffix($p, $locale);
        if ($now < $base) {
            $text .= ' '.$this->t('was', $locale, ['price' => $this->money($merchant, $base)]).' 🔥';
        }

        return $text;
    }

    protected function unitSuffix(Product $p, string $locale): string
    {
        return $p->unit ? ' / '.$this->unit($p->unit, $locale) : '';
    }

    protected function unit(string $unit, string $locale): string
    {
        $key = "whatsapp.units.{$unit}";

        return trans()->has($key, $locale) ? __($key, [], $locale) : $unit;
    }

    protected function money(Merchant $merchant, float $amount): string
    {
        return Money::format($amount, $merchant->currency);
    }

    protected function t(string $key, string $locale, array $params = []): string
    {
        return __("whatsapp.menu.{$key}", $params, $locale);
    }

    protected function itemsCount(int $n, string $locale): string
    {
        return trans_choice('whatsapp.menu.items_count', $n, ['n' => $n], $locale);
    }

    protected function text(string $body): array
    {
        return ['type' => 'text', 'text' => $body];
    }
}
