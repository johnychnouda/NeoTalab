<?php

namespace App\Services;

use App\Models\Order;

/**
 * One question after an order: Great (5), Okay (3) or Bad (1). Default is conversational (words),
 * with optional WhatsApp buttons when whatsapp.conversational is off. Okay/Bad ask what went wrong.
 */
final class RatingFlow
{
    public const GREAT = 5;

    public const OKAY = 3;

    public const BAD = 1;

    /** question => orders column */
    public const COLUMNS = ['order' => 'rating', 'service' => 'rating_service', 'delivery' => 'rating_delivery'];

    /** @return list<string> */
    public function questions(Order $order): array
    {
        return ['order'];
    }

    /** The first question without an answer, or null when the customer has answered them all. */
    public function next(Order $order): ?string
    {
        foreach ($this->questions($order) as $question) {
            if ($order->{self::COLUMNS[$question]} === null) {
                return $question;
            }
        }

        return null;
    }

    public function save(Order $order, string $question, int $value): void
    {
        $order->forceFill([self::COLUMNS[$question] => $value, 'rated_at' => now()])->save();
    }

    /**
     * Great / Okay / Bad from typed words (EN/AR/FR); null otherwise.
     */
    public static function typedScore(string $text): ?int
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return null;
        }
        foreach (['great', 'excellent', 'amazing', 'perfect', 'super', 'ممتاز', 'رائع', 'تمام', 'parfait', 'génial', 'genial'] as $word) {
            if ($t === $word || str_contains($t, $word)) {
                return self::GREAT;
            }
        }
        foreach (['okay', 'ok', 'fine', 'average', 'meh', 'عادي', 'وسط', 'moyen', 'correct'] as $word) {
            if ($t === $word || str_contains($t, $word)) {
                return self::OKAY;
            }
        }
        foreach (['bad', 'terrible', 'awful', 'poor', 'سيء', 'سيئ', 'مش منيح', 'mauvais', 'nul'] as $word) {
            if ($t === $word || str_contains($t, $word)) {
                return self::BAD;
            }
        }

        return null;
    }

    /**
     * The question as WhatsApp text (conversational) or Great / Okay / Bad buttons.
     * $lead is shown above it (the delivered message).
     *
     * @return array<string, mixed>
     */
    public function message(Order $order, string $question, string $locale, ?string $lead = null): array
    {
        $questions = $this->questions($order);
        $text = __("whatsapp.rating.q_{$question}", [
            'n' => (int) array_search($question, $questions, true) + 1,
            'total' => count($questions),
            'driver' => $order->driver?->name ?? '',
        ], $locale);

        $body = mb_substr(($lead !== null && $lead !== '' ? $lead."\n\n" : '').$text, 0, 1024);

        if (config('whatsapp.conversational', true)) {
            return ['type' => 'text', 'text' => $body];
        }

        $buttons = [self::GREAT => 'great', self::OKAY => 'okay', self::BAD => 'bad'];

        return ['type' => 'interactive', 'interactive' => [
            'type' => 'button',
            'body' => ['text' => $body],
            'action' => ['buttons' => array_map(
                fn ($value, $key) => ['type' => 'reply', 'reply' => ['id' => "rt:{$order->id}:{$question}:{$value}", 'title' => mb_substr(__("whatsapp.rating.{$key}", [], $locale), 0, 20)]],
                array_keys($buttons),
                $buttons,
            )],
        ]];
    }
}
