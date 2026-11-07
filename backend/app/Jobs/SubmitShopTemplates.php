<?php

namespace App\Jobs;

use App\Models\Merchant;
use App\Services\WhatsApp\ShopTemplateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sends a newly connected shop's message templates to Meta. About a hundred calls to Meta, so it runs
 * on the queue: inside the signup request it ran past the 30 second limit and failed the signup.
 * The hourly templates:sync sends whatever this misses.
 */
class SubmitShopTemplates implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $merchantId) {}

    public function handle(ShopTemplateService $templates): void
    {
        $merchant = Merchant::find($this->merchantId);
        if (! $merchant) {
            return;
        }

        try {
            $templates->sync($merchant);
        } catch (\Throwable $e) {
            Log::warning('Template submission after signup failed', ['merchant_id' => $merchant->id, 'error' => $e->getMessage()]);
        }
    }
}
