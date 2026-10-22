<?php

namespace App\Services\AI;

use App\Services\AI\DTO\CartAction;
use App\Services\AI\DTO\CartLineSnapshot;
use App\Services\AI\DTO\CatalogProductSnapshot;

/**
 * Handles changes to what is already in the cart, which the AI often misses:
 * "actually make it 3 falafel" sets that line to 3, and "remove the falafel" removes it.
 * A change needs a change word and a number; a removal needs a remove word and no number.
 */
final class CartEditsFromMessage
{
    private const CHANGE = '/\b(make it|make that|make them|change it|change that|actually|instead|rather|update|i meant|correction)\b|plutôt|en fait|finalement|changer|بدل|خليها|خلّيها|غيّر|غير/u';

    private const REMOVE = '/\b(remove|delete|take out|without|no more)\b|enl[eè]ve|enlever|retire|retirer|supprime|supprimer|احذف|امسح|شيل|بدون/u';

    /**
     * @param  array<int, CartAction>  $actions
     * @param  array<int, CartLineSnapshot>  $cartLines
     * @param  array<int, CatalogProductSnapshot>  $catalog
     * @return array<int, CartAction>
     */
    public function apply(string $message, array $actions, array $cartLines, array $catalog): array
    {
        $text = ProductMentions::normalise($message);
        $change = (bool) preg_match(self::CHANGE, $text);
        $remove = (bool) preg_match(self::REMOVE, $text);

        if (! $change && ! $remove) {
            return $actions;
        }

        $products = [];
        foreach ($catalog as $product) {
            $products[$product->id] = $product;
        }
        $allNames = QuantityFromMessage::allNames($catalog);

        $edits = [];
        $touched = [];

        foreach ($cartLines as $line) {
            $product = $products[$line->productId] ?? null;
            if (! $product) {
                continue;
            }

            $names = QuantityFromMessage::namesOf($product);
            if (! ProductMentions::mentions($text, $names, $allNames)) {
                continue;
            }

            $number = QuantityFromMessage::quantityFor($text, $product, $allNames);

            if ($remove && $number === null) {
                $edits[] = new CartAction(action: 'remove', cartItemId: $line->id);
                $touched[$line->productId] = true;
            } elseif ($change && $number !== null) {
                $touched[$line->productId] = true;
                if ($number !== $line->quantity) {
                    $edits[] = new CartAction(action: 'update', cartItemId: $line->id, quantity: $number);
                }
            }
        }

        if ($edits === []) {
            return $actions;
        }

        // The customer is changing this product, so an "add" for it in the same reply would duplicate it.
        $kept = array_filter($actions, fn (CartAction $a) => ! ($a->action === 'add' && isset($touched[$a->productId])));

        return array_merge($edits, array_values($kept));
    }
}
