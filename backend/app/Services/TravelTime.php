<?php

namespace App\Services;

use App\Models\DeliveryZone;
use App\Models\Merchant;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Driving time from the shop to the customer. With a Google Maps key, the shop's location and
 * the customer's WhatsApp pin, it asks Google's Routes API; otherwise it uses the delivery area's
 * driving time set by the shop.
 *
 * Cost: Google is asked once per order (the answer is kept on the order) and without live
 * traffic, which bills the cheaper "Essentials" tier (10,000 free a month, then $5 per 1,000).
 */
class TravelTime
{
    public const FALLBACK_MINS = 15;

    public function minutes(Merchant $merchant, Order $order): int
    {
        if ($order->travel_mins) {
            return (int) $order->travel_mins;
        }

        $mins = $this->fromGoogle($merchant, $order) ?? $this->fromZone($order);
        if ($order->exists) {
            $order->forceFill(['travel_mins' => $mins])->save();
        }

        return $mins;
    }

    public function fromZone(Order $order): int
    {
        $zone = $order->delivery_zone_id ? DeliveryZone::query()->find($order->delivery_zone_id) : null;

        return max(1, (int) ($zone->delivery_mins ?? self::FALLBACK_MINS));
    }

    /** Driving minutes from the shop pin to a point, or null when Google Maps can't answer. */
    public function between(Merchant $merchant, float $lat, float $lng): ?int
    {
        $shop = $this->shopLocation($merchant);

        return $shop ? $this->driveMinutes($shop[0], $shop[1], $lat, $lng) : null;
    }

    protected function fromGoogle(Merchant $merchant, Order $order): ?int
    {
        if ($order->delivery_lat === null || $order->delivery_lng === null) {
            return null;
        }

        return $this->between($merchant, (float) $order->delivery_lat, (float) $order->delivery_lng);
    }

    protected function driveMinutes(float $fromLat, float $fromLng, float $toLat, float $toLng): ?int
    {
        $key = (string) config('services.google_maps.key');
        if ($key === '') {
            return null;
        }

        try {
            $response = Http::timeout(8)->withHeaders([
                'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => 'routes.duration',
            ])->post('https://routes.googleapis.com/directions/v2:computeRoutes', [
                'origin' => ['location' => ['latLng' => ['latitude' => $fromLat, 'longitude' => $fromLng]]],
                'destination' => ['location' => ['latLng' => ['latitude' => $toLat, 'longitude' => $toLng]]],
                'travelMode' => 'DRIVE',
                'routingPreference' => 'TRAFFIC_UNAWARE',
            ]);
            $seconds = (int) rtrim((string) $response->json('routes.0.duration'), 's');

            return $response->successful() && $seconds > 0 ? max(1, (int) ceil($seconds / 60)) : null;
        } catch (\Throwable $e) {
            Log::info('Google travel time unavailable, using the area time', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The shop's location from its settings: "33.89,35.50" or a Google Maps link containing it.
     *
     * @return array{0: float, 1: float}|null
     */
    public function shopLocation(Merchant $merchant): ?array
    {
        $raw = (string) (($merchant->settings ?? [])['shop_location'] ?? '');

        return preg_match('/(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/', $raw, $m) ? [(float) $m[1], (float) $m[2]] : null;
    }
}
