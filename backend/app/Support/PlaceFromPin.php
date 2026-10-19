<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * Finds the Lebanese governorate (region) and city of a pin with Google's Geocoding API, so the
 * owner does not pick them by hand. The names are mapped to the same lists the join form uses.
 */
class PlaceFromPin
{
    /** Google's governorate name (lowercase, without "Governorate") => the region value used in the app. */
    protected const REGIONS = [
        'beirut' => 'Beirut',
        'mount lebanon' => 'Mount Lebanon',
        'north' => 'North Lebanon',
        'north lebanon' => 'North Lebanon',
        'south' => 'South Lebanon',
        'south lebanon' => 'South Lebanon',
        'bekaa' => 'Bekaa',
        'beqaa' => 'Bekaa',
        'nabatieh' => 'Nabatieh',
        'akkar' => 'Akkar',
        'baalbek-hermel' => 'Baalbek-Hermel',
        'baalbek hermel' => 'Baalbek-Hermel',
    ];

    /** Cities by region, the same values as the join form's list. */
    protected const CITIES = [
        'Beirut' => ['Beirut'],
        'Mount Lebanon' => ['Baabda', 'Aley', 'Jounieh', 'Broummana', 'Bikfaya', 'Beit Mery', 'Dbayeh', 'Jbeil', 'Chouf', 'Deir el Qamar'],
        'North Lebanon' => ['Tripoli', 'Zgharta', 'Batroun', 'Koura', 'Bsharri', 'Miniyeh-Danniyeh'],
        'South Lebanon' => ['Sidon', 'Tyre', 'Jezzine'],
        'Bekaa' => ['Zahle', 'Chtaura', 'Joub Jannine', 'Rashaya'],
        'Nabatieh' => ['Nabatieh', 'Bint Jbeil', 'Marjeyoun', 'Hasbaya'],
        'Akkar' => ['Halba', 'Qoubaiyat'],
        'Baalbek-Hermel' => ['Baalbek', 'Hermel'],
    ];

    /** Google's place types that can name the city, most useful first. */
    protected const CITY_TYPES = ['locality', 'administrative_area_level_3', 'sublocality_level_1', 'administrative_area_level_2'];

    /**
     * @return array{region: ?string, city: ?string}|null  null when Google is not set up or does not answer
     */
    public static function lookup(string $latLng): ?array
    {
        $key = (string) config('services.google_geocoding.key');
        if ($key === '' || ! preg_match('/^(-?\d+\.?\d*),(-?\d+\.?\d*)$/', trim($latLng), $m)) {
            return null;
        }

        try {
            $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'latlng' => $m[1].','.$m[2],
                'language' => 'en',
                'region' => 'lb',
                'key' => $key,
            ]);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->ok() || $response->json('status') !== 'OK') {
            return null;
        }

        return self::fromResults($response->json('results') ?? []);
    }

    /** Reads the region and city out of Google's results. Public so the mapping can be tested offline. */
    public static function fromResults(array $results): array
    {
        $components = [];
        foreach ($results as $result) {
            foreach ($result['address_components'] ?? [] as $component) {
                $components[] = $component;
            }
        }

        $isLebanon = collect($components)->contains(fn ($c) => in_array('country', $c['types'] ?? [], true) && ($c['short_name'] ?? '') === 'LB');
        if (! $isLebanon) {
            return ['region' => null, 'city' => null];
        }

        $region = null;
        foreach ($components as $component) {
            if (in_array('administrative_area_level_1', $component['types'] ?? [], true)) {
                $region = self::REGIONS[self::normal($component['long_name'] ?? '')] ?? null;
                break;
            }
        }

        $city = null;
        $fallback = null;
        foreach (self::CITY_TYPES as $type) {
            foreach ($components as $component) {
                if (! in_array($type, $component['types'] ?? [], true)) {
                    continue;
                }
                $name = (string) ($component['long_name'] ?? '');
                $known = self::knownCity($name);
                if ($known) {
                    [$city, $cityRegion] = $known;
                    $region ??= $cityRegion;
                    break 2;
                }
                $fallback ??= $name !== '' ? $name : null;
            }
        }

        // A city the app does not list is still shown, so the owner can keep it under "Other".
        return ['region' => $region, 'city' => $city ?? $fallback];
    }

    /** [city, region] when the name is one of the listed cities, otherwise null. */
    protected static function knownCity(string $name): ?array
    {
        $wanted = self::normal($name);
        foreach (self::CITIES as $region => $cities) {
            foreach ($cities as $city) {
                if (self::normal($city) === $wanted) {
                    return [$city, $region];
                }
            }
        }

        return null;
    }

    /** Lowercase, without "District", "Caza", "Governorate" or "City", dashes as spaces. */
    protected static function normal(string $name): string
    {
        $name = strtolower(str_replace('-', ' ', $name));
        $name = preg_replace('/\b(district|caza|governorate|city)\b/', '', $name);

        return trim(preg_replace('/\s+/', ' ', $name));
    }
}
