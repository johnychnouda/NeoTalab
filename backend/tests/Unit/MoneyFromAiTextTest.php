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

    public function test_french_style_amounts_are_written_the_shop_way(): void
    {
        // gpt-6-luna in French (s44, 97 of 106 replies): a decimal comma, a space, and "US" or "USD" after the sign.
        $this->assertSame('La Summer Dress coûte 29.52$.', Money::fromAiText('La Summer Dress coûte 29,52$.'));
        $this->assertSame('La crème coûte 18.64$.', Money::fromAiText('La crème coûte 18,64 $.'));
        $this->assertSame('Les French Fries coûtent 34.43$.', Money::fromAiText('Les French Fries coûtent 34,43 $ US.'));
        $this->assertSame('Le Sketchbook coûte 10.46$.', Money::fromAiText('Le Sketchbook coûte 10,46 $ USD.'));
        $this->assertSame('2 Paint 4L à 23.18$ pièce.', Money::fromAiText('2 Paint 4L à 23,18 $ pièce.'));
    }

    public function test_a_sign_on_both_sides_and_the_arabic_word_become_one_sign(): void
    {
        // s44 (gpt-6-luna): "Se3ro $5$." came out as "5$$"; Arabic replies wrote "5 دولار".
        $this->assertSame('Se3ro 5$.', Money::fromAiText('Se3ro $5$.'));
        $this->assertSame('سعره 5$.', Money::fromAiText('سعره 5 دولار.'));
        $this->assertSame('بسعر 5.50$ للواحدة', Money::fromAiText('بسعر 5.50 دولارات للواحدة'));
    }

    public function test_a_number_after_an_amount_is_not_glued_to_it(): void
    {
        $this->assertSame('Total 5.50$ 2 items', Money::fromAiText('Total 5.50$ 2 items'));
        $this->assertSame('Total 5.50$, 2 items', Money::fromAiText('Total 5.50 $, 2 items'));
    }

    public function test_lira_and_text_without_money_are_left_alone(): void
    {
        $this->assertSame('That is 250,000 LBP, 2 items, at 9 PM.', Money::fromAiText('That is 250,000 LBP, 2 items, at 9 PM.'));
        $this->assertSame('5.50$ already', Money::fromAiText('5.50$ already'));
    }
}
