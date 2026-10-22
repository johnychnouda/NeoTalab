<?php

namespace Tests\Unit;

use App\Support\Money;
use Tests\TestCase;

class MoneyFromAiTextTest extends TestCase
{
    public function test_usd_after_the_amount_becomes_the_dollar_sign_after_it(): void
    {
        $this->assertSame('Your falafel is 2.79$.', Money::fromAiText('Your falafel is 2.79 USD.'));
        $this->assertSame('Total 18.00$', Money::fromAiText('Total 18.00 US$'));
    }

    public function test_usd_before_the_amount_and_a_dollar_sign_before_it_are_rewritten(): void
    {
        $this->assertSame('Total 5.50$', Money::fromAiText('Total USD 5.50'));
        $this->assertSame('Total 5.50$', Money::fromAiText('Total $5.50'));
    }

    public function test_lira_and_text_without_money_are_left_alone(): void
    {
        $this->assertSame('That is 250,000 LBP, 2 items, at 9 PM.', Money::fromAiText('That is 250,000 LBP, 2 items, at 9 PM.'));
        $this->assertSame('5.50$ already', Money::fromAiText('5.50$ already'));
    }
}
