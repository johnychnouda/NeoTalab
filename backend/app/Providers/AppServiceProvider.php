<?php

namespace App\Providers;

use App\Services\AI\Contracts\ChatProvider;
use App\Services\AI\Contracts\SpeechToText;
use App\Services\AI\Providers\FakeChatProvider;
use App\Services\AI\Providers\OpenAiCompatibleChatProvider;
use App\Services\AI\Speech\FakeSpeechToText;
use App\Services\AI\Speech\OpenAiSpeechToText;
use App\Services\SystemAlertService;
use App\Support\CurrentTenant;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One tenant holder per request/worker lifecycle.
        $this->app->singleton(CurrentTenant::class);

        $this->app->bind(ChatProvider::class, function ($app) {
            return match (config('ai.driver')) {
                'openai' => $app->make(OpenAiCompatibleChatProvider::class),
                default => $app->make(FakeChatProvider::class),
            };
        });

        $this->app->bind(SpeechToText::class, fn ($app) => config('ai.driver') === 'openai'
            ? $app->make(OpenAiSpeechToText::class)
            : $app->make(FakeSpeechToText::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The emailed reset link opens the NeoTalab website's "choose a new password" page.
        ResetPassword::createUrlUsing(fn ($notifiable, string $token) => rtrim((string) config('app.frontend_url'), '/')
            .'/reset-password?token='.$token.'&email='.urlencode((string) $notifiable->getEmailForPasswordReset()));

        // Every warning or error the app logs also becomes an owner alert (Inbox → System).
        Event::listen(MessageLogged::class, fn (MessageLogged $log) => app(SystemAlertService::class)
            ->record($log->level, (string) $log->message, $log->context));

        // Brute-force protection: per email+IP, a looser per-IP ceiling, and per email alone. The visitor
        // address comes from a forwarded header the proxy may pass on as the visitor sent it, so the
        // email-only limits are what stop one account being tried from endless "different" addresses.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
            Limit::perMinute(10)->by('email:'.Str::lower((string) $request->input('email'))),
            Limit::perHour(30)->by('email-hour:'.Str::lower((string) $request->input('email'))),
        ]);

        // Public /join applications: per visitor, and a ceiling for everyone together (a flood with
        // faked addresses still stops; real shops apply a few times a day at most).
        RateLimiter::for('onboarding', fn (Request $request) => [
            Limit::perHour(5)->by($request->ip()),
            Limit::perHour(30)->by('onboarding:all'),
        ]);

        // Authenticated API traffic.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
    }
}
