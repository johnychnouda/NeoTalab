<?php

namespace App\Services\AI\Translation;

use App\Services\AI\Contracts\TextTranslator;

/**
 * Local/test driver: no API call. Translates only what config('ai.translate.fake') lists, as
 * ['original' => ['ar' => 'translation']].
 */
class FakeTextTranslator implements TextTranslator
{
    public function translate(string $text, string $locale, ?string $merchantId = null): ?string
    {
        $found = config('ai.translate.fake')[$text][$locale] ?? null;

        return is_string($found) && $found !== '' ? $found : null;
    }
}
