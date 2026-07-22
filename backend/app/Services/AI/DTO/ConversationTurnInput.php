<?php

namespace App\Services\AI\DTO;

/**
 * Input to a ChatProvider for one conversation turn.
 */
final readonly class ConversationTurnInput
{
    /**
     * @param  array<int, array{role:string,content:string}>  $history
     * @param  array<int, CatalogProductSnapshot>  $catalog
     * @param  array<int, CartLineSnapshot>  $cartLines
     */
    public function __construct(
        public string $message,
        public string $locale,
        public array $history,
        public array $catalog,
        public array $cartLines,
        public string $conversationPhase = 'idle',
    ) {}
}
