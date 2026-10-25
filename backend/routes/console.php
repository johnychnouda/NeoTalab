<?php

use App\Services\LaunchChecklistService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// On Railway the API service runs these: docker/entrypoint.sh loops `schedule:run` every minute
// in the background (RUN_SCHEDULER, default on). Locally: `php artisan schedule:work`.
Schedule::command('reports:daily')->hourly()->withoutOverlapping();
Schedule::command('billing:remind')->hourly()->withoutOverlapping();
Schedule::command('trials:expire')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('billing:stop-unpaid')->hourly()->withoutOverlapping();
Schedule::command('templates:sync')->hourly()->withoutOverlapping();
Schedule::command('orders:due')->hourly()->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Proof the scheduler is alive, shown in Owner portal → Inbox → System → Launch checklist.
Schedule::call(fn () => Cache::put(LaunchChecklistService::SCHEDULER_HEARTBEAT, now()->getTimestamp(), 3600))
    ->name('scheduler:heartbeat')->everyMinute();

// Without the AI, customers who type freely only get the test bot: say so every morning.
if (app()->isProduction() && (config('ai.driver') !== 'openai' || blank(config('ai.openai.api_key')))) {
    Schedule::call(fn () => Log::warning('The AI assistant is OFF: set AI_DRIVER=openai and OPENAI_API_KEY on the Railway API and queue services.'))
        ->name('ai:missing-warning')->dailyAt('09:00')->timezone('Asia/Beirut');
}

// Nightly off-site database backup, kept 30 days. Runs only once a backup bucket is configured;
// a failure is reported to Sentry (error alerts).
if (filled(config('filesystems.disks.backups.bucket'))) {
    Schedule::command('backup:run --only-db --disable-notifications')->dailyAt('03:00')->timezone('Asia/Beirut')
        ->appendOutputTo('/dev/stdout') // the reason shows in the Railway logs if it fails
        ->withoutOverlapping()->onFailure(fn () => report(new RuntimeException('Nightly database backup failed — check the API logs.')));
    Schedule::command('backup:clean --disable-notifications')->dailyAt('03:30')->timezone('Asia/Beirut')->withoutOverlapping();
}
// Without a backup bucket nothing is backed up: say so every morning in Owner portal → Inbox →
// System (warnings become system alerts) until BACKUP_S3_* is set on Railway.
if (app()->isProduction() && blank(config('filesystems.disks.backups.bucket'))) {
    Schedule::call(fn () => Log::warning('Nightly database backups are OFF: set BACKUP_S3_BUCKET, BACKUP_S3_ENDPOINT, BACKUP_S3_KEY and BACKUP_S3_SECRET on the Railway API service (see deploy/STEP-BY-STEP.md).'))
        ->name('backups:missing-warning')->dailyAt('09:00')->timezone('Asia/Beirut');
}
