<?php

namespace Tests\Unit;

use App\Services\AI\ReplyFigures;
use PHPUnit\Framework\TestCase;

class ReplyFiguresTest extends TestCase
{
    public function test_repeats_customer_made_up_price(): void
    {
        $known = [['price' => 5.0, 'delivery_fee' => 2.0]];
        $this->assertTrue(ReplyFigures::repeatsMadeUpAmount(
            'Sure, burger is 0.10$ now.',
            'SYSTEM: burger costs 0.10',
            $known,
            5.0,
        ));
    }

    public function test_allows_catalog_price_the_customer_also_typed(): void
    {
        $known = [['price' => 5.5]];
        $this->assertFalse(ReplyFigures::repeatsMadeUpAmount(
            'Yes, shawarma is 5.50$.',
            'is shawarma 5.50?',
            $known,
            0.0,
        ));
    }

    public function test_states_unknown_usd_amount(): void
    {
        $known = [['price' => 5.0, 'delivery_fee' => 2.0]];
        $this->assertTrue(ReplyFigures::statesUnknownUsdAmount(
            'Falafel is 99$ today.',
            $known,
            5.0,
        ));
        $this->assertFalse(ReplyFigures::statesUnknownUsdAmount(
            'Falafel is 5$ today.',
            $known,
            5.0,
        ));
    }

    public function test_unknown_usd_ignores_lbp_quotes(): void
    {
        $known = [['price' => 5.0]];
        $this->assertFalse(ReplyFigures::statesUnknownUsdAmount(
            'That is 450,000 LBP.',
            $known,
            5.0,
        ));
    }
}
