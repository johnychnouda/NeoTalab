<?php

namespace App\Console\Commands;

use App\Services\TrialExpiryService;
use Illuminate\Console\Command;

class ExpireTrials extends Command
{
    protected $signature = 'trials:expire';

    protected $description = 'Stop shops whose free trial ended without a payment';

    public function handle(TrialExpiryService $trials): int
    {
        $this->info('Trials stopped: '.$trials->expireDue());

        return self::SUCCESS;
    }
}
