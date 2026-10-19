<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The shop's address part (/backoffice/{slug}). Arabic names are written as Latin letters that
 * keep the vowel letters (ا و ي), so "الملكة والأمير" reads as "almlka-walamyr" instead of
 * losing every vowel. Short vowels are not written in Arabic, so this is a readable guess.
 */
class ShopSlug
{
    public const PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    private const ARABIC = [
        'أ' => 'a', 'إ' => 'i', 'آ' => 'a', 'ا' => 'a', 'ب' => 'b', 'ت' => 't', 'ث' => 'th',
        'ج' => 'j', 'ح' => 'h', 'خ' => 'kh', 'د' => 'd', 'ذ' => 'dh', 'ر' => 'r', 'ز' => 'z',
        'س' => 's', 'ش' => 'sh', 'ص' => 's', 'ض' => 'd', 'ط' => 't', 'ظ' => 'z', 'ع' => 'a',
        'غ' => 'gh', 'ف' => 'f', 'ق' => 'q', 'ك' => 'k', 'ل' => 'l', 'م' => 'm', 'ن' => 'n',
        'ه' => 'h', 'و' => 'w', 'ي' => 'y', 'ى' => 'a', 'ة' => 'a', 'ء' => '', 'ؤ' => 'w', 'ئ' => 'y',
    ];

    public static function from(string $name): string
    {
        // Harakat (short-vowel marks) are rarely typed, and when they are they must not split the word.
        $name = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $name) ?? $name;
        $romanized = strtr($name, self::ARABIC);

        return Str::slug($romanized);
    }
}
