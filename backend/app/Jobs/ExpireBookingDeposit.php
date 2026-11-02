<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\Merchant;
use App\Services\BookingDeposits;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * End of the payment window for a booking's deposit: a booking whose customer never tapped "I paid" is cancelled and
 * its time released. One the customer reported is left to the shop to decide.
 */
class ExpireBookingDeposit implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $appointmentId) {}

    public function handle(BookingDeposits $deposits): void
    {
        $appointment = (new Appointment)->newQueryWithoutTenancy()->with('customer')->find($this->appointmentId);
        $merchant = $appointment ? Merchant::find($appointment->merchant_id) : null;

        if ($appointment && $merchant && $appointment->status === 'pending_deposit') {
            $deposits->expire($merchant, $appointment);
        }
    }
}
