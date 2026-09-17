<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

/**
 * PHP port of the Sustainable Web Design Model v4 from CO2.js 0.19.0 by The Green Web Foundation –
 * the library's default model: `new co2()` / `new co2({model: "swd", version: 4})`.
 *
 * Additions are performed in the same order as CO2.js, so results match the JavaScript library exactly.
 * Only the defaults are ported (global grid intensity, no custom visit ratios or green hosting factor).
 *
 * @see https://github.com/thegreenwebfoundation/co2.js/blob/v0.19.0/src/sustainable-web-design-v4.js
 * @see https://sustainablewebdesign.org/estimating-digital-emissions/
 */
final class Co2
{
    public const MODEL = 'swd';
    public const MODEL_VERSION = 4;
    public const CO2JS_VERSION = '0.19.0';

    private const GIGABYTE = 1_000_000_000;

    private const OPERATIONAL_KWH_PER_GB_DATACENTER = 0.055;
    private const OPERATIONAL_KWH_PER_GB_NETWORK = 0.059;
    private const OPERATIONAL_KWH_PER_GB_DEVICE = 0.08;
    private const EMBODIED_KWH_PER_GB_DATACENTER = 0.012;
    private const EMBODIED_KWH_PER_GB_NETWORK = 0.013;
    private const EMBODIED_KWH_PER_GB_DEVICE = 0.081;

    private const GLOBAL_GRID_INTENSITY = 494; // g CO2e per kWh

    /** Upper bounds (g CO2e per visit) of the rating grades, SWDM v4. */
    private const RATINGS = [
        'A+' => 0.04,
        'A' => 0.079,
        'B' => 0.145,
        'C' => 0.209,
        'D' => 0.278,
        'E' => 0.359,
    ];

    /**
     * Grams of CO2e per page visit (CO2.js perVisit()).
     */
    public static function perVisit(int|float $bytes, bool $green = false): float
    {
        if ($bytes < 1) {
            return 0.0;
        }

        [$operational, $embodied] = self::emissions($bytes);

        // With the CO2.js defaults (firstVisitPercentage 1, returnVisitPercentage 0, dataReloadRatio 0)
        // a visit equals the first visit.
        return $operational['dataCenter'] * self::operationalShare($green) + $embodied['dataCenter']
            + $operational['network'] + $embodied['network']
            + $operational['device'] + $embodied['device'];
    }

    /**
     * Grams of CO2e for transferring $bytes (CO2.js perByte()).
     */
    public static function perByte(int|float $bytes, bool $green = false): float
    {
        if ($bytes < 1) {
            return 0.0;
        }

        [$operational, $embodied] = self::emissions($bytes);

        $dataCenter = $operational['dataCenter'] * self::operationalShare($green) + $embodied['dataCenter'];
        $network = $operational['network'] + $embodied['network'];
        $device = $operational['device'] + $embodied['device'];

        return $dataCenter + $network + $device;
    }

    /**
     * Grams of CO2e per visit, split into operational (electricity while transferring and displaying) and
     * embodied (manufacturing of data centers, networks and devices – a per-GB model average, not the
     * site's actual hardware). Unlike CO2.js segment results, green hosting is applied to the operational
     * share, so both parts always add up to perVisit(). For non-green hosting they equal CO2.js
     * totalOperationalCO2e and totalEmbodiedCO2e.
     *
     * @return array{operational: float, embodied: float}
     */
    public static function perVisitSegments(int|float $bytes, bool $green = false): array
    {
        if ($bytes < 1) {
            return ['operational' => 0.0, 'embodied' => 0.0];
        }

        [$operational, $embodied] = self::emissions($bytes);

        return [
            'operational' => $operational['dataCenter'] * self::operationalShare($green) + $operational['network'] + $operational['device'],
            'embodied' => $embodied['dataCenter'] + $embodied['network'] + $embodied['device'],
        ];
    }

    /**
     * Grade from A+ to F for grams of CO2e per visit (CO2.js outputRating()).
     */
    public static function rating(float $gramsPerVisit): string
    {
        foreach (self::RATINGS as $rating => $limit) {
            if ($gramsPerVisit <= $limit) {
                return $rating;
            }
        }

        return 'F';
    }

    /**
     * @return array<string, mixed> The "co2" block of the API response.
     */
    public static function report(int $bytes, bool $green): array
    {
        $perVisit = self::perVisit($bytes, $green);

        return [
            'model' => self::MODEL,
            'model_version' => self::MODEL_VERSION,
            'co2js_version' => self::CO2JS_VERSION,
            'per_visit' => $perVisit,
            'per_byte' => self::perByte($bytes, $green),
            'segments' => self::perVisitSegments($bytes, $green),
            'rating' => $bytes < 1 ? null : self::rating($perVisit),
            'unit' => 'g',
        ];
    }

    /**
     * @return array{0: array{dataCenter: float, network: float, device: float}, 1: array{dataCenter: float, network: float, device: float}}
     */
    private static function emissions(int|float $bytes): array
    {
        $gigabytes = $bytes / self::GIGABYTE;

        return [
            [
                'dataCenter' => $gigabytes * self::OPERATIONAL_KWH_PER_GB_DATACENTER * self::GLOBAL_GRID_INTENSITY,
                'network' => $gigabytes * self::OPERATIONAL_KWH_PER_GB_NETWORK * self::GLOBAL_GRID_INTENSITY,
                'device' => $gigabytes * self::OPERATIONAL_KWH_PER_GB_DEVICE * self::GLOBAL_GRID_INTENSITY,
            ],
            [
                'dataCenter' => $gigabytes * self::EMBODIED_KWH_PER_GB_DATACENTER * self::GLOBAL_GRID_INTENSITY,
                'network' => $gigabytes * self::EMBODIED_KWH_PER_GB_NETWORK * self::GLOBAL_GRID_INTENSITY,
                'device' => $gigabytes * self::EMBODIED_KWH_PER_GB_DEVICE * self::GLOBAL_GRID_INTENSITY,
            ],
        ];
    }

    /**
     * Green hosting removes the operational data-center emissions (green hosting factor 1).
     */
    private static function operationalShare(bool $green): int
    {
        return $green ? 0 : 1;
    }
}
