<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\ChatProvider;
use App\Services\AI\DTO\ConversationTurnInput;
use App\Services\AI\DTO\StructuredTurnOutput;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenAI-compatible chat completions with JSON-schema structured output.
 * Works with OpenAI, Azure OpenAI, Groq, and other compatible gateways.
 */
class OpenAiCompatibleChatProvider implements ChatProvider
{
    public function processTurn(ConversationTurnInput $input): StructuredTurnOutput
    {
        $apiKey = config('ai.openai.api_key');
        if (empty($apiKey)) {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $catalog = array_map(fn ($p) => $p->toArray(), $input->catalog);
        $cart = array_map(fn ($l) => $l->toArray(), $input->cartLines);

        $system = <<<'PROMPT'
You are a commerce assistant for a WhatsApp ordering system. The customer may write in Arabic (including Lebanese dialect), English, French, or mixed language.

Return ONLY valid JSON matching the provided schema. Never return prose outside JSON.

Resolve product references to IDs from the supplied catalog. If a product is not in the catalog or not orderable, do not invent IDs — leave cart_actions empty and set missing_fields.

Cart action rules:
- "add": requires product_id, optional variant_id, modifier_ids, quantity
- "update": requires cart_item_id and quantity
- "remove": requires cart_item_id
- "clear": empties the cart

Intents: greeting, order, modify_cart, inquiry, cancel, unknown.
Recommended actions: show_menu, ask_clarification, confirm_cart, close_conversation, await_customer, none.
PROMPT;

        $userPayload = json_encode([
            'customer_message' => $input->message,
            'locale' => $input->locale,
            'conversation_phase' => $input->conversationPhase,
            'history' => $input->history,
            'catalog' => $catalog,
            'cart' => $cart,
        ], JSON_UNESCAPED_UNICODE);

        $schema = $this->responseSchema();

        try {
            $response = Http::withToken($apiKey)
                ->timeout(config('ai.openai.timeout', 30))
                ->post(rtrim(config('ai.openai.base_url'), '/').'/chat/completions', [
                    'model' => config('ai.openai.model'),
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $userPayload],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'conversation_turn',
                            'strict' => true,
                            'schema' => $schema,
                        ],
                    ],
                ])
                ->throw();
        } catch (RequestException $e) {
            throw new RuntimeException('AI provider request failed: '.$e->getMessage(), 0, $e);
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content)) {
            throw new RuntimeException('AI provider returned an empty response.');
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('AI provider returned invalid JSON.');
        }

        return StructuredTurnOutput::fromArray($decoded);
    }

    /**
     * @return array<string, mixed>
     */
    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'intent', 'confidence', 'entities', 'cart_actions',
                'conversation_state', 'missing_fields', 'recommended_action', 'assistant_message',
            ],
            'properties' => [
                'intent' => [
                    'type' => 'string',
                    'enum' => ['greeting', 'order', 'modify_cart', 'inquiry', 'cancel', 'unknown'],
                ],
                'confidence' => ['type' => 'number'],
                'entities' => ['type' => 'object', 'additionalProperties' => true],
                'cart_actions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['action'],
                        'properties' => [
                            'action' => ['type' => 'string', 'enum' => ['add', 'update', 'remove', 'clear']],
                            'product_id' => ['type' => ['string', 'null']],
                            'variant_id' => ['type' => ['string', 'null']],
                            'cart_item_id' => ['type' => ['string', 'null']],
                            'quantity' => ['type' => 'integer'],
                            'modifier_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'notes' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
                'conversation_state' => ['type' => 'object', 'additionalProperties' => true],
                'missing_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
                'recommended_action' => [
                    'type' => 'string',
                    'enum' => ['show_menu', 'ask_clarification', 'confirm_cart', 'close_conversation', 'await_customer', 'none'],
                ],
                'assistant_message' => ['type' => 'string'],
            ],
        ];
    }
}
