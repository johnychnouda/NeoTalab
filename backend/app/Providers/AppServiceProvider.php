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
        // Every warning or error the app logs also becomes an owner alert (Inbox → System).
        Event::listen(MessageLogged::class, fn (MessageLogged $log) => app(SystemAlertService::class)
            ->record($log->level, (string) $log->message, $log->context));

        // Brute-force protection: per email+IP, plus a looser per-IP ceiling.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        // Public /join applications.
        RateLimiter::for('onboarding', fn (Request $request) => [
            Limit::perHour(5)->by($request->ip()),
        ]);

        // Authenticated API traffic.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
    }
}
