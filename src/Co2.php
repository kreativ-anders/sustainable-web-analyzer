<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

/**
 * CO2e estimate per page visit with the Sustainable Web Design Model v4 (SWDM v4).
 *
 * Inspired by CO2.js 0.19.0 by The Green Web Foundation: the model constants and the order of additions
 * are taken from its SWDM v4 implementation, so with the global grid intensity the results match
 * `new co2({model: "swd", version: 4})` exactly (see tests/run.php). CO2.js is a library that turns a
 * byte count you already have into grams, and it is mostly used to estimate the site it runs on. It does
 * not measure foreign websites. Everything that produces the inputs is done by this project itself:
 * crawling the page, measuring the transferred bytes, the green hosting check, locating every server and
 * choosing the grid intensity of the data-center segment from the countries of those servers (weighted
 * by bytes, see GridIntensity).
 *
 * Only the data-center segment uses the server location. The network sits between server and visitor
 * and the device grid depends on where the visitor is, which is unknown for a cached, shared result,
 * so both keep the global average, as SWDM recommends. Embodied emissions always use the global average,
 * as in CO2.js.
 *
 * @see https://github.com/thegreenwebfoundation/co2.js/blob/v0.19.0/src/sustainable-web-design-v4.js
 * @see https://sustainablewebdesign.org/estimating-digital-emissions/
 */
final class Co2
{
    public const MODEL = 'swd';
    public const MODEL_VERSION = 4;
    public const CO2JS_VERSION = '0.19.0';

    public const GLOBAL_GRID_INTENSITY = 494; // g CO2e per kWh

    private const GIGABYTE = 1_000_000_000;

    private const OPERATIONAL_KWH_PER_GB_DATACENTER = 0.055;
    private const OPERATIONAL_KWH_PER_GB_NETWORK = 0.059;
    private const OPERATIONAL_KWH_PER_GB_DEVICE = 0.08;
    private const EMBODIED_KWH_PER_GB_DATACENTER = 0.012;
    private const EMBODIED_KWH_PER_GB_NETWORK = 0.013;
    private const EMBODIED_KWH_PER_GB_DEVICE = 0.081;

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
     * Grams of CO2e per page visit (CO2.js perVisit() with gridIntensity.dataCenter).
     */
    public static function perVisit(int|float $bytes, bool $green = false, int|float $dataCenterIntensity = self::GLOBAL_GRID_INTENSITY): float
    {
        if ($bytes < 1) {
            return 0.0;
        }

        [$operational, $embodied] = self::emissions($bytes, $dataCenterIntensity);

        // With the CO2.js defaults (firstVisitPercentage 1, returnVisitPercentage 0, dataReloadRatio 0)
        // a visit equals the first visit.
        return $operational['dataCenter'] * self::operationalShare($green) + $embodied['dataCenter']
            + $operational['network'] + $embodied['network']
            + $operational['device'] + $embodied['device'];
    }

    /**
     * Grams of CO2e for transferring $bytes (CO2.js perByte() with gridIntensity.dataCenter).
     */
    public static function perByte(int|float $bytes, bool $green = false, int|float $dataCenterIntensity = self::GLOBAL_GRID_INTENSITY): float
    {
        if ($bytes < 1) {
            return 0.0;
        }

        [$operational, $embodied] = self::emissions($bytes, $dataCenterIntensity);

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
    public static function perVisitSegments(int|float $bytes, bool $green = false, int|float $dataCenterIntensity = self::GLOBAL_GRID_INTENSITY): array
    {
        if ($bytes < 1) {
            return ['operational' => 0.0, 'embodied' => 0.0];
        }

        [$operational, $embodied] = self::emissions($bytes, $dataCenterIntensity);

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
     * @param float $dataCenterIntensity g CO2e/kWh of the servers, e.g. from GridIntensity::dataCenter().
     * @param bool $penalized The analysis reached a gate (see Limit): the grams are a lower bound and the rating is F.
     * @return array<string, mixed> The "co2" block of the API response.
     */
    public static function report(int $bytes, bool $green, float $dataCenterIntensity = self::GLOBAL_GRID_INTENSITY, bool $penalized = false): array
    {
        $perVisit = self::perVisit($bytes, $green, $dataCenterIntensity);

        return [
            'model' => self::MODEL,
            'model_version' => self::MODEL_VERSION,
            'co2js_version' => self::CO2JS_VERSION,
            'per_visit' => $perVisit,
            'per_byte' => self::perByte($bytes, $green, $dataCenterIntensity),
            'segments' => self::perVisitSegments($bytes, $green, $dataCenterIntensity),
            'grid_intensity' => [
                'data_center' => round($dataCenterIntensity, 2),
                'network' => self::GLOBAL_GRID_INTENSITY,
                'device' => self::GLOBAL_GRID_INTENSITY,
                'source' => GridIntensity::SOURCE,
            ],
            'rating' => match (true) {
                $penalized => 'F',
                $bytes < 1 => null,
                default => self::rating($perVisit),
            },
            'penalized' => $penalized,
            'unit' => 'g',
        ];
    }

    /**
     * @return array{0: array{dataCenter: float, network: float, device: float}, 1: array{dataCenter: float, network: float, device: float}}
     */
    private static function emissions(int|float $bytes, int|float $dataCenterIntensity): array
    {
        $gigabytes = $bytes / self::GIGABYTE;

        return [
            [
                'dataCenter' => $gigabytes * self::OPERATIONAL_KWH_PER_GB_DATACENTER * $dataCenterIntensity,
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
