<?php

namespace App\Services\AI\Speech;

use App\Services\AI\Contracts\SpeechToText;
use App\Services\UsageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI audio transcription (gpt-4o-mini-transcribe by default). No language is forced:
 * Lebanese customers mix Arabic, English and French in one sentence.
 */
class OpenAiSpeechToText implements SpeechToText
{
    public function __construct(private readonly UsageService $usage) {}

    public function transcribe(string $audio, string $mimeType, string $hint = '', ?string $merchantId = null): ?string
    {
        $config = config('ai.openai');
        $model = (string) config('ai.speech.model');
        $extension = str_contains($mimeType, 'ogg') ? 'ogg' : (str_contains($mimeType, 'mpeg') ? 'mp3' : (str_contains($mimeType, 'mp4') || str_contains($mimeType, 'aac') ? 'm4a' : 'ogg'));

        $response = Http::withToken((string) $config['api_key'])
            ->timeout((int) $config['timeout'])
            ->attach('file', $audio, "voice.{$extension}")
            ->post(rtrim((string) $config['base_url'], '/').'/audio/transcriptions', array_filter([
                'model' => $model,
                'response_format' => 'json',
                'prompt' => $hint !== '' ? $hint : null,
            ]));

        if (! $response->successful()) {
            Log::warning('Voice note transcription failed', ['status' => $response->status(), 'merchant_id' => $merchantId]);

            return null;
        }

        $usage = $response->json('usage');
        if (is_array($usage) && isset($usage['input_tokens'])) {
            $this->usage->recordAi($merchantId, $model, [
                'prompt_tokens' => (int) $usage['input_tokens'],
                'completion_tokens' => (int) ($usage['output_tokens'] ?? 0),
            ]);
        }

        $text = trim((string) $response->json('text', ''));

        return $text !== '' ? $text : null;
    }
}
