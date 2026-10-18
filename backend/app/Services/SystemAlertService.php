<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\SystemAlert;
use App\Services\WhatsApp\PlatformTemplateService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns problems the app logs (failed backups, WhatsApp/AI errors, unhandled exceptions) into
 * owner alerts: one row per distinct problem with a count, shown in Owner portal → Inbox → System,
 * and for errors also an email and/or WhatsApp to the recipients set in Settings → Alerts.
 *
 * Recording must never break the caller or loop on itself (the DB or WhatsApp may be the very
 * thing that is down), so everything here is guarded and failures are swallowed.
 */
class SystemAlertService
{
    private static bool $busy = false;

    public function __construct(protected WhatsAppService $whatsapp) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $level, string $message, array $context = []): ?SystemAlert
    {
        if (self::$busy || ! in_array($level, config('alerts.levels'), true)) {
            return null;
        }

        self::$busy = true;
        try {
            return $this->store($level, $message, $context);
        } catch (Throwable) {
            return null;
        } finally {
            self::$busy = false;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function store(string $level, string $message, array $context): SystemAlert
    {
        $exception = $context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            $message = class_basename($exception).': '.$exception->getMessage();
            $fingerprintSource = $exception::class.'@'.$exception->getFile().':'.$exception->getLine();
        }

        $merchantId = is_string($context['merchant_id'] ?? null) ? $context['merchant_id'] : null;
        $message = Str::limit(trim($message) ?: 'Unknown error', 490);
        $fingerprint = hash('sha256', ($fingerprintSource ?? $message).'|'.$merchantId);
        $now = now();

        $alert = SystemAlert::query()->where('fingerprint', $fingerprint)->latest('last_seen_at')->first();
        $isNew = ! $alert || $alert->resolved_at !== null;

        if ($alert) {
            $alert->forceFill([
                'level' => $this->worse($alert->resolved_at ? $level : $alert->level, $level),
                'message' => $message,
                'context' => $this->clean($context),
                'occurrences' => $alert->resolved_at ? 1 : $alert->occurrences + 1,
                'first_seen_at' => $alert->resolved_at ? $now : $alert->first_seen_at,
                'last_seen_at' => $now,
                'resolved_at' => null,
            ])->save();
        } else {
            $alert = SystemAlert::create([
                'level' => $level,
                'message' => $message,
                'fingerprint' => $fingerprint,
                'context' => $this->clean($context),
                'merchant_id' => $merchantId,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);
        }

        if ($this->shouldNotify($alert, $isNew)) {
            $this->notify($alert);
        }

        return $alert;
    }

    protected function shouldNotify(SystemAlert $alert, bool $isNew): bool
    {
        if (! in_array($alert->level, config('alerts.notify_levels'), true)) {
            return false;
        }
        if (! $isNew && $alert->notified_at && $alert->notified_at->gt(now()->subMinutes(config('alerts.renotify_minutes')))) {
            return false;
        }

        // A storm of different errors must not flood the owner's phone.
        return RateLimiter::attempt('system-alert-notify', 20, fn () => true, 3600);
    }

    /**
     * Emails and/or WhatsApps the alert to the recipients from Settings → Alerts.
     *
     * @return array{email: string, whatsapp: string} per channel: sent | off | the error
     */
    protected function notify(SystemAlert $alert): array
    {
        $result = $this->sendToOwner($this->summary($alert), 'NeoTalab alert: '.Str::limit($alert->message, 80), 'Open the owner portal → Inbox → System for details.');

        if (in_array('sent', $result, true)) {
            $alert->forceFill(['notified_at' => now()])->save();
        }

        return $result;
    }

    /**
     * Emails and/or WhatsApps a message to the recipients from Settings → Alerts
     * (the WhatsApp goes out from the platform number with the "system_alert" template).
     *
     * $whatsappText replaces $text on WhatsApp (one line, no footer) when given.
     *
     * @return array{email: string, whatsapp: string} per channel: sent | off | the error
     */
    public function sendToOwner(string $text, string $subject, string $footer, ?string $whatsappText = null, ?string $template = null): array
    {
        $extra = PlatformSetting::singleton()->settings ?? [];
        $result = ['email' => 'off', 'whatsapp' => 'off'];

        $email = trim((string) ($extra['alert_email'] ?? ''));
        if ($email !== '' && config('mail.default') === 'log') {
            $result['email'] = 'Email sending is not set up on the server yet (MAIL_MAILER).';
        } elseif ($email !== '') {
            try {
                Mail::raw(trim($text."\n\n".$footer), function ($mail) use ($email, $subject) {
                    $mail->to($email)->subject($subject);
                });
                $result['email'] = 'sent';
            } catch (Throwable $e) {
                $result['email'] = Str::limit($e->getMessage(), 200); // mail provider not set up or down
            }
        }

        $phone = trim((string) ($extra['alert_whatsapp'] ?? ''));
        if ($phone !== '') {
            try {
                $this->whatsapp->sendPlatform($phone, [
                    'type' => 'template',
                    'template' => [
                        'name' => $template ?? config('alerts.whatsapp_template'),
                        'language' => ['code' => config('alerts.whatsapp_language')],
                        'components' => [[
                            'type' => 'body',
                            // Template variables may not contain new lines or long runs of spaces.
                            'parameters' => [['type' => 'text', 'text' => Str::limit(preg_replace('/\s+/', ' ', $whatsappText ?? $text), 900)]],
                        ]],
                    ],
                ]);
                $result['whatsapp'] = 'sent';
            } catch (ConnectionException) {
                $result['whatsapp'] = 'Could not reach the WhatsApp servers.';
            } catch (Throwable $e) {
                $result['whatsapp'] = Str::limit($e->getMessage(), 200); // platform number or template not ready
            }
        }

        return $result;
    }

    /**
     * A heads-up for the owner that is not a problem (e.g. a new join request), sent to the
     * Settings → Alerts recipients. Never throws, and a failed send does not become an alert.
     *
     * @return array{email: string, whatsapp: string}
     */
    public function notice(string $text, string $subject, string $footer = '', ?string $whatsappText = null): array
    {
        self::$busy = true;
        try {
            return $this->sendToOwner($text, $subject, $footer, $whatsappText, app(PlatformTemplateService::class)->noticeTemplate());
        } catch (Throwable) {
            return ['email' => 'failed', 'whatsapp' => 'failed'];
        } finally {
            self::$busy = false;
        }
    }

    /**
     * Owner portal "Send a test alert": always notifies, and says which channel worked.
     *
     * @return array{email: string, whatsapp: string}
     */
    public function sendTest(): array
    {
        self::$busy = true; // a failing WhatsApp send logs an error; don't turn that into another alert
        try {
            $alert = SystemAlert::create([
                'level' => 'warning',
                'message' => 'Test alert from the owner portal — if you got this, alerts work.',
                'fingerprint' => hash('sha256', 'test|'.Str::uuid()),
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'resolved_at' => now(),
            ]);

            return $this->notify($alert);
        } finally {
            self::$busy = false;
        }
    }

    public function summary(SystemAlert $alert): string
    {
        $shop = $alert->merchant_id ? ($alert->merchant?->name ?? null) : null;

        return trim(ucfirst($alert->level).': '.$alert->message.($shop ? " (shop: {$shop})" : ''));
    }

    /**
     * Keeps the context small and free of secrets; exceptions become class/file/line.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function clean(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (preg_match('/token|secret|password|authorization|api_key/i', (string) $key)) {
                continue;
            }
            if ($value instanceof Throwable) {
                $out[$key] = ['class' => $value::class, 'file' => basename($value->getFile()).':'.$value->getLine()];

                continue;
            }
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $out[$key] = is_scalar($value) || $value === null ? Str::limit((string) $value, 500) : Str::limit((string) $json, 1000);
        }

        return $out;
    }

    protected function worse(string $a, string $b): string
    {
        $order = array_flip(config('alerts.levels'));

        return ($order[$b] ?? 0) > ($order[$a] ?? 0) ? $b : $a;
    }
}
