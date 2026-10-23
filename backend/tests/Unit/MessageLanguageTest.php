<?php

namespace Tests\Unit;

use App\Services\AI\MessageLanguage;
use PHPUnit\Framework\TestCase;

class MessageLanguageTest extends TestCase
{
    public function test_reads_the_customers_language(): void
    {
        $this->assertSame('en', MessageLanguage::detect("Hi, I'd like 2 zaatar manousheh please"));
        $this->assertSame('en', MessageLanguage::detect('what time do you close today?'));
        $this->assertSame('fr', MessageLanguage::detect('Bonjour, je voudrais 3 manouchés au zaatar'));
        $this->assertSame('ar', MessageLanguage::detect('مرحبا، بدي ٢ منقوشة زعتر'));
        $this->assertSame('arabizi', MessageLanguage::detect('marhaba, baddi 2 man2oushe zaatar'));
        $this->assertNull(MessageLanguage::detect('😀👍'));
        $this->assertNull(MessageLanguage::detect('2'));
    }

    public function test_a_reply_in_another_language_is_caught(): void
    {
        $this->assertTrue(MessageLanguage::mismatch('en', 'أحتاج أن أتحقق من القائمة لأخبرك'));
        $this->assertTrue(MessageLanguage::mismatch('en', "Nous fermons aujourd'hui à 23h00, merci pour votre commande"));
        $this->assertTrue(MessageLanguage::mismatch('ar', 'We close at 11 PM today'));
        $this->assertFalse(MessageLanguage::mismatch('en', "I've added 2 زعتر منقوشة to your order. Would you like to check out?"));
        $this->assertFalse(MessageLanguage::mismatch('arabizi', 'Tayeb, added 2 zaatar to your order!'));
        $this->assertFalse(MessageLanguage::mismatch(null, 'anything'));
    }
}
