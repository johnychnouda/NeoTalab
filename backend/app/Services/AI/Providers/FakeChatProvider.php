<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\ChatProvider;
use App\Services\AI\DTO\CartAction;
use App\Services\AI\DTO\CartLineSnapshot;
use App\Services\AI\DTO\CatalogProductSnapshot;
use App\Services\AI\DTO\ConversationTurnInput;
use App\Services\AI\DTO\StructuredTurnOutput;
use App\Support\Conversation\ConversationIntent;
use App\Support\Conversation\RecommendedAction;

/**
 * Deterministic driver for tests and local dev, no API key required.
 *
 * Recognized patterns (case-insensitive, EN/AR/FR menu keywords):
 * - "menu" / "قائمة" / "menu svp" → show menu inquiry
 * - "add 2 {product}" / "order 2 {product}" / "بدي 2 {product}" → add to cart
 * - "remove {product}" / "cancel {product}" → remove matching line
 * - "clear" / "empty cart" → clear cart
 * - "hello" / "marhaba" / "bonjour" → greeting
 * - "confirm | {zone} | {address} | {cash|wish} | {USD|LBP|MIXED} | {cash_main} | {delivery|pickup}" → place_order (fields optional)
 * - "cancel order" → cancel_order; "human" / "agent" → handoff
 */
class FakeChatProvider implements ChatProvider
{
    public function processTurn(ConversationTurnInput $input): StructuredTurnOutput
    {
        $text = mb_strtolower(trim($input->message));

        if ($checkout = $this->parseCheckout($input)) {
            return $checkout;
        }

        if (preg_match('/^(checkout|done|that\'s all)$/u', $text)) {
            return $this->action($input, RecommendedAction::Checkout, ConversationIntent::Order);
        }

        if (preg_match('/^(where(\'s| is) my order|order status|track my order|wein talabe|où est ma commande)\b/u', $text)) {
            return $this->action($input, RecommendedAction::TrackOrder, ConversationIntent::Inquiry);
        }

        if (preg_match('/^(cancel (my )?order|الغي الطلب|annuler (ma )?commande)\b/u', $text)) {
            return $this->action($input, RecommendedAction::CancelOrder, ConversationIntent::Cancel);
        }

        if (preg_match('/\b(human|agent|person|موظف|humain)\b/u', $text)) {
            return $this->action($input, RecommendedAction::Handoff, ConversationIntent::Inquiry);
        }

        if ($learned = $this->parseName($input)) {
            return $learned;
        }

        if ($this->isGreeting($text)) {
            return $this->greeting($input);
        }

        if ($this->isMenuRequest($text)) {
            return $this->menuInquiry($input);
        }

        if ($this->isShopLocationRequest($text)) {
            $url = $input->shop['maps_url'] ?? null;
            $address = $input->shop['pickup_address'] ?? ($input->shop['name'] ?? 'the shop');

            return new StructuredTurnOutput(
                intent: ConversationIntent::Inquiry,
                confidence: 0.9,
                entities: [],
                cartActions: [],
                conversationState: ['phase' => $input->conversationPhase],
                missingFields: [],
                recommendedAction: RecommendedAction::None,
                assistantMessage: $url
                    ? "📍 {$address}\n{$url}"
                    : ($address !== '' && $address !== 'the shop'
                        ? "📍 We’re at {$address}"
                        : 'I don’t have the shop map pin saved yet 🙏'),
            );
        }

        if ($this->isClearCart($text)) {
            return $this->clearCart($input);
        }

        if ($remove = $this->parseRemove($text, $input)) {
            return $remove;
        }

        if ($add = $this->parseAdd($text, $input)) {
            return $add;
        }

        return new StructuredTurnOutput(
            intent: ConversationIntent::Unknown,
            confidence: 0.3,
            entities: ['raw_message' => $input->message],
            cartActions: [],
            conversationState: ['phase' => $input->conversationPhase],
            missingFields: [],
            recommendedAction: RecommendedAction::AskClarification,
            assistantMessage: "Hmm I didn't catch that 🙏 Try \"menu\" or something like \"add 2 ProductName\".",
        );
    }

    private function parseCheckout(ConversationTurnInput $input): ?StructuredTurnOutput
    {
        if (! preg_match('/^\s*(confirm|checkout|confirmer|تأكيد)\b(.*)$/iu', $input->message, $m)) {
            return null;
        }

        $parts = array_map('trim', explode('|', $m[2]));
        array_shift($parts); // text before the first "|"

        return new StructuredTurnOutput(
            intent: ConversationIntent::Order,
            confidence: 0.9,
            entities: [],
            cartActions: [],
            conversationState: ['phase' => 'checkout'],
            missingFields: [],
            recommendedAction: RecommendedAction::PlaceOrder,
            assistantMessage: 'Perfect, let’s finish this 👍',
            checkout: [
                'delivery_zone' => ($parts[0] ?? '') ?: null,
                'delivery_address' => ($parts[1] ?? '') ?: null,
                'payment_method' => ($parts[2] ?? '') ?: null,
                'payment_currency' => ($parts[3] ?? '') ?: null,
                'cash_main' => is_numeric($parts[4] ?? null) ? (float) $parts[4] : null,
                'fulfillment' => ($parts[5] ?? '') ?: null,
                'notes' => null,
                // Customers always share a location pin and a typed address is only the note for the driver: an address
                // in this test command stands in for both. A pin the customer really sent earlier wins (ConversationService).
                'location' => ($parts[1] ?? '') !== '' ? ['lat' => 33.8938, 'lng' => 35.5018] : null,
            ],
        );
    }

    private function action(ConversationTurnInput $input, RecommendedAction $action, ConversationIntent $intent): StructuredTurnOutput
    {
        return new StructuredTurnOutput(
            intent: $intent,
            confidence: 0.9,
            entities: [],
            cartActions: [],
            conversationState: ['phase' => $input->conversationPhase],
            missingFields: [],
            recommendedAction: $action,
            assistantMessage: '',
        );
    }

    private function isGreeting(string $text): bool
    {
        return (bool) preg_match('/^(hi|hello|hey|marhaba|bonjour|salut)\b/u', $text);
    }

    private function isShopLocationRequest(string $text): bool
    {
        return (bool) preg_match(
            '/\b(where (are|is) (you|the shop)|your (location|address)|shop (location|address)|google maps|وين المحل|موقعكم|localisation|où êtes)\b/u',
            $text,
        );
    }

    private function isMenuRequest(string $text): bool
    {
        return (bool) preg_match('/\b(menu|catalogue|قائمة|menu svp)\b/u', $text);
    }

    private function isClearCart(string $text): bool
    {
        return (bool) preg_match('/\b(clear|empty cart|reset cart)\b/u', $text);
    }

    private function greeting(ConversationTurnInput $input): StructuredTurnOutput
    {
        $hello = $input->customerName
            ? "Good day Mr/Ms {$input->customerName}! What can I get you today, please? 😊"
            : 'Good day Sir/Ma’am! What can I get you today, please? 😊 May I have your name?';

        return new StructuredTurnOutput(
            intent: ConversationIntent::Greeting,
            confidence: 0.99,
            entities: [],
            cartActions: [],
            conversationState: ['phase' => 'idle', 'language' => 'en'],
            missingFields: $input->customerName ? [] : ['customer_name'],
            recommendedAction: RecommendedAction::AwaitCustomer,
            assistantMessage: $hello,
        );
    }

    private function parseName(ConversationTurnInput $input): ?StructuredTurnOutput
    {
        if ($input->customerName) {
            return null;
        }
        $text = trim($input->message);
        $name = null;
        if (preg_match('/^(?:my name is|i(?:\'| a)?m|i am|ana|أنا|اسمي|je m[\'’]appelle)\s+(.+)$/iu', $text, $m)) {
            $name = trim($m[1], " \t.,!؟?");
        } elseif (preg_match('/^[\p{L}][\p{L}\'’\-]{1,30}(?:\s+[\p{L}][\p{L}\'’\-]{1,30}){0,2}$/u', $text)
            && ! preg_match('/\b(menu|hi|hello|hey|yes|no|ok)\b/iu', $text)) {
            // Short name-only reply after we asked.
            $asked = collect($input->history)->contains(fn ($m) => str_contains(mb_strtolower((string) ($m['content'] ?? '')), 'name'));
            if ($asked) {
                $name = $text;
            }
        }
        if ($name === null || $name === '') {
            return null;
        }

        return new StructuredTurnOutput(
            intent: ConversationIntent::Inquiry,
            confidence: 0.9,
            entities: [],
            cartActions: [],
            conversationState: ['phase' => $input->conversationPhase, 'language' => 'en'],
            missingFields: [],
            recommendedAction: RecommendedAction::AwaitCustomer,
            assistantMessage: 'Pleasure to meet you! What would you like today, please? 😊',
            customerName: $name,
        );
    }

    private function menuInquiry(ConversationTurnInput $input): StructuredTurnOutput
    {
        $names = array_map(fn ($p) => $p->name, $input->catalog);

        return new StructuredTurnOutput(
            intent: ConversationIntent::Inquiry,
            confidence: 0.95,
            entities: ['available_products' => $names],
            cartActions: [],
            conversationState: ['phase' => 'browsing'],
            missingFields: [],
            recommendedAction: RecommendedAction::ShowMenu,
            assistantMessage: 'Sure — here’s the menu 👇',
        );
    }

    private function clearCart(ConversationTurnInput $input): StructuredTurnOutput
    {
        return new StructuredTurnOutput(
            intent: ConversationIntent::ModifyCart,
            confidence: 0.98,
            entities: [],
            cartActions: [new CartAction(action: 'clear')],
            conversationState: ['phase' => 'building_cart'],
            missingFields: [],
            recommendedAction: RecommendedAction::ShowMenu,
            assistantMessage: 'Done — cart’s empty. What would you like?',
        );
    }

    private function parseAdd(string $text, ConversationTurnInput $input): ?StructuredTurnOutput
    {
        // "add 2 shawarma", "order 1 fresh juice", "بدي 3 pizza"
        if (! preg_match('/(?:add|order|بدي)\s+(\d+)?\s*(.+)$/u', $text, $m)
            && ! preg_match('/^(\d+)\s+(.+)$/u', $text, $m)) {
            return null;
        }

        $qty = isset($m[1]) && is_numeric($m[1]) ? (int) $m[1] : 1;
        $productQuery = trim($m[2] ?? $m[1] ?? '');
        if ($qty > 1 && isset($m[2])) {
            $productQuery = trim($m[2]);
        } elseif (! isset($m[2]) && is_numeric($m[1])) {
            $qty = (int) $m[1];
            $productQuery = trim($m[2] ?? '');
        }

        $product = $this->matchProduct($productQuery, $input);
        if (! $product) {
            return new StructuredTurnOutput(
                intent: ConversationIntent::Order,
                confidence: 0.6,
                entities: ['requested_product' => $productQuery],
                cartActions: [],
                conversationState: ['phase' => 'building_cart'],
                missingFields: ['product'],
                recommendedAction: RecommendedAction::AskClarification,
                assistantMessage: "I don't see \"{$productQuery}\" on our menu — want something else?",
            );
        }

        if (! $product->isOrderable) {
            return new StructuredTurnOutput(
                intent: ConversationIntent::Order,
                confidence: 0.7,
                entities: ['product_id' => $product->id, 'product_name' => $product->name],
                cartActions: [],
                conversationState: ['phase' => 'building_cart'],
                missingFields: [],
                recommendedAction: RecommendedAction::AskClarification,
                assistantMessage: "Sorry, {$product->name} isn’t available right now 🙏",
            );
        }

        return new StructuredTurnOutput(
            intent: ConversationIntent::ModifyCart,
            confidence: 0.92,
            entities: [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => $qty,
            ],
            cartActions: [
                new CartAction(
                    action: 'add',
                    productId: $product->id,
                    quantity: max(1, $qty),
                ),
            ],
            conversationState: ['phase' => 'building_cart'],
            missingFields: [],
            recommendedAction: RecommendedAction::ConfirmCart,
            assistantMessage: "Got it — {$qty} × {$product->name} 👍 Anything else?",
        );
    }

    private function parseRemove(string $text, ConversationTurnInput $input): ?StructuredTurnOutput
    {
        if (! preg_match('/(?:remove|cancel|delete)\s+(.+)$/u', $text, $m)) {
            return null;
        }

        $productQuery = trim($m[1]);
        $line = $this->matchCartLine($productQuery, $input);

        if (! $line) {
            return new StructuredTurnOutput(
                intent: ConversationIntent::ModifyCart,
                confidence: 0.5,
                entities: ['requested_product' => $productQuery],
                cartActions: [],
                conversationState: ['phase' => 'building_cart'],
                missingFields: [],
                recommendedAction: RecommendedAction::AskClarification,
                assistantMessage: "I don't see \"{$productQuery}\" in your cart — want to add something?",
            );
        }

        return new StructuredTurnOutput(
            intent: ConversationIntent::ModifyCart,
            confidence: 0.9,
            entities: ['cart_item_id' => $line->id],
            cartActions: [new CartAction(action: 'remove', cartItemId: $line->id)],
            conversationState: ['phase' => 'building_cart'],
            missingFields: [],
            recommendedAction: RecommendedAction::ConfirmCart,
            assistantMessage: 'Removed 👍 Anything else?',
        );
    }

    private function matchProduct(string $query, ConversationTurnInput $input): ?CatalogProductSnapshot
    {
        $query = mb_strtolower(trim($query));

        foreach ($input->catalog as $product) {
            $names = array_filter([
                mb_strtolower($product->name),
                $product->nameAr ? mb_strtolower($product->nameAr) : null,
                $product->nameFr ? mb_strtolower($product->nameFr) : null,
            ]);

            foreach ($names as $name) {
                if ($name === $query || str_contains($name, $query) || str_contains($query, $name)) {
                    return $product;
                }
            }
        }

        return null;
    }

    private function matchCartLine(string $query, ConversationTurnInput $input): ?CartLineSnapshot
    {
        $query = mb_strtolower(trim($query));

        foreach ($input->cartLines as $line) {
            $name = mb_strtolower($line->productName ?? '');
            if ($name && (str_contains($name, $query) || str_contains($query, $name))) {
                return $line;
            }
        }

        return null;
    }
}
