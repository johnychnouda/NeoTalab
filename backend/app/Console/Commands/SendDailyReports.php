<?php

namespace App\Console\Commands;

use App\Services\DailyReportService;
use Illuminate\Console\Command;

class SendDailyReports extends Command
{
    protected $signature = 'reports:daily';

    protected $description = 'Send the nightly WhatsApp summary to shop owners whose report hour is now';

    public function handle(DailyReportService $reports): int
    {
        $this->info('Reports sent: '.$reports->sendDue());

        return self::SUCCESS;
    }
}
