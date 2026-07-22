<?php

namespace App\Services\AI\DTO;

use App\Support\Conversation\ConversationIntent;
use App\Support\Conversation\RecommendedAction;

/**
 * Structured output from the AI provider — never parsed from free-form text.
 */
final readonly class StructuredTurnOutput
{
    /**
     * @param  array<string, mixed>  $entities
     * @param  array<int, CartAction>  $cartActions
     * @param  array<string, mixed>  $conversationState
     * @param  array<int, string>  $missingFields
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
    ) {}

    public static function fromArray(array $data): self
    {
        $cartActions = array_map(
            fn (array $a) => CartAction::fromArray($a),
            $data['cart_actions'] ?? [],
        );

        return new self(
            intent: ConversationIntent::tryFrom($data['intent'] ?? '') ?? ConversationIntent::Unknown,
            confidence: (float) ($data['confidence'] ?? 0),
            entities: $data['entities'] ?? [],
            cartActions: $cartActions,
            conversationState: $data['conversation_state'] ?? [],
            missingFields: array_values($data['missing_fields'] ?? []),
            recommendedAction: RecommendedAction::tryFrom($data['recommended_action'] ?? '') ?? RecommendedAction::None,
            assistantMessage: (string) ($data['assistant_message'] ?? ''),
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
        ];
    }
}
