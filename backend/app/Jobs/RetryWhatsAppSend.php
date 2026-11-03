<?php

namespace App\Jobs;

use App\Models\Merchant;
use App\Services\WhatsApp\TransientWhatsAppException;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * A message to a customer or to the shop's team that Meta could not take (outage, rate limit): sent again after
 * 15 s, 1 min, 5 min and 15 min, so a short Meta problem does not cost the customer their answer.
 */
class RetryWhatsAppSend implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @param  array<string, mixed>  $message
     */
    public function __construct(public string $merchantId, public string $toPhone, public array $message) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 300, 900];
    }

    public function handle(WhatsAppService $whatsapp): void
    {
        $merchant = Merchant::query()->find($this->merchantId);
        if (! $merchant) {
            return;
        }

        $tenant = app(CurrentTenant::class);
        $previous = $tenant->get();
        $tenant->set($merchant);

        try {
            $whatsapp->sendToCustomerNow($merchant, $this->toPhone, $this->message);
        } catch (TransientWhatsAppException $e) {
            throw $e; // the queue tries again later
        } catch (RuntimeException $e) {
            Log::error('WhatsApp retry gave up: Meta refused the message', ['merchant_id' => $merchant->id, 'error' => $e->getMessage()]);
        } finally {
            $tenant->set($previous);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('WhatsApp message could not be delivered after 5 tries', ['merchant_id' => $this->merchantId, 'error' => $e->getMessage()]);
    }
}
