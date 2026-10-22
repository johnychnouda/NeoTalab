<?php

namespace App\Support;

/**
 * Finds the Lebanese governorate (region) and city of a pin without any paid service: the nearest
 * listed town decides. Approximate by nature, so the owner can always change the result by hand.
 */
class PlaceFromPin
{
    /** Within this many km of a listed town, the governorate is known. */
    protected const REGION_KM = 20;

    /** Within this many km of a listed town, the town itself is the city. Farther out, it is left empty. */
    protected const CITY_KM = 8;

    /**
     * Listed towns: [region, city, lat, lng]. Coordinates are the town centres, approximate.
     * The city values are the same as the join form's list.
     */
    protected const TOWNS = [
        ['Beirut', 'Beirut', 33.8938, 35.5018],
        ['Mount Lebanon', 'Baabda', 33.8333, 35.5442],
        ['Mount Lebanon', 'Aley', 33.8081, 35.5986],
        ['Mount Lebanon', 'Jounieh', 33.9808, 35.6178],
        ['Mount Lebanon', 'Broummana', 33.8906, 35.6075],
        ['Mount Lebanon', 'Bikfaya', 33.9128, 35.6270],
        ['Mount Lebanon', 'Beit Mery', 33.8750, 35.6250],
        ['Mount Lebanon', 'Dbayeh', 33.9239, 35.5878],
        ['Mount Lebanon', 'Jbeil', 34.1210, 35.6510],
        ['Mount Lebanon', 'Chouf', 33.6880, 35.5500],
        ['Mount Lebanon', 'Deir el Qamar', 33.7022, 35.5669],
        ['North Lebanon', 'Tripoli', 34.4367, 35.8497],
        ['North Lebanon', 'Zgharta', 34.3960, 35.8950],
        ['North Lebanon', 'Batroun', 34.2540, 35.6580],
        ['North Lebanon', 'Koura', 34.2720, 35.7760],
        ['North Lebanon', 'Bsharri', 34.2500, 36.0100],
        ['North Lebanon', 'Miniyeh-Danniyeh', 34.4900, 35.8800],
        ['South Lebanon', 'Sidon', 33.5630, 35.3690],
        ['South Lebanon', 'Tyre', 33.2705, 35.2038],
        ['South Lebanon', 'Jezzine', 33.5410, 35.5840],
        ['Bekaa', 'Zahle', 33.8463, 35.9019],
        ['Bekaa', 'Chtaura', 33.8160, 35.8550],
        ['Bekaa', 'Joub Jannine', 33.5485, 35.7530],
        ['Bekaa', 'Rashaya', 33.5040, 35.8400],
        ['Nabatieh', 'Nabatieh', 33.3780, 35.4840],
        ['Nabatieh', 'Bint Jbeil', 33.1200, 35.4350],
        ['Nabatieh', 'Marjeyoun', 33.3600, 35.5900],
        ['Nabatieh', 'Hasbaya', 33.3950, 35.6820],
        ['Akkar', 'Halba', 34.5450, 36.0790],
        ['Akkar', 'Qoubaiyat', 34.5300, 36.0300],
        ['Baalbek-Hermel', 'Baalbek', 34.0050, 36.2090],
        ['Baalbek-Hermel', 'Hermel', 34.3950, 36.3870],
    ];

    /**
     * @return array{region: ?string, city: ?string}|null null when the pin is far from every listed town
     */
    public static function lookup(string $latLng): ?array
    {
        if (! preg_match('/^(-?\d+\.?\d*),(-?\d+\.?\d*)$/', trim($latLng), $m)) {
            return null;
        }

        return self::nearest((float) $m[1], (float) $m[2]);
    }

    /** The region and city of the nearest listed town, or null when no town is near enough. */
    public static function nearest(float $lat, float $lng): ?array
    {
        $best = null;
        $bestKm = INF;
        foreach (self::TOWNS as [$region, $city, $townLat, $townLng]) {
            $km = self::distanceKm($lat, $lng, $townLat, $townLng);
            if ($km < $bestKm) {
                [$best, $bestKm] = [[$region, $city], $km];
            }
        }

        if ($best === null || $bestKm > self::REGION_KM) {
            return null;
        }

        return [
            'region' => $best[0],
            'city' => $bestKm <= self::CITY_KM ? $best[1] : null,
        ];
    }

    /** Great-circle distance in km. */
    protected static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
