<?php

namespace App\Jobs;

use App\Services\WhatsApp\WhatsAppInboundService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppWebhook implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string,mixed>  $payload
     */
    public function __construct(public array $payload) {}

    public function handle(WhatsAppInboundService $inbound): void
    {
        try {
            $inbound->handle($this->payload);
        } catch (\Throwable $e) {
            Log::error('WhatsApp webhook job failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
