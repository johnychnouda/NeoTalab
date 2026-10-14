<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Services\WhatsApp\ShopTemplateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncShopTemplates extends Command
{
    protected $signature = 'templates:sync';

    protected $description = "Submit NeoTalab's WhatsApp templates to connected shops and refresh their approval status";

    public function handle(ShopTemplateService $templates): int
    {
        foreach (Merchant::query()->where('status', 'active')->get() as $merchant) {
            try {
                $templates->sync($merchant);
            } catch (\Throwable $e) {
                Log::warning('Template sync failed', ['merchant_id' => $merchant->id, 'error' => $e->getMessage()]);
            }
        }

        return self::SUCCESS;
    }
}
