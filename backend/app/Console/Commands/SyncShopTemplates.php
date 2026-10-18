<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Services\WhatsApp\PlatformTemplateService;
use App\Services\WhatsApp\ShopTemplateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncShopTemplates extends Command
{
    protected $signature = 'templates:sync';

    protected $description = "Submit NeoTalab's WhatsApp templates (platform number and connected shops) and refresh their approval status";

    public function handle(ShopTemplateService $templates, PlatformTemplateService $platform): int
    {
        try {
            $platform->sync(); // NeoTalab's own number: alert and good-news templates
        } catch (\Throwable $e) {
            Log::warning('Platform template sync failed', ['error' => $e->getMessage()]);
        }

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
