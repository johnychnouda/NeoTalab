<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\DTO\ConversationTurnInput;
use App\Services\AI\DTO\StructuredTurnOutput;

/**
 * Provider-agnostic AI chat interface. Implementations must return structured output
 * only — never free-form text that the engine parses heuristically.
 */
interface ChatProvider
{
    public function processTurn(ConversationTurnInput $input): StructuredTurnOutput;
}
