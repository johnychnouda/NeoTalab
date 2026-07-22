<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\MerchantWhatsAppCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class WhatsAppWebhookController extends Controller
{
    /**
     * Meta webhook verification (GET).
     */
    public function verify(Request $request): Response|HttpResponse
    {
        $mode = $request->query('hub_mode') ?? $request->query('hub.mode');
        $token = $request->query('hub_verify_token') ?? $request->query('hub.verify_token');
        $challenge = $request->query('hub_challenge') ?? $request->query('hub.challenge');

        if ($mode === 'subscribe' && hash_equals(MerchantWhatsAppCredentials::verifyToken(), (string) $token)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * Meta inbound webhook (POST) — acknowledge fast, process async.
     */
    public function receive(Request $request, WhatsAppService $whatsapp): JsonResponse
    {
        $raw = $request->getContent();
        $signature = $request->header('X-Hub-Signature-256');

        if (! $whatsapp->isValidSignature($raw, $signature)) {
            return response()->json(['error' => 'invalid_signature'], 403);
        }

        /** @var array<string,mixed> $payload */
        $payload = json_decode($raw, true) ?? [];

        ProcessWhatsAppWebhook::dispatch($payload);

        return response()->json(['ok' => true]);
    }
}
