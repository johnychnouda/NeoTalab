<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    /**
     * Today and upcoming bookings (read-only schedule for the dashboard).
     */
    public function index(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;
        $tz = $merchant->timezone ?: config('app.timezone');

        $appointments = Appointment::query()
            ->with(['customer', 'provider'])
            ->where('starts_at', '>=', now()->setTimezone($tz)->startOfDay()->utc())
            ->orderBy('starts_at')
            ->limit(200)
            ->get()
            ->map(fn (Appointment $a) => [
                'id' => $a->id,
                'service' => $a->service_name,
                'provider' => $a->provider?->name,
                'price' => (float) $a->price,
                'status' => $a->status,
                'starts_at' => $a->starts_at->copy()->setTimezone($tz)->format('Y-m-d H:i'),
                'ends_at' => $a->ends_at->copy()->setTimezone($tz)->format('H:i'),
                'customer_name' => $a->customer?->name,
                'customer_phone' => $a->customer?->phone,
            ]);

        return response()->json(['appointments' => $appointments, 'currency' => $merchant->currency]);
    }
}
