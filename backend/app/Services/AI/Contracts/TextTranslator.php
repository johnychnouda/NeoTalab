<?php

namespace App\Services\AI\Contracts;

interface TextTranslator
{
    /**
     * A short customer note ("no onions") in another language.
     *
     * @return string|null the text in $locale (en, ar, fr), or null when it is already in that language or cannot be translated
     */
    public function translate(string $text, string $locale, ?string $merchantId = null): ?string;
}
