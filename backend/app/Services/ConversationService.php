<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Customer;
use App\Services\AI\CatalogResolver;
use App\Services\AI\Contracts\ChatProvider;
use App\Services\AI\DTO\ConversationTurnInput;
use App\Services\AI\DTO\StructuredTurnOutput;
use App\Support\Conversation\ConversationStatus;
use App\Support\CurrentTenant;
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
     * @return array{output: StructuredTurnOutput, cart_result: array{applied:int,rejected:array}, conversation: Conversation}
     */
    public function processTurn(Conversation $conversation, string $message): array
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
        $history = array_map(
            fn (array $m) => ['role' => $m['role'], 'content' => $m['content']],
            $state['messages'] ?? []
        );

        $input = new ConversationTurnInput(
            message: $message,
            locale: $conversation->customer->locale ?? 'en',
            history: $history,
            catalog: $this->catalogResolver->buildCatalogSnapshot()->all(),
            cartLines: $this->cartService->snapshotLines($cart)->all(),
            conversationPhase: $state['phase'] ?? 'idle',
        );

        $output = $this->chatProvider->processTurn($input);

        $cartResult = $this->cartService->applyActions($cart, $output->cartActions);

        $now = now()->toIso8601String();
        $messages = $state['messages'] ?? [];
        $messages[] = ['role' => 'customer', 'content' => $message, 'at' => $now];
        $messages[] = ['role' => 'assistant', 'content' => $output->assistantMessage, 'at' => $now];

        $newState = [
            'messages' => $messages,
            'phase' => $output->conversationState['phase'] ?? ($state['phase'] ?? 'idle'),
            'last_ai_output' => $output->toArray(),
        ];

        $conversation->update([
            'state' => $newState,
            'last_message_at' => now(),
        ]);

        $conversation->refresh()->load(['customer', 'cart.items.product', 'cart.items.variant']);

        return [
            'output' => $output,
            'cart_result' => $cartResult,
            'conversation' => $conversation,
        ];
    }

    public function findConversation(string $id): Conversation
    {
        $this->requireTenant();

        return Conversation::with(['customer', 'cart.items.product', 'cart.items.variant'])->findOrFail($id);
    }

    private function requireTenant(): void
    {
        if (! app(CurrentTenant::class)->check()) {
            throw new InvalidArgumentException('A tenant context is required for conversation operations.');
        }
    }
}
