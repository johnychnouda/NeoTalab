<?php

namespace Tests\Unit;

use App\Support\TimeRequest;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** A customer writes the time they want ("tomorrow at 6", "demain à 18h", "بكرا الساعة 6 مساء"). */
class TimeRequestTest extends TestCase
{
    /** Tuesday 6 October 2026, noon in Beirut. */
    private function now(): Carbon
    {
        return Carbon::parse('2026-10-06 12:00', 'Asia/Beirut');
    }

    /** @return array<string, array{0: string, 1: ?string, 2: ?int, 3: int, 4: bool}> text, date, hour, minute, ambiguous */
    public static function understood(): array
    {
        return [
            'en tomorrow at 6' => ['Can I get it tomorrow at 6?', '2026-10-07', 6, 0, true],
            'en tomorrow 6pm' => ['tomorrow 6pm', '2026-10-07', 18, 0, false],
            'en tomorrow 6 pm spaced' => ['tomorrow at 6 pm', '2026-10-07', 18, 0, false],
            'en 24 hour clock' => ['tomorrow at 18:30', '2026-10-07', 18, 30, false],
            'en today 8 pm' => ['today at 8 pm', '2026-10-06', 20, 0, false],
            'en tonight' => ['tonight at 9', '2026-10-06', 21, 0, false],
            'en time only' => ['6:30 pm', null, 18, 30, false],
            'en at 7' => ['at 7', null, 7, 0, true],
            'en weekday' => ['friday at 7pm', '2026-10-09', 19, 0, false],
            'en day after tomorrow' => ['day after tomorrow 5pm', '2026-10-08', 17, 0, false],
            'en morning' => ['tomorrow at 9 in the morning', '2026-10-07', 9, 0, false],
            'en noon' => ['tomorrow at noon', '2026-10-07', 12, 0, false],
            'en 12 am is midnight' => ['today 12 am', '2026-10-06', 0, 0, false],
            'en day only' => ['tomorrow', '2026-10-07', null, 0, false],
            'fr demain 18h' => ['demain à 18h', '2026-10-07', 18, 0, false],
            'fr demain 18h30' => ['demain à 18h30', '2026-10-07', 18, 30, false],
            'fr ce soir' => ['ce soir à 8h', '2026-10-06', 20, 0, false],
            'fr heures' => ['demain à 6 heures du soir', '2026-10-07', 18, 0, false],
            'fr weekday' => ['vendredi 19h', '2026-10-09', 19, 0, false],
            'fr apres demain' => ['après-demain à 17h', '2026-10-08', 17, 0, false],
            'ar tomorrow evening' => ['بكرا الساعة 6 مساء', '2026-10-07', 18, 0, false],
            'ar arabic digits' => ['بكرة الساعة ٧', '2026-10-07', 7, 0, true],
            'ar half' => ['اليوم الساعة 8 ونص', '2026-10-06', 8, 30, true],
            'ar quarter' => ['بكرا الساعة 5 وربع', '2026-10-07', 5, 15, true],
            'ar day after tomorrow' => ['بعد بكرا الساعة 5 العصر', '2026-10-08', 17, 0, false],
            'ar weekday' => ['الجمعة الساعة 8 مساء', '2026-10-09', 20, 0, false],
            'ar day only' => ['بكرا', '2026-10-07', null, 0, false],
            'arabizi tomorrow' => ['bokra el sa3a 6', '2026-10-07', 6, 0, true],
            'arabizi pm' => ['bokra 8 pm', '2026-10-07', 20, 0, false],
            'arabizi day after' => ['ba3d bokra el sa3a 7', '2026-10-08', 7, 0, true],
        ];
    }

    #[DataProvider('understood')]
    public function test_a_written_time_is_understood(string $text, ?string $date, ?int $hour, int $minute, bool $ambiguous): void
    {
        $r = TimeRequest::parse($text, $this->now());

        $this->assertNotNull($r, $text);
        $this->assertSame([$date, $hour, $minute, $ambiguous], [$r['date'], $r['hour'], $r['minute'], $r['ambiguous']], $text);
    }

    /** @return array<string, array{0: string}> */
    public static function notATime(): array
    {
        return [
            'an item' => ['add 2 pepsi'],
            'a greeting' => ['hello'],
            'a lone number' => ['5'],
            'a yes' => ['yes'],
            'a request' => ['can you add a sandwich'],
            'a quantity' => ['I want 3'],
            'a drink name' => ['7 up please'],
            'a note' => ['extra spicy please'],
            'a question' => ['do you have chicken'],
            'arabic item' => ['بدي ٢ شاورما'],
            'french item' => ['je voudrais 2 pizzas'],
            'empty' => [''],
        ];
    }

    #[DataProvider('notATime')]
    public function test_other_messages_are_not_taken_for_a_time(string $text): void
    {
        $this->assertNull(TimeRequest::parse($text, $this->now()), $text);
    }
}
