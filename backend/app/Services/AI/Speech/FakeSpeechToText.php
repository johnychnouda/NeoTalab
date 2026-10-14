<?php

namespace App\Services\AI\Speech;

use App\Services\AI\Contracts\SpeechToText;

/**
 * Local/test driver: no API call. Hears config('ai.speech.fake_transcript') (null = nothing).
 */
class FakeSpeechToText implements SpeechToText
{
    public function transcribe(string $audio, string $mimeType, string $hint = '', ?string $merchantId = null): ?string
    {
        $text = config('ai.speech.fake_transcript');

        return is_string($text) && trim($text) !== '' ? trim($text) : null;
    }
}
