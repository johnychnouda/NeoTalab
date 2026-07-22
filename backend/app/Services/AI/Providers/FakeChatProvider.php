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
 * Deterministic driver for tests and local dev — no API key required.
 *
 * Recognized patterns (case-insensitive, EN/AR/FR menu keywords):
 * - "menu" / "قائمة" / "menu svp" → show menu inquiry
 * - "add 2 {product}" / "order 2 {product}" / "بدي 2 {product}" → add to cart
 * - "remove {product}" / "cancel {product}" → remove matching line
 * - "clear" / "empty cart" → clear cart
 * - "hello" / "marhaba" / "bonjour" → greeting
 */
class FakeChatProvider implements ChatProvider
{
    public function processTurn(ConversationTurnInput $input): StructuredTurnOutput
    {
        $text = mb_strtolower(trim($input->message));

        if ($this->isGreeting($text)) {
            return $this->greeting($input);
        }

        if ($this->isMenuRequest($text)) {
            return $this->menuInquiry($input);
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
            assistantMessage: 'Sorry, I did not understand. Try "menu" or "add 2 ProductName".',
        );
    }

    private function isGreeting(string $text): bool
    {
        return (bool) preg_match('/^(hi|hello|hey|marhaba|bonjour|salut)\b/u', $text);
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
        return new StructuredTurnOutput(
            intent: ConversationIntent::Greeting,
            confidence: 0.99,
            entities: [],
            cartActions: [],
            conversationState: ['phase' => 'idle'],
            missingFields: [],
            recommendedAction: RecommendedAction::ShowMenu,
            assistantMessage: 'Hello! How can I help you order today?',
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
            assistantMessage: 'Here is what we have: '.implode(', ', $names ?: ['(nothing listed yet)']).'.',
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
            assistantMessage: 'Your cart has been cleared.',
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
                assistantMessage: "I couldn't find \"{$productQuery}\" on the menu.",
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
                assistantMessage: "\"{$product->name}\" is not available right now.",
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
            assistantMessage: "Added {$qty} × {$product->name} to your cart.",
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
                assistantMessage: "No cart line matching \"{$productQuery}\".",
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
            assistantMessage: 'Removed that item from your cart.',
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
