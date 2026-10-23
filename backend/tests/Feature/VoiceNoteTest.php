<?php

namespace Tests\Feature;

use App\Jobs\CheckLateOrder;
use App\Jobs\CheckUnacceptedOrder;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\WhatsAppMessage;
use App\Services\AI\Speech\OpenAiSpeechToText;
use App\Services\WhatsApp\WhatsAppInboundService;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class VoiceNoteTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Merchant $merchant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['whatsapp.platform_access_token' => 'test-token']);
        Bus::fake([CheckUnacceptedOrder::class, CheckLateOrder::class]);
        [$this->merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['name' => 'Foren', 'currency' => 'USD', 'settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto']]);
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Chicken shawarma', 'price' => 5]);
    }

    /** Meta: media id → download URL → the audio bytes; everything else is a sent message. */
    private function fakeMeta(bool $mediaOk = true): void
    {
        Http::fake(function (Request $r) use ($mediaOk) {
            if (str_ends_with($r->url(), '/AUDIO1')) {
                return $mediaOk
                    ? Http::response(['url' => 'https://lookaside.fbsbx.com/voice/AUDIO1', 'mime_type' => 'audio/ogg; codecs=opus', 'file_size' => 2048])
                    : Http::response(['error' => ['message' => 'gone']], 404);
            }
            if (str_contains($r->url(), 'lookaside.fbsbx.com')) {
                return Http::response('OggS-fake-audio', 200, ['Content-Type' => 'audio/ogg']);
            }

            return Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]]);
        });
    }

    private function inbound(array $message): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [array_merge(['id' => 'wamid.IN'.(++$this->seq), 'from' => '96170123456'], $message)],
        ]]]]]]);
    }

    private function voice(): void
    {
        $this->inbound(['type' => 'audio', 'audio' => ['id' => 'AUDIO1', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]]);
    }

    /** @return list<array<string, mixed>> */
    private function sent(): array
    {
        return Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages'))
            ->map(fn (Request $r) => $r->data())->values()->all();
    }

    private function body(array $message): string
    {
        return $message['text']['body'] ?? $message['interactive']['body']['text'] ?? '';
    }

    public function test_a_call_to_the_shop_number_gets_a_message_once_not_silence(): void
    {
        $this->fakeMeta();
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Zaatar', 'price' => 1]);
        $call = fn () => app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['field' => 'calls', 'value' => [
            'metadata' => ['phone_number_id' => '555'],
            'calls' => [['id' => 'wacid.'.uniqid(), 'from' => '96170123456', 'event' => 'connect', 'direction' => 'USER_INITIATED']],
        ]]]]]]);

        $call();
        $call(); // calling again right away: no second message

        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $this->assertSame('+96170123456', '+'.ltrim($sent[0]['to'], '+'));
        $this->assertStringContainsString("can't take calls", $this->body($sent[0]));
    }

    public function test_a_voice_note_is_transcribed_and_answered_like_a_typed_message(): void
    {
        $this->fakeMeta();
        config(['ai.speech.fake_transcript' => 'marhaba']);

        $this->voice();

        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $this->assertSame('list', $sent[0]['interactive']['type'], 'greeting comes with the menu, same as typing "marhaba"');
        $this->assertSame('marhaba', WhatsAppMessage::query()->withoutGlobalScopes()->where('direction', 'inbound')->first()->payload['transcript']);
    }

    public function test_an_unclear_voice_note_gets_a_polite_reply_and_the_menu(): void
    {
        $this->fakeMeta();
        config(['ai.speech.fake_transcript' => null]);

        $this->voice();

        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $this->assertSame(__('whatsapp.voice_failed', [], 'en'), $this->body($sent[0]));
        $this->assertSame('list', $sent[0]['interactive']['type']);
    }

    public function test_a_voice_note_that_cannot_be_downloaded_still_gets_an_answer(): void
    {
        $this->fakeMeta(mediaOk: false);
        config(['ai.speech.fake_transcript' => 'marhaba']);

        $this->voice();

        $this->assertSame(__('whatsapp.voice_failed', [], 'en'), $this->body($this->sent()[0]));
    }

    public function test_photos_and_stickers_get_a_polite_reply_and_the_menu(): void
    {
        $this->fakeMeta();

        $this->inbound(['type' => 'sticker', 'sticker' => ['id' => 'ST1']]);
        $this->inbound(['type' => 'image', 'image' => ['id' => 'IMG1']]);

        $sent = $this->sent();
        $this->assertCount(2, $sent);
        foreach ($sent as $message) {
            $this->assertSame(__('whatsapp.media_unsupported', [], 'en'), $this->body($message));
            $this->assertSame('list', $message['interactive']['type']);
        }
    }

    public function test_without_a_menu_the_note_is_sent_as_plain_text(): void
    {
        $this->fakeMeta();
        Product::query()->withoutGlobalScopes()->delete();

        $this->inbound(['type' => 'image', 'image' => ['id' => 'IMG1']]);

        $this->assertSame(__('whatsapp.media_unsupported', [], 'en'), $this->sent()[0]['text']['body']);
    }

    public function test_a_closed_shop_answers_closed_without_transcribing(): void
    {
        $this->fakeMeta();
        $this->merchant->update(['settings' => array_merge($this->merchant->settings, ['mode' => 'closed'])]);

        $this->voice();

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/AUDIO1'));
        $this->assertSame(__('whatsapp.closed', ['shop' => 'Foren'], 'en'), $this->sent()[0]['text']['body']);
    }

    public function test_a_paused_bot_ignores_voice_notes(): void
    {
        $this->fakeMeta();
        $this->merchant->update(['settings' => array_merge($this->merchant->settings, ['mode' => 'manual'])]);

        $this->voice();

        Http::assertNothingSent();
    }

    public function test_openai_transcription_sends_the_audio_and_records_the_cost(): void
    {
        config(['ai.openai.api_key' => 'sk-test', 'ai.openai.base_url' => 'https://api.openai.com/v1', 'ai.speech.model' => 'gpt-4o-mini-transcribe']);
        Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response([
            'text' => ' بدي ٢ شاورما دجاج ',
            'usage' => ['type' => 'tokens', 'input_tokens' => 1000, 'output_tokens' => 20],
        ])]);

        $text = app(OpenAiSpeechToText::class)->transcribe('OggS', 'audio/ogg; codecs=opus', 'Foren. Chicken shawarma', $this->merchant->id);

        $this->assertSame('بدي ٢ شاورما دجاج', $text);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.openai.com/v1/audio/transcriptions'
            && $r->isMultipart()
            && collect($r->data())->contains(fn ($part) => $part['name'] === 'model' && $part['contents'] === 'gpt-4o-mini-transcribe'));
        $row = DB::table('ai_usage')->where('merchant_id', $this->merchant->id)->first();
        $this->assertEqualsWithDelta(0.0031, (float) $row->cost_usd, 0.0001, '1000 audio tokens × $3/M + 20 × $5/M');
    }
}
