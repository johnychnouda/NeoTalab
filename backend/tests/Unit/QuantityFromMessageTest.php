<?php

namespace Tests\Unit;

use App\Services\AI\CartEditsFromMessage;
use App\Services\AI\DTO\CartAction;
use App\Services\AI\DTO\CartLineSnapshot;
use App\Services\AI\DTO\CatalogProductSnapshot;
use App\Services\AI\QuantityFromMessage;
use Tests\TestCase;

class QuantityFromMessageTest extends TestCase
{
    private function catalog(): array
    {
        return [
            new CatalogProductSnapshot('p-beef', 'Shawarma Beef', 'شاورما لحم', 'Shawarma boeuf', '5.50', true),
            new CatalogProductSnapshot('p-falafel', 'Falafel', 'فلافل', 'Falafel', '3.00', true),
        ];
    }

    private function add(string $productId, int $quantity = 1): CartAction
    {
        return new CartAction(action: 'add', productId: $productId, quantity: $quantity);
    }

    private function quantityAfter(string $message, string $productId, int $aiQuantity = 1): int
    {
        $actions = (new QuantityFromMessage)->correct($message, [$this->add($productId, $aiQuantity)], $this->catalog());

        return $actions[0]->quantity;
    }

    public function test_a_number_before_the_name_is_the_quantity(): void
    {
        $this->assertSame(2, $this->quantityAfter('2 shawarma beef', 'p-beef'));
        $this->assertSame(2, $this->quantityAfter('2x shawarma beef', 'p-beef'));
    }

    public function test_a_number_after_the_name_with_x_is_the_quantity(): void
    {
        $this->assertSame(3, $this->quantityAfter('shawarma beef x3', 'p-beef'));
        $this->assertSame(3, $this->quantityAfter('shawarma beef × 3', 'p-beef'));
    }

    public function test_arabic_digits_and_arabic_names_work(): void
    {
        $this->assertSame(2, $this->quantityAfter('٢ شاورما لحم', 'p-beef'));
        $this->assertSame(4, $this->quantityAfter('4 فلافل', 'p-falafel'));
    }

    public function test_french_number_words_work(): void
    {
        $this->assertSame(2, $this->quantityAfter('deux falafels', 'p-falafel'));
        $this->assertSame(3, $this->quantityAfter('3 shawarma boeuf', 'p-beef'));
    }

    public function test_the_quantity_is_only_read_for_the_product_it_belongs_to(): void
    {
        $message = '2 shawarma beef and 1 falafel';

        $this->assertSame(2, $this->quantityAfter($message, 'p-beef'));
        $this->assertSame(1, $this->quantityAfter($message, 'p-falafel'));
    }

    public function test_a_message_without_a_quantity_leaves_the_action_alone(): void
    {
        $this->assertSame(1, $this->quantityAfter('shawarma beef please', 'p-beef'));
    }

    public function test_it_never_lowers_a_quantity_the_ai_already_set(): void
    {
        $this->assertSame(5, $this->quantityAfter('2 shawarma beef', 'p-beef', 5));
    }

    public function test_other_actions_are_not_changed(): void
    {
        $remove = new CartAction(action: 'remove', cartItemId: 'line-1');

        $actions = (new QuantityFromMessage)->correct('2 falafel', [$remove], $this->catalog());

        $this->assertSame($remove, $actions[0]);
    }

    public function test_a_shorter_name_inside_a_longer_product_is_not_counted(): void
    {
        $catalog = [
            new CatalogProductSnapshot('p-falafel', 'Falafel', null, null, '3.00', true),
            new CatalogProductSnapshot('p-plate', 'Falafel Plate', null, null, '6.00', true),
        ];

        // "2 falafel plate" is 2 plates: the plain falafel must not get the 2.
        $this->assertSame(1, (new QuantityFromMessage)->correct('2 falafel plate', [$this->add('p-falafel')], $catalog)[0]->quantity);
        $this->assertSame(2, (new QuantityFromMessage)->correct('2 falafel plate', [$this->add('p-plate')], $catalog)[0]->quantity);
    }

    private function line(string $id, string $productId, int $quantity): CartLineSnapshot
    {
        return new CartLineSnapshot($id, $productId, null, null, $quantity, '3.00', [], '3.00');
    }

    public function test_make_it_a_number_changes_the_line_quantity(): void
    {
        $actions = (new CartEditsFromMessage)->apply('actually make it 3 falafel', [], [$this->line('line-1', 'p-falafel', 1)], $this->catalog());

        $this->assertCount(1, $actions);
        $this->assertSame('update', $actions[0]->action);
        $this->assertSame('line-1', $actions[0]->cartItemId);
        $this->assertSame(3, $actions[0]->quantity);
    }

    public function test_a_change_drops_the_ai_add_for_the_same_product(): void
    {
        $actions = (new CartEditsFromMessage)->apply(
            'actually make it 3 falafel',
            [$this->add('p-falafel', 3)],
            [$this->line('line-1', 'p-falafel', 1)],
            $this->catalog(),
        );

        $this->assertCount(1, $actions);
        $this->assertSame('update', $actions[0]->action);
    }

    public function test_remove_the_falafel_removes_its_line_and_keeps_the_others(): void
    {
        $actions = (new CartEditsFromMessage)->apply(
            'remove the falafel',
            [],
            [$this->line('line-1', 'p-falafel', 1), $this->line('line-2', 'p-beef', 1)],
            $this->catalog(),
        );

        $this->assertCount(1, $actions);
        $this->assertSame('remove', $actions[0]->action);
        $this->assertSame('line-1', $actions[0]->cartItemId);
    }

    public function test_a_plain_add_with_no_change_word_is_left_alone(): void
    {
        $actions = (new CartEditsFromMessage)->apply(
            '2 falafel',
            [$this->add('p-falafel', 2)],
            [$this->line('line-1', 'p-falafel', 1)],
            $this->catalog(),
        );

        $this->assertCount(1, $actions);
        $this->assertSame('add', $actions[0]->action);
    }
}
