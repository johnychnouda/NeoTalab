<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\PlatformWhatsAppCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

class CheckPlatformWhatsApp extends Command
{
    protected $signature = 'platform:check-whatsapp';

    protected $description = "Ask Meta whether NeoTalab's own WhatsApp number still works, and raise an owner alert when it does not";

    public function handle(PlatformWhatsAppCheck $check): int
    {
        $result = $check->run();

        // One failed try can be a network blip: look again before waking the owner.
        if ($result['state'] === 'problem') {
            Sleep::for(10)->seconds();
            $result = $check->run();
        }

        if ($result['state'] === 'problem') {
            // Logged as an error, so it lands in Owner portal → Inbox → System and is emailed to Settings → Alerts.
            Log::error("NeoTalab's WhatsApp number has a problem: ".($result['message'] ?? 'unknown'));
            $this->warn('Problem: '.($result['message'] ?? 'unknown'));
        } else {
            $this->info('State: '.$result['state']);
        }

        return self::SUCCESS;
    }
}
