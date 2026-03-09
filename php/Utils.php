<?php
class Utils
{
    private static array $countryCodes = [
        '+1', '+52', '+53', '+54', '+55', '+56', '+57', '+58',
        '+51', '+507', '+506', '+505', '+504', '+503', '+502',
        '+509', '+592', '+593', '+595', '+597', '+598'
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
}