<?php

namespace Tests\Unit;

use App\Support\ClockTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClockTimeTest extends TestCase
{
    #[DataProvider('messages')]
    public function test_it_reads_the_time_a_customer_wrote(string $text, ?string $expected): void
    {
        $this->assertSame($expected, ClockTime::fromText($text));
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function messages(): array
    {
        return [
            'english 24 hour' => ['Please book a wash tomorrow at 10:00', '10:00'],
            'single digit hour' => ['book me at 9:30', '09:30'],
            'french 10h00' => ['Réservez-moi demain à 10h00', '10:00'],
            'french 10h30' => ['demain à 10h30 svp', '10:30'],
            'french 10h' => ['Je viens à 10h', '10:00'],
            'arabic with western digits' => ['احجز لي غداً الساعة 10:00', '10:00'],
            'arabic with arabic digits' => ['احجز لي غداً الساعة ١٠:٣٠', '10:30'],
            'pm' => ['book it for 3 pm', '15:00'],
            'am' => ['book it for 11am', '11:00'],
            'noon pm is 12' => ['at 12 pm', '12:00'],
            'midnight am is 0' => ['at 12 am', '00:00'],
            'no time' => ['book a haircut tomorrow', null],
            'a date is not a time' => ['book on 2026-10-08', null],
            'a price is not a time' => ['it costs 10.30$', null],
            'two different times are unclear' => ['10:00 or 11:00?', null],
            'the same time twice is fine' => ['10:00, yes 10:00', '10:00'],
            'out of range' => ['at 25:00', null],
        ];
    }
}
