<?php

namespace App\Console\Commands;

use App\Services\BillingReminderService;
use Illuminate\Console\Command;

class SendBillingReminders extends Command
{
    protected $signature = 'billing:remind';

    protected $description = 'Remind shop owners on WhatsApp that their NeoTalab subscription is ending';

    public function handle(BillingReminderService $reminders): int
    {
        $this->info('Reminders sent: '.$reminders->sendDue());

        return self::SUCCESS;
    }
}
