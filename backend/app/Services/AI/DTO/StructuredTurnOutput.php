<?php

namespace App\Services\AI\DTO;

use App\Support\Conversation\ConversationIntent;
use App\Support\Conversation\RecommendedAction;

/**
 * Structured output from the AI provider, never parsed from free-form text.
 */
final readonly class StructuredTurnOutput
{
    /**
     * @param  array<string, mixed>  $entities
     * @param  array<int, CartAction>  $cartActions
     * @param  array<string, mixed>  $conversationState
     * @param  array<int, string>  $missingFields
     * @param  array<string, string|null>|null  $checkout  delivery_zone, delivery_address, payment_method, notes,
     *                                                     set only with place_order, after the customer confirmed
     * @param  string|null  $customerName  Name the customer just gave, when we should save it
     */
    public function __construct(
        public ConversationIntent $intent,
        public float $confidence,
        public array $entities,
        public array $cartActions,
        public array $conversationState,
        public array $missingFields,
        public RecommendedAction $recommendedAction,
        public string $assistantMessage,
        public ?array $checkout = null,
        public ?string $customerName = null,
    ) {}

    public static function fromArray(array $data): self
    {
        $cartActions = array_map(
            fn (array $a) => CartAction::fromArray($a),
            $data['cart_actions'] ?? [],
        );
        $name = $data['customer_name'] ?? null;
        $name = is_string($name) ? trim($name) : null;

        return new self(
            intent: ConversationIntent::tryFrom($data['intent'] ?? '') ?? ConversationIntent::Unknown,
            confidence: (float) ($data['confidence'] ?? 0),
            entities: $data['entities'] ?? [],
            cartActions: $cartActions,
            conversationState: $data['conversation_state'] ?? [],
            missingFields: array_values($data['missing_fields'] ?? []),
            recommendedAction: RecommendedAction::tryFrom($data['recommended_action'] ?? '') ?? RecommendedAction::None,
            assistantMessage: (string) ($data['assistant_message'] ?? ''),
            checkout: is_array($data['checkout'] ?? null) ? $data['checkout'] : null,
            customerName: ($name !== null && $name !== '') ? mb_substr($name, 0, 120) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'intent' => $this->intent->value,
            'confidence' => $this->confidence,
            'entities' => $this->entities,
            'cart_actions' => array_map(fn (CartAction $a) => $a->toArray(), $this->cartActions),
            'conversation_state' => $this->conversationState,
            'missing_fields' => $this->missingFields,
            'recommended_action' => $this->recommendedAction->value,
            'assistant_message' => $this->assistantMessage,
            'checkout' => $this->checkout,
            'customer_name' => $this->customerName,
        ];
    }
}
