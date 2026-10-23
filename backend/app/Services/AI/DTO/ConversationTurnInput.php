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
     * @param  array<string, mixed>  $shop  Merchant context: name, currency, hours, delivery zones
     */
    public function __construct(
        public string $message,
        public string $locale,
        public array $history,
        public array $catalog,
        public array $cartLines,
        public string $conversationPhase = 'idle',
        public array $shop = [],
        // Set when a first answer came back in another language than the customer's: en, fr, ar or arabizi.
        public ?string $replyLanguage = null,
    ) {}
}
