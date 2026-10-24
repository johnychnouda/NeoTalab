<?php

namespace App\Services;

use App\Jobs\NotifyStaffOfCheck;
use App\Models\Category;
use App\Models\Conversation;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Services\AI\DTO\CartAction;
use App\Support\BusinessKind;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The menu inside the WhatsApp chat, no pages, no links: categories and items as WhatsApp
 * lists (with "More" for big menus), options and quantity as buttons, then the cart with
 * Add more / Checkout. Also answers the "Where's my order?" button.
 *
 * Button ids: mn:cats:{page}, mn:cat:{id}:{page}, mn:item:{id}, mn:var:{id}, mn:mod:{id},
 * mn:done, mn:qty:{n}, mn:qtymore, trk:{orderId}. The option being built lives in
 * conversations.state['menu_pick'].
 */
class ChatMenu
{
    /** WhatsApp lists hold 10 rows: 9 entries + "More". */
    public const PAGE = 9;

    public function __construct(
        protected CartService $cart,
        protected OrderTiming $timing,
    ) {}

    /**
     * @return array<string, mixed>|null the message to send, or null if the button is not ours
     */
    public function handle(Merchant $merchant, Conversation $conversation, string $locale, string $buttonId): ?array
    {
        if (str_starts_with($buttonId, 'trk:')) {
            $order = Order::query()->whereKey(substr($buttonId, 4))->where('customer_id', $conversation->customer_id)->first();

            return $order ? $this->status($merchant, $order, $locale) : null;
        }
        if (! str_starts_with($buttonId, 'mn:')) {
            return null;
        }

        [, $kind, $a, $b] = array_pad(explode(':', $buttonId, 4), 4, '');

        return match ($kind) {
            'cats' => $this->categories($merchant, $locale, max(1, (int) $a)),
            'cat' => $this->items($merchant, $locale, $a, max(1, (int) $b)),
            'item' => $this->startItem($merchant, $conversation, $locale, $a),
            'var' => $this->pickVariant($merchant, $conversation, $locale, $a),
            'mod' => $this->pickModifier($merchant, $conversation, $locale, $a),
            'done' => $this->nextGroup($merchant, $conversation, $locale),
            'qty' => $this->add($merchant, $conversation, $locale, max(1, min(CartService::MAX_LINE_QUANTITY, (int) $a))),
            'qtymore' => $this->list($this->t('how_many', $locale), $this->t('btn_choose', $locale), array_map(
                fn ($n) => ['id' => "mn:qty:{$n}", 'title' => (string) $n, 'description' => ''], range(3, 10),
            )),
            default => null,
        };
    }

    /**
     * The category list. $body replaces the default text (e.g. the assistant's greeting).
     *
     * @return array<string, mixed>
     */
    public function categories(Merchant $merchant, string $locale, int $page = 1, ?string $body = null): array
    {
        $products = $this->orderable();
        $categories = Category::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->filter(fn (Category $c) => $products->contains('category_id', $c->id))->values();

        // One category (or none) and no offers: go straight to the items.
        if ($categories->count() <= 1 && $products->every(fn (Product $p) => $p->activeOfferPercent() === null)) {
            return $this->items($merchant, $locale, (string) ($categories->first()->id ?? 'all'), 1, $body);
        }

        $offers = $products->filter(fn (Product $p) => $p->activeOfferPercent() !== null)->count();
        $entries = $categories->map(fn (Category $c) => [
            'id' => "mn:cat:{$c->id}:1",
            'title' => $this->name($c, $locale),
            'description' => $this->itemsCount($products->where('category_id', $c->id)->count(), $locale),
        ]);
        // Offers come first, as their own category.
        if ($offers > 0) {
            $entries->prepend(['id' => 'mn:cat:offers:1', 'title' => $this->t('offers', $locale), 'description' => $this->itemsCount($offers, $locale)]);
        }

        $rows = $this->paged($entries, $page, fn (array $row) => $row, 'mn:cats:'.($page + 1), $locale);

        return $this->list($body ?? $this->t('choose_category', $locale), $this->t('btn_'.BusinessKind::menuWord($merchant), $locale), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function items(Merchant $merchant, string $locale, string $categoryId, int $page = 1, ?string $body = null): array
    {
        // Sold-out items never appear in the menu.
        $products = $this->orderable();
        if ($categoryId === 'offers') {
            $products = $products->filter(fn (Product $p) => $p->activeOfferPercent() !== null)->values();
        } elseif ($categoryId !== 'all') {
            $products = $products->where('category_id', $categoryId)->values();
        }
        if ($products->isEmpty()) {
            return $this->text($this->t('no_items', $locale));
        }

        $rows = $this->paged($products, $page, fn (Product $p) => [
            'id' => "mn:item:{$p->id}",
            'title' => $this->name($p, $locale),
            'description' => $this->price($merchant, $p, $locale),
        ], "mn:cat:{$categoryId}:".($page + 1), $locale);

        $category = ! in_array($categoryId, ['all', 'offers'], true) ? Category::query()->find($categoryId) : null;
        $title = $categoryId === 'offers' ? $this->t('offers', $locale) : ($category ? $this->name($category, $locale) : $this->t('choose_item', $locale));

        return $this->list($body ?? $title, $this->t('btn_items', $locale), $rows);
    }

    /**
     * The "Where's my order?" answer: status, time, driver.
     *
     * @return array<string, mixed>
     */
    public function status(Merchant $merchant, Order $order, string $locale): array
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

        return in_array($order->status, ['delivered', 'cancelled', 'rejected'], true)
            ? $this->text($text)
            : self::withStatusButton($order, $text, $locale);
    }

    /**
     * A message with the "📦 Where's my order?" button.
     *
     * @return array<string, mixed>
     */
    public static function withStatusButton(Order $order, string $body, string $locale): array
    {
        return ['type' => 'interactive', 'interactive' => [
            'type' => 'button',
            'body' => ['text' => mb_substr($body, 0, 1024)],
            'action' => ['buttons' => [['type' => 'reply', 'reply' => ['id' => "trk:{$order->id}", 'title' => mb_substr(__('whatsapp.track.button', [], $locale), 0, 20)]]]],
        ]];
    }

    /** @return array<string, mixed> */
    protected function startItem(Merchant $merchant, Conversation $conversation, string $locale, string $productId): array
    {
        $product = Product::with(['variants', 'modifierGroups.modifiers'])->find($productId);
        if (! $product || $product->duration_mins) {
            return $this->categories($merchant, $locale);
        }
        if (! $product->isOrderable()) {
            return $this->items($merchant, $locale, (string) ($product->category_id ?: 'all'), 1, $this->t('is_sold_out', $locale, ['item' => $this->name($product, $locale)]));
        }

        if ($product->staff_check && ! $conversation->customer?->isApproved($product->id)) {
            return $this->checkRequest($conversation, $locale, $product);
        }

        $this->savePick($conversation, ['product' => $product->id, 'variant' => null, 'mods' => [], 'group' => 0]);

        return $this->nextStep($merchant, $conversation, $locale);
    }

    /**
     * An item the team must check first (prescription, age): never added by the bot. The customer
     * is asked for a photo of the prescription; the staff get Approve / Refuse buttons, or, with
     * only the shop phone, whoever holds it types ✅ or ❌ in this chat.
     *
     * @return array<string, mixed>
     */
    public function checkRequest(Conversation $conversation, string $locale, Product $product): array
    {
        $customer = $conversation->customer;
        if ($customer->hasPendingCheck() !== $product->id) {
            $customer->markCheck($product->id, false);
            NotifyStaffOfCheck::dispatch($customer->id, $product->id);
        }

        return $this->text(__('whatsapp.check.ask', ['item' => $this->name($product, $locale)], $locale));
    }

    /** @return array<string, mixed> */
    protected function pickVariant(Merchant $merchant, Conversation $conversation, string $locale, string $variantId): array
    {
        $pick = $this->pick($conversation);
        if (! $pick) {
            return $this->categories($merchant, $locale);
        }
        $pick['variant'] = $variantId;
        $this->savePick($conversation, $pick);

        return $this->nextStep($merchant, $conversation, $locale);
    }

    /** @return array<string, mixed> */
    protected function pickModifier(Merchant $merchant, Conversation $conversation, string $locale, string $modifierId): array
    {
        $pick = $this->pick($conversation);
        $product = $pick ? Product::with('modifierGroups.modifiers')->find($pick['product']) : null;
        $group = $product?->modifierGroups->values()->get($pick['group'] ?? 0);
        if (! $product || ! $group) {
            return $this->categories($merchant, $locale);
        }

        if (! in_array($modifierId, $pick['mods'], true)) {
            $pick['mods'][] = $modifierId;
        }
        $chosen = count(array_intersect($pick['mods'], $group->modifiers->pluck('id')->all()));
        $max = (int) ($group->max_select ?: 0);
        if ($max === 1 || ($max > 1 && $chosen >= $max)) {
            $pick['group']++;
        }
        $this->savePick($conversation, $pick);

        return $this->nextStep($merchant, $conversation, $locale);
    }

    /** @return array<string, mixed> */
    protected function nextGroup(Merchant $merchant, Conversation $conversation, string $locale): array
    {
        $pick = $this->pick($conversation);
        if (! $pick) {
            return $this->categories($merchant, $locale);
        }
        $pick['group']++;
        $this->savePick($conversation, $pick);

        return $this->nextStep($merchant, $conversation, $locale);
    }

    /**
     * Asks for the next missing choice: size, then each group of extras, then the quantity.
     *
     * @return array<string, mixed>
     */
    protected function nextStep(Merchant $merchant, Conversation $conversation, string $locale): array
    {
        $pick = $this->pick($conversation);
        $product = Product::with(['variants', 'modifierGroups.modifiers'])->find($pick['product']);
        $name = $this->name($product, $locale);

        $variants = $product->variants->filter(fn ($v) => $v->isOffered())->values();
        if ($variants->isNotEmpty() && ! $pick['variant']) {
            return $this->choices($this->t('which_variant', $locale, ['item' => $name]), $variants->map(fn ($v) => [
                'id' => "mn:var:{$v->id}",
                'title' => $this->name($v, $locale),
                'description' => $this->money($merchant, (float) ($v->price ?? $product->price)),
            ])->all(), $locale);
        }

        $groups = $product->modifierGroups->values();
        while (($group = $groups->get($pick['group'])) !== null) {
            $options = $group->modifiers->filter(fn ($m) => ($m->is_active ?? true) && ($m->is_available ?? true))
                ->reject(fn ($m) => in_array($m->id, $pick['mods'], true))->values();
            $chosen = count(array_intersect($pick['mods'], $group->modifiers->pluck('id')->all()));
            if ($options->isEmpty()) {
                $pick['group']++;
                $this->savePick($conversation, $pick);

                continue;
            }

            $rows = $options->take(self::PAGE)->map(fn ($m) => [
                'id' => "mn:mod:{$m->id}",
                'title' => $this->name($m, $locale),
                'description' => (float) $m->price_delta > 0 ? '+'.$this->money($merchant, (float) $m->price_delta) : '',
            ])->all();
            $required = $group->required && $chosen < max(1, (int) $group->min_select);
            if (! $required) {
                $rows[] = ['id' => 'mn:done', 'title' => $this->t($chosen > 0 ? 'done' : 'no_thanks', $locale), 'description' => ''];
            }

            return $this->choices($this->t('which_option', $locale, ['group' => $this->name($group, $locale), 'item' => $name]), $rows, $locale);
        }

        $unit = $product->unit ? ' '.$this->unit($product->unit, $locale) : '';
        // Remembered so a typed number ("2", "٢") answers this question like the buttons do.
        $this->savePick($conversation, ['awaiting' => 'qty'] + $pick);

        return $this->buttons($this->t('how_many_of', $locale, ['item' => $name]), [
            ['mn:qty:1', "1{$unit}"], ['mn:qty:2', "2{$unit}"], ['mn:qtymore', "3+{$unit}"],
        ]);
    }

    /** @return array<string, mixed> */
    protected function add(Merchant $merchant, Conversation $conversation, string $locale, int $qty): array
    {
        $pick = $this->pick($conversation);
        if (! $pick) {
            return $this->categories($merchant, $locale);
        }

        $conversation->load('cart');
        $cart = $conversation->cart ?? $this->cart->createForConversation($conversation->id);
        $result = $this->cart->applyActions($cart, [new CartAction(
            action: 'add', productId: $pick['product'], variantId: $pick['variant'], quantity: $qty, modifierIds: $pick['mods'],
        )]);
        $this->savePick($conversation, null);

        if ($result['applied'] === 0) {
            return $this->categories($merchant, $locale, 1, $this->t('cannot_add', $locale));
        }

        $product = Product::query()->find($pick['product']);
        $cart->refresh();
        $count = (int) $cart->items()->sum('quantity');

        return $this->buttons($this->t('added', $locale, [
            'qty' => $qty,
            'item' => $this->name($product, $locale),
            'cart' => $this->itemsCount($count, $locale),
            'total' => $this->money($merchant, (float) $cart->subtotal),
        ]), [['mn:cats:1', $this->t('btn_add_more', $locale)], ['co:start:', $this->t('btn_checkout', $locale)]]);
    }

    /**
     * @param  list<array{id: string, title: string, description: string}>  $rows
     * @return array<string, mixed>
     */
    protected function choices(string $body, array $rows, string $locale): array
    {
        // Up to 3 short choices fit as buttons (one tap); more become a list.
        if (count($rows) <= 3 && collect($rows)->every(fn ($r) => mb_strlen(trim($r['title'].' '.$r['description'])) <= 20)) {
            return $this->buttons($body, array_map(fn ($r) => [$r['id'], trim($r['title'].' '.$r['description'])], $rows));
        }

        return $this->list($body, $this->t('btn_choose', $locale), $rows);
    }

    /**
     * @param  Collection<int, mixed>  $all
     * @return list<array{id: string, title: string, description: string}>
     */
    protected function paged(Collection $all, int $page, callable $row, string $moreId, string $locale): array
    {
        $total = $all->count();
        $size = $total <= self::PAGE + 1 ? self::PAGE + 1 : self::PAGE;
        $rows = $all->slice(($page - 1) * self::PAGE, $size)->map($row)->values()->all();
        if ($total > $page * self::PAGE && $total > self::PAGE + 1) {
            $rows[] = ['id' => $moreId, 'title' => '➡️ '.__('whatsapp.menu.more', [], $locale), 'description' => ''];
        }

        return $rows;
    }

    /** @return Collection<int, Product> */
    protected function orderable(): Collection
    {
        return Product::query()->where('is_active', true)->whereNull('duration_mins')
            ->orderBy('sort_order')->orderBy('name')->get()
            ->filter(fn (Product $p) => $p->isOrderable())->values();
    }

    /**
     * A plain number typed while the menu waits for the quantity: added like the quantity buttons.
     * Null when the menu is not waiting for one or the text is not just a number.
     *
     * @return array<string, mixed>|null
     */
    public function typedQuantity(Merchant $merchant, Conversation $conversation, string $locale, ?string $text): ?array
    {
        $pick = $this->pick($conversation);
        $digits = strtr(trim((string) $text), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        if (($pick['awaiting'] ?? null) !== 'qty' || ! preg_match('/^(?:x\s*)?(\d{1,2})$/iu', $digits, $m) || (int) $m[1] < 1) {
            return null;
        }

        return $this->add($merchant, $conversation, $locale, min(CartService::MAX_LINE_QUANTITY, (int) $m[1]));
    }

    /** @return array<string, mixed>|null */
    protected function pick(Conversation $conversation): ?array
    {
        return ($conversation->state ?? [])['menu_pick'] ?? null;
    }

    /** @param array<string, mixed>|null $pick */
    protected function savePick(Conversation $conversation, ?array $pick): void
    {
        $state = $conversation->state ?? [];
        if ($pick === null) {
            unset($state['menu_pick']);
        } else {
            $state['menu_pick'] = $pick;
        }
        $conversation->forceFill(['state' => $state, 'last_message_at' => now()])->save();
    }

    /** Names are shown exactly as the shop wrote them, in any language. */
    protected function name(object $model, string $locale): string
    {
        return (string) $model->name;
    }

    /** "$3.00 / kg", "$8.00+" (sizes), "$8.00 (was $10.00) 🔥" (offer). */
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

    /** @param array<string, mixed> $params */
    protected function t(string $key, string $locale, array $params = []): string
    {
        return __("whatsapp.menu.{$key}", $params, $locale);
    }

    /** "1 item" / "2 items": the plural depends on the count and the language. */
    protected function itemsCount(int $n, string $locale): string
    {
        return trans_choice('whatsapp.menu.items_count', $n, ['n' => $n], $locale);
    }

    /** @return array<string, mixed> */
    protected function text(string $body): array
    {
        return ['type' => 'text', 'text' => $body];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $buttons
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
                fn ($r) => array_filter(['id' => $r['id'], 'title' => Str::limit($r['title'], 23, '…'), 'description' => mb_substr($r['description'], 0, 72)], fn ($v) => $v !== ''),
                $rows,
            )]]],
        ]];
    }
}
