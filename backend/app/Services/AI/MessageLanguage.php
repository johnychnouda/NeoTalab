<?php

namespace App\Services\AI;

/**
 * Which language a short chat message is written in, without the AI: Arabic script, French, English,
 * or Arabizi (Lebanese Arabic in Latin letters). Used to make every reply follow the customer's
 * language, whatever the shop's language is. Null when the message has no clear language
 * (an emoji, a number, a product name alone).
 */
final class MessageLanguage
{
    private const FRENCH = ['bonjour', 'bonsoir', 'salut', 'merci', 'je', 'voudrais', 'veux', 'vous', 'votre', 'nous', 'avec', 'pour',
        'combien', 'est', 'quelle', 'quel', 'heure', 'oui', 'non', 'svp', 'stp', 'plait', 'commande', 'livraison', 'fermez', 'ouvert',
        'avez', 'pouvez', 'moi', 'une', 'des', 'les', 'du', 'au', 'aux', 'et', 'mais', 'aussi', 'encore', 'plutôt', 'désolé'];

    private const ENGLISH = ['hi', 'hello', 'hey', 'thanks', 'thank', 'please', 'want', 'would', 'like', 'i', "i'd", "i'm", 'my', 'you',
        'your', 'the', 'and', 'with', 'what', 'how', 'much', 'when', 'where', 'do', 'does', 'have', 'can', 'is', 'are', 'time', 'close',
        'open', 'delivery', 'order', 'yes', 'no', 'please', 'actually', 'make', 'it', 'add', 'remove', 'today', 'tomorrow', 'book'];

    private const ARABIZI = ['marhaba', 'mar7aba', 'baddi', 'bade', 'badde', 'bddi', 'shou', 'shu', 'chou', 'kifak', 'kifik', 'keefak',
        'ahla', 'yalla', 'yislamo', 'yeslamo', 'tayeb', 'mnih', 'kteer', 'ktir', 'hal2', 'halla2', 'wen', 'wein', 'fi', 'ma3', 'la2',
        'eh', 'ya3ne', 'ya3ni', 'lyom', 'bukra', 'shukran', 'merci', 'habibi', 'baddak', 'baddik', 'kam', 'adesh', 'addesh', 'ade'];

    /** @return 'ar'|'fr'|'en'|'arabizi'|null */
    public static function detect(string $text): ?string
    {
        $arabic = preg_match_all('/\p{Arabic}/u', $text);
        $latin = preg_match_all('/[A-Za-zÀ-ÿ]/u', $text);
        if ($arabic === 0 && $latin === 0) {
            return null;
        }
        if ($arabic >= $latin) {
            return 'ar';
        }

        $words = preg_split('/[^a-zà-ÿ0-9\']+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $score = fn (array $list) => count(array_intersect($words, $list));
        // Arabizi writes Arabic sounds with digits inside words: "man2oushe", "3a", "7elo".
        $arabizi = $score(self::ARABIZI) + count(preg_grep('/[a-z][2357][a-z]|^[2357][a-z]/', $words) ?: []);
        $fr = $score(self::FRENCH);
        $en = $score(self::ENGLISH);

        if ($arabizi > 0 && $arabizi >= max($fr, $en)) {
            return 'arabizi';
        }
        if ($fr > $en) {
            return 'fr';
        }
        if ($en > $fr) {
            return 'en';
        }

        return null;
    }

    /** True when a reply is clearly in another language than the customer's message. */
    public static function mismatch(?string $customer, string $reply): bool
    {
        if ($customer === null || trim($reply) === '') {
            return false;
        }
        $answer = self::detect($reply);
        if ($answer === null) {
            return false;
        }

        return match ($customer) {
            'ar' => $answer !== 'ar',
            'arabizi' => $answer === 'ar',
            default => $answer !== $customer && $answer !== 'arabizi',
        };
    }
}
