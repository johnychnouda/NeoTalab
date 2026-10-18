<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * A delivery zone drawn on the map, stored as JSON on delivery_zones.area:
 *   ['type' => 'circle',  'center' => [lat, lng], 'radius' => metres]
 *   ['type' => 'polygon', 'points' => [[lat, lng], …]]
 * Customers' location pins are matched to the zone they fall in; when zones overlap, the smallest
 * one wins (rings around the shop: the inner ring first).
 */
final class ZoneArea
{
    protected const EARTH_M = 6_371_000;

    /**
     * Checks and tidies an area sent by the dashboard. Null when none was sent.
     *
     * @return array<string, mixed>|null
     */
    public static function clean(mixed $area): ?array
    {
        if ($area === null || $area === '' || $area === []) {
            return null;
        }
        $fail = fn () => throw ValidationException::withMessages(['area' => [__('Draw the zone on the map again.')]]);
        if (! is_array($area)) {
            $fail();
        }

        if (($area['type'] ?? null) === 'circle') {
            $center = self::point($area['center'] ?? null) ?? $fail();
            $radius = (float) ($area['radius'] ?? 0);
            if ($radius < 100 || $radius > 60_000) {
                $fail();
            }

            return ['type' => 'circle', 'center' => $center, 'radius' => (int) round($radius)];
        }

        if (($area['type'] ?? null) === 'polygon') {
            $points = array_values(array_filter(array_map([self::class, 'point'], (array) ($area['points'] ?? []))));
            if (count($points) < 3 || count($points) > 200) {
                $fail();
            }

            return ['type' => 'polygon', 'points' => $points];
        }

        return $fail();
    }

    /** @param  array<string, mixed>  $area */
    public static function contains(array $area, float $lat, float $lng): bool
    {
        if ($area['type'] === 'circle') {
            return self::distance($area['center'][0], $area['center'][1], $lat, $lng) <= $area['radius'];
        }

        // Ray casting on the points (lat as y, lng as x); fine at a city's scale.
        $inside = false;
        $points = $area['points'];
        for ($i = 0, $j = count($points) - 1; $i < count($points); $j = $i++) {
            [$yi, $xi] = $points[$i];
            [$yj, $xj] = $points[$j];
            if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * Rough size in square metres, only used to put smaller zones first.
     *
     * @param  array<string, mixed>  $area
     */
    public static function size(array $area): float
    {
        if ($area['type'] === 'circle') {
            return M_PI * $area['radius'] ** 2;
        }
        $points = $area['points'];
        $lat0 = deg2rad($points[0][0]);
        $sum = 0.0;
        for ($i = 0, $n = count($points); $i < $n; $i++) {
            [$y1, $x1] = $points[$i];
            [$y2, $x2] = $points[($i + 1) % $n];
            $sum += deg2rad($x1) * cos($lat0) * deg2rad($y2) - deg2rad($x2) * cos($lat0) * deg2rad($y1);
        }

        return abs($sum) / 2 * self::EARTH_M ** 2;
    }

    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_M * asin(min(1, sqrt($a)));
    }

    /** @return array{0: float, 1: float}|null */
    protected static function point(mixed $p): ?array
    {
        if (! is_array($p) || count($p) < 2 || ! is_numeric($p[0] ?? null) || ! is_numeric($p[1] ?? null)) {
            return null;
        }
        [$lat, $lng] = [round((float) $p[0], 6), round((float) $p[1], 6)];

        return abs($lat) <= 90 && abs($lng) <= 180 ? [$lat, $lng] : null;
    }
}
