<?php

namespace Tests\Unit;

use App\Services\AI\DTO\CartAction;
use App\Services\AI\DTO\CatalogProductSnapshot;
use App\Services\AI\ExactItemFromMessage;
use PHPUnit\Framework\TestCase;

class ExactItemFromMessageTest extends TestCase
{
    private function catalog(): array
    {
        $p = fn (string $id, string $name, bool $ok = true) => new CatalogProductSnapshot($id, $name, null, null, '5.00', $ok);

        return [$p('plain', 'Sketchbook'), $p('large', 'Sketchbook Large'), $p('pen', 'Pen')];
    }

    public function test_the_item_named_wins_over_a_longer_one_the_assistant_picked(): void
    {
        $out = (new ExactItemFromMessage)->correct('I want 2 Sketchbook', [new CartAction('add', 'large', 'v1', null, 2, ['m1'])], $this->catalog());

        $this->assertSame('plain', $out[0]->productId);
        $this->assertNull($out[0]->variantId);
        $this->assertSame([], $out[0]->modifierIds);
        $this->assertSame(2, $out[0]->quantity);
    }

    public function test_the_long_name_written_in_full_is_kept(): void
    {
        $out = (new ExactItemFromMessage)->correct('one sketchbook large please', [new CartAction('add', 'large')], $this->catalog());

        $this->assertSame('large', $out[0]->productId);
    }

    public function test_an_unrelated_item_is_never_swapped_in(): void
    {
        $out = (new ExactItemFromMessage)->correct('add a pen', [new CartAction('add', 'large')], $this->catalog());

        $this->assertSame('large', $out[0]->productId);
    }

    public function test_an_item_that_is_not_orderable_is_not_swapped_in(): void
    {
        $catalog = [new CatalogProductSnapshot('plain', 'Sketchbook', null, null, '5.00', false), new CatalogProductSnapshot('large', 'Sketchbook Large', null, null, '6.00', true)];
        $out = (new ExactItemFromMessage)->correct('2 sketchbook', [new CartAction('add', 'large')], $catalog);

        $this->assertSame('large', $out[0]->productId);
    }
}
