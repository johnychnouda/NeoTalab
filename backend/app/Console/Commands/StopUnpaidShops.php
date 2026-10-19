<?php

namespace App\Console\Commands;

use App\Services\SubscriptionStopService;
use Illuminate\Console\Command;

class StopUnpaidShops extends Command
{
    protected $signature = 'billing:stop-unpaid';

    protected $description = 'Stop paid shops that have not paid after the grace period';

    public function handle(SubscriptionStopService $stops): int
    {
        $this->info('Shops stopped: '.$stops->stopDue());

        return self::SUCCESS;
    }
}
