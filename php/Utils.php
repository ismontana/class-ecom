<?php
class Utils
{
    private static array $countryCodes = [
        '+1', '+52', '+53', '+54', '+55', '+56', '+57', '+58',
        '+51', '+507', '+506', '+505', '+504', '+503', '+502',
        '+509', '+592', '+593', '+595', '+597', '+598'
    ];

    private static array $countryMap = [
        'méxico' => 'Mexico',
        'mexico' => 'Mexico',
        'mx' => 'Mexico',

        'estados unidos' => 'United States',
        'usa' => 'United States',
        'us' => 'United States',
        'united states' => 'United States',

        'canada' => 'Canada',
        'ca' => 'Canada',

        'españa' => 'Spain',
        'spain' => 'Spain',

        'colombia' => 'Colombia',
        'co' => 'Colombia',

        'argentina' => 'Argentina',
        'ar' => 'Argentina',

        'chile' => 'Chile',
        'cl' => 'Chile',

        'peru' => 'Peru',
        'perú' => 'Peru',
        'pe' => 'Peru'
    ];

    public static function extractPhoneData(?string $phone): array
    {
        if (empty($phone)) {
            return ['lada' => null, 'numero' => null];
        }

        $normalized = preg_replace('/[\s\-\(\)]/', '', $phone);

        foreach (self::$countryCodes as $code) {
            if (strpos($normalized, $code) === 0) {
                return [
                    'lada' => $code,
                    'numero' => substr($normalized, strlen($code))
                ];
            }
        }

        if (preg_match('/^\+(\d{1,3})(\d+)$/', $normalized, $m)) {
            return [
                'lada' => '+' . $m[1],
                'numero' => $m[2]
            ];
        }

        return [
            'lada' => null,
            'numero' => $normalized
        ];
    }

    public static function normalizeCountry(?string $country): string
    {
        if (empty($country)) {
            return 'Mexico';
        }

        $key = mb_strtolower(trim($country));

        return self::$countryMap[$key] ?? $country;
    }

    public static function countryToISO2(?string $country): string
{
    if (!$country) {
        return 'MX';
    }

    $map = [
        'méxico' => 'MX',
        'mexico' => 'MX',
        'mx' => 'MX',

        'united states' => 'US',
        'estados unidos' => 'US',
        'usa' => 'US',

        'argentina' => 'AR',
        'colombia' => 'CO',
        'chile' => 'CL',
        'peru' => 'PE',
        'perú' => 'PE',
        'spain' => 'ES',
        'españa' => 'ES'
    ];

    $key = mb_strtolower(trim($country));

    return $map[$key] ?? 'MX';
}
}

