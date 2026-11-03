<?php

namespace App\Services\AI;

use App\Services\AI\DTO\CartAction;
use App\Services\AI\DTO\CatalogProductSnapshot;

/**
 * The assistant sometimes adds "Sketchbook Large" when the customer wrote "2 Sketchbook" and the shop sells both as
 * separate items. When the customer's words name a product exactly and say nothing of the one the assistant chose,
 * the product named wins (only when its name is part of the chosen one's, so a different item is never swapped in).
 */
final class ExactItemFromMessage
{
    /**
     * @param  array<int, CartAction>  $actions
     * @param  array<int, CatalogProductSnapshot>  $catalog
     * @return array<int, CartAction>
     */
    public function correct(string $message, array $actions, array $catalog): array
    {
        $text = ProductMentions::normalise($message);
        $allNames = QuantityFromMessage::allNames($catalog);

        return array_map(function (CartAction $action) use ($text, $catalog, $allNames) {
            if ($action->action !== 'add' || ! $action->productId) {
                return $action;
            }
            $chosen = collect($catalog)->firstWhere('id', $action->productId);
            if (! $chosen || ProductMentions::mentions($text, QuantityFromMessage::namesOf($chosen), $allNames)) {
                return $action;
            }

            $chosenNames = QuantityFromMessage::namesOf($chosen);
            $named = collect($catalog)
                ->filter(fn (CatalogProductSnapshot $p) => $p->id !== $chosen->id && $p->isOrderable
                    && ProductMentions::mentions($text, QuantityFromMessage::namesOf($p), $allNames)
                    && collect(QuantityFromMessage::namesOf($p))->contains(fn (string $n) => collect($chosenNames)->contains(fn (string $c) => str_contains($c, $n))))
                ->values();

            return $named->count() === 1 ? $action->withProduct($named->first()->id) : $action;
        }, $actions);
    }
}
