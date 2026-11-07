<?php

namespace Tests\Feature;

use App\Services\AI\Translation\OpenAiTextTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A customer's short note is translated for the team, kept for a week, and never breaks an order message. */
class OpenAiTextTranslatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['ai.openai.api_key' => 'sk-test', 'ai.openai.base_url' => 'https://api.test/v1', 'ai.openai.model' => 'test-model']);
    }

    private function answer(string $content, int $status = 200): void
    {
        Http::fake(['api.test/*' => Http::response(['model' => 'test-model', 'choices' => [['message' => ['content' => $content]]], 'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 5]], $status)]);
    }

    public function test_it_returns_the_translation(): void
    {
        $this->answer("  \"بدون بصل\"\n");

        $this->assertSame('بدون بصل', app(OpenAiTextTranslator::class)->translate('no onions', 'ar'));
        Http::assertSent(fn (Request $r) => str_contains($r['messages'][0]['content'], 'Arabic') && $r['messages'][1]['content'] === 'no onions');
    }

    public function test_a_note_already_in_that_language_gives_nothing(): void
    {
        $this->answer('SAME');

        $this->assertNull(app(OpenAiTextTranslator::class)->translate('بدون بصل', 'ar'));
    }

    public function test_the_same_note_is_asked_only_once(): void
    {
        $this->answer('بدون بصل');
        $translator = app(OpenAiTextTranslator::class);

        $translator->translate('no onions', 'ar');
        $translator->translate('no onions', 'ar');

        Http::assertSentCount(1);
    }

    public function test_a_failed_call_gives_nothing_and_is_tried_again_next_time(): void
    {
        Http::fake(['api.test/*' => Http::sequence()
            ->push(['error' => 'down'], 500)
            ->push(['model' => 'test-model', 'choices' => [['message' => ['content' => 'بدون بصل']]]], 200)]);
        $translator = app(OpenAiTextTranslator::class);

        $this->assertNull($translator->translate('no onions', 'ar'));
        $this->assertSame('بدون بصل', $translator->translate('no onions', 'ar'));
    }

    public function test_an_unknown_language_or_empty_note_is_never_sent(): void
    {
        Http::fake();
        $translator = app(OpenAiTextTranslator::class);

        $this->assertNull($translator->translate('no onions', 'de'));
        $this->assertNull($translator->translate('   ', 'ar'));
        Http::assertNothingSent();
    }
}
