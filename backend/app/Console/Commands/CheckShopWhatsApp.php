<?php

namespace App\Console\Commands;

use App\Services\ShopWhatsAppWatch;
use Illuminate\Console\Command;

class CheckShopWhatsApp extends Command
{
    protected $signature = 'shops:check-whatsapp';

    protected $description = "Ask Meta whether each shop's WhatsApp still works, and tell the owners of shops that need to reconnect";

    public function handle(ShopWhatsAppWatch $watch): int
    {
        $this->info('Shop owners told: '.$watch->run());

        return self::SUCCESS;
    }
}
