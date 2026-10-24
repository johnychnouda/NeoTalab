<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Merchant;
use App\Models\WhatsAppMessage;
use App\Support\BusinessKind;
use Illuminate\Console\Command;

/**
 * Pharmacies and clinics: chat text older than BusinessKind::HEALTH_KEEP_DAYS is erased, since
 * customers may write about their health. Orders and bookings stay (the shop needs them); message
 * rows stay without their text, so a late duplicate from WhatsApp is still recognised.
 */
class ForgetHealthChats extends Command
{
    protected $signature = 'chats:forget';

    protected $description = 'Erase old chat text of pharmacies and clinics.';

    public function handle(): int
    {
        $before = now()->subDays(BusinessKind::HEALTH_KEEP_DAYS);
        $erased = 0;

        foreach (Merchant::query()->get() as $merchant) {
            if (! BusinessKind::isHealth($merchant)) {
                continue;
            }

            Conversation::query()->withoutGlobalScopes()->where('merchant_id', $merchant->id)
                ->where(fn ($q) => $q->where('last_message_at', '<', $before)->orWhere(fn ($q) => $q->whereNull('last_message_at')->where('created_at', '<', $before)))
                ->whereNotNull('state')
                ->chunkById(200, function ($conversations) use (&$erased) {
                    foreach ($conversations as $conversation) {
                        $state = $conversation->state ?? [];
                        if (($state['messages'] ?? []) === [] && ! isset($state['last_ai_output'])) {
                            continue;
                        }
                        $conversation->forceFill(['state' => ['messages' => [], 'phase' => $state['phase'] ?? 'idle', 'erased_at' => now()->toIso8601String()]])->save();
                        $erased++;
                    }
                });

            $erased += WhatsAppMessage::query()->withoutGlobalScopes()->where('merchant_id', $merchant->id)
                ->where('created_at', '<', $before)
                ->update(['payload' => json_encode(['erased' => true])]);
        }

        $this->info("Erased {$erased} old chats and messages.");

        return self::SUCCESS;
    }
}
