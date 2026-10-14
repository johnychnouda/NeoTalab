<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Support\MerchantWhatsAppCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Owner portal → Inbox → System → Launch checklist: which server settings are in place.
 * Read-only; each check is `required` (shops can't work without it), `recommended` or `optional`.
 */
class LaunchChecklistService
{
    public const SCHEDULER_HEARTBEAT = 'scheduler:heartbeat';

    /**
     * @return list<array{key: string, ok: bool, level: string}>
     */
    public function checks(): array
    {
        $platform = PlatformSetting::singleton()->settings ?? [];

        return [
            ['key' => 'ai', 'level' => 'required', 'ok' => config('ai.driver') === 'openai' && filled(config('ai.openai.api_key'))],
            ['key' => 'whatsapp_secret', 'level' => 'required', 'ok' => filled(config('whatsapp.app_secret'))],
            ['key' => 'whatsapp_token', 'level' => 'required', 'ok' => MerchantWhatsAppCredentials::platformAccessToken() !== ''],
            ['key' => 'whatsapp_signup', 'level' => 'required', 'ok' => filled(config('whatsapp.meta_app_id')) && filled(config('whatsapp.embedded_signup_config_id'))],
            ['key' => 'queue', 'level' => 'required', 'ok' => $this->queueIsMoving()],
            ['key' => 'scheduler', 'level' => 'required', 'ok' => $this->schedulerIsRunning()],
            ['key' => 'email', 'level' => 'recommended', 'ok' => $this->emailIsSetUp()],
            ['key' => 'alert_recipients', 'level' => 'recommended', 'ok' => filled($platform['alert_email'] ?? null) || filled($platform['alert_whatsapp'] ?? null)],
            ['key' => 'backups', 'level' => 'recommended', 'ok' => filled(config('filesystems.disks.backups.bucket'))],
            ['key' => 'maps', 'level' => 'optional', 'ok' => filled(config('services.google_maps.key'))],
            ['key' => 'sentry', 'level' => 'optional', 'ok' => filled(config('sentry.dsn'))],
        ];
    }

    /** No message has been waiting more than 5 minutes for a queue worker. */
    protected function queueIsMoving(): bool
    {
        if (config('queue.default') !== 'database') {
            return true; // sync (local) or another driver: nothing to wait on here
        }

        return ! DB::table(config('queue.connections.database.table', 'jobs'))
            ->whereNull('reserved_at')
            ->where('available_at', '<', now()->subMinutes(5)->getTimestamp())
            ->exists();
    }

    /** The scheduler stamps a heartbeat every minute (routes/console.php). */
    protected function schedulerIsRunning(): bool
    {
        $last = (int) Cache::get(self::SCHEDULER_HEARTBEAT, 0);

        return $last > now()->subMinutes(5)->getTimestamp();
    }

    protected function emailIsSetUp(): bool
    {
        return match (config('mail.default')) {
            'resend' => filled(config('services.resend.key')),
            'smtp' => filled(config('mail.mailers.smtp.host')) && config('mail.mailers.smtp.host') !== '127.0.0.1',
            'log', 'array', null => false,
            default => true,
        };
    }
}
