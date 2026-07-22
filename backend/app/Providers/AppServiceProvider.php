<?php

namespace App\Providers;

use App\Services\AI\Contracts\ChatProvider;
use App\Services\AI\Providers\FakeChatProvider;
use App\Services\AI\Providers\OpenAiCompatibleChatProvider;
use App\Support\CurrentTenant;
use Illuminate\Support\ServiceProvider;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
