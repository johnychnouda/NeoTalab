<?php

namespace App\Services;

use App\Models\Order;

/**
 * The questions after an order, one tap each: how was the order, how was the service and, for a delivery with a
 * driver, how the delivery person treated the customer. Great counts 5, Okay 3, Bad 1. A Bad answer alerts the shop.
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
        return ['order', 'service', ...($order->fulfillment === 'delivery' && $order->driver_id ? ['delivery'] : [])];
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
     * The question as a WhatsApp message with Great / Okay / Bad. $lead is shown above it (the delivered message).
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

        $buttons = [self::GREAT => 'great', self::OKAY => 'okay', self::BAD => 'bad'];

        return ['type' => 'interactive', 'interactive' => [
            'type' => 'button',
            'body' => ['text' => mb_substr(($lead !== null && $lead !== '' ? $lead."\n\n" : '').$text, 0, 1024)],
            'action' => ['buttons' => array_map(
                fn ($value, $key) => ['type' => 'reply', 'reply' => ['id' => "rt:{$order->id}:{$question}:{$value}", 'title' => mb_substr(__("whatsapp.rating.{$key}", [], $locale), 0, 20)]],
                array_keys($buttons),
                $buttons,
            )],
        ]];
    }
}
