<?php

namespace App\Services\AI\Contracts;

/**
 * Turns a customer's voice note into text so it can be handled like a typed message.
 */
interface SpeechToText
{
    /**
     * @param  string  $audio  raw file bytes (WhatsApp voice notes are audio/ogg; codecs=opus)
     * @param  string  $hint  words the speaker is likely to use (shop name, menu items)
     * @return string|null the transcript, or null when nothing usable was heard
     */
    public function transcribe(string $audio, string $mimeType, string $hint = '', ?string $merchantId = null): ?string;
}
