<?php

namespace App\Jobs;

use App\Services\WhatsApp\TransientWhatsAppException;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * A message from NeoTalab's own number (join answer, shop login, billing notice, report, owner alert) that Meta could
 * not take (outage, rate limit, its "something went wrong"): sent again after 15 s, 1 min, 5 min and 15 min.
 */
class RetryPlatformSend implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @param  array<string, mixed>  $message
     */
    public function __construct(public string $toPhone, public array $message) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 300, 900];
    }

    public function handle(WhatsAppService $whatsapp): void
    {
        try {
            $whatsapp->sendPlatformNow($this->toPhone, $this->message);
        } catch (TransientWhatsAppException $e) {
            throw $e; // the queue tries again later
        } catch (RuntimeException $e) {
            Log::error('Platform WhatsApp retry gave up: Meta refused the message', ['error' => $e->getMessage()]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Platform WhatsApp message could not be delivered after 5 tries', ['error' => $e->getMessage()]);
    }
}
