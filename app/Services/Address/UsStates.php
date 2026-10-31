<?php

namespace App\Services\Address;

/**
 * The 50 states plus DC with the name and approximate centre of each, used to favour
 * address suggestions near a state and to read the state out of an IP lookup.
 */
class UsStates
{
    /** @var array<string, array{0:string, 1:float, 2:float}> code => [name, lat, lng] */
    private const STATES = [
        'AL' => ['Alabama', 32.78, -86.83], 'AK' => ['Alaska', 64.07, -152.28], 'AZ' => ['Arizona', 34.27, -111.66],
        'AR' => ['Arkansas', 34.89, -92.44], 'CA' => ['California', 37.18, -119.47], 'CO' => ['Colorado', 38.99, -105.55],
        'CT' => ['Connecticut', 41.62, -72.73], 'DE' => ['Delaware', 38.99, -75.51], 'DC' => ['District of Columbia', 38.91, -77.01],
        'FL' => ['Florida', 28.63, -82.45], 'GA' => ['Georgia', 32.64, -83.44], 'HI' => ['Hawaii', 20.29, -156.37],
        'ID' => ['Idaho', 44.35, -114.61], 'IL' => ['Illinois', 40.04, -89.20], 'IN' => ['Indiana', 39.89, -86.28],
        'IA' => ['Iowa', 42.08, -93.50], 'KS' => ['Kansas', 38.49, -98.38], 'KY' => ['Kentucky', 37.53, -85.30],
        'LA' => ['Louisiana', 31.07, -92.00], 'ME' => ['Maine', 45.37, -69.24], 'MD' => ['Maryland', 39.05, -76.79],
        'MA' => ['Massachusetts', 42.26, -71.81], 'MI' => ['Michigan', 44.35, -85.41], 'MN' => ['Minnesota', 46.28, -94.31],
        'MS' => ['Mississippi', 32.74, -89.67], 'MO' => ['Missouri', 38.35, -92.46], 'MT' => ['Montana', 47.05, -109.64],
        'NE' => ['Nebraska', 41.54, -99.80], 'NV' => ['Nevada', 39.33, -116.63], 'NH' => ['New Hampshire', 43.68, -71.58],
        'NJ' => ['New Jersey', 40.19, -74.67], 'NM' => ['New Mexico', 34.41, -106.11], 'NY' => ['New York', 42.95, -75.53],
        'NC' => ['North Carolina', 35.56, -79.39], 'ND' => ['North Dakota', 47.45, -100.47], 'OH' => ['Ohio', 40.29, -82.79],
        'OK' => ['Oklahoma', 35.59, -97.49], 'OR' => ['Oregon', 43.93, -120.56], 'PA' => ['Pennsylvania', 40.88, -77.80],
        'RI' => ['Rhode Island', 41.68, -71.51], 'SC' => ['South Carolina', 33.92, -80.90], 'SD' => ['South Dakota', 44.44, -100.23],
        'TN' => ['Tennessee', 35.86, -86.35], 'TX' => ['Texas', 31.48, -99.33], 'UT' => ['Utah', 39.31, -111.67],
        'VT' => ['Vermont', 44.07, -72.67], 'VA' => ['Virginia', 37.52, -78.85], 'WA' => ['Washington', 47.38, -120.45],
        'WV' => ['West Virginia', 38.64, -80.62], 'WI' => ['Wisconsin', 44.62, -89.99], 'WY' => ['Wyoming', 43.00, -107.55],
    ];

    /** Normalise a 2-letter state code, or null when it is not a US state. */
    public static function code(?string $value): ?string
    {
        $code = strtoupper(trim((string) $value));

        return isset(self::STATES[$code]) ? $code : null;
    }

    /** @return array{lat: float, lng: float}|null */
    public static function center(string $code): ?array
    {
        $state = self::STATES[$code] ?? null;

        return $state ? ['lat' => $state[1], 'lng' => $state[2]] : null;
    }

    /** Code for a state name such as "California", or null. */
    public static function codeForName(?string $name): ?string
    {
        $name = strtolower(trim((string) $name));
        if ($name === '') {
            return null;
        }
        foreach (self::STATES as $code => [$stateName]) {
            if (strtolower($stateName) === $name) {
                return $code;
            }
        }

        return null;
    }
}
