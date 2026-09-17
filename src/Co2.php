<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

/**
 * PHP port of the Sustainable Web Design model (v3) from CO2.js 0.13.2 by The Green Web Foundation,
 * the version used by the kreativ-anders.de frontend: `new co2({model: "swd"})`.
 *
 * The additions are done in the same order as CO2.js, so results match the JavaScript library exactly.
 *
 * @see https://github.com/thegreenwebfoundation/co2.js/blob/v0.13.2/src/sustainable-web-design.js
 * @see https://sustainablewebdesign.org/calculating-digital-emissions/
 */
final class Co2
{
    public const MODEL = 'swd';
    public const MODEL_VERSION = 3;
    public const CO2JS_VERSION = '0.13.2';

    private const GIGABYTE = 1_000_000_000;
    private const KWH_PER_GB = 0.81;

    /** Share of the energy per system segment. */
    private const SEGMENTS = [
        'consumerDevice' => 0.52,
        'network' => 0.14,
        'production' => 0.19,
        'dataCenter' => 0.15,
    ];

    private const GLOBAL_GRID_INTENSITY = 442;     // g CO2e per kWh
    private const RENEWABLES_GRID_INTENSITY = 50;  // g CO2e per kWh

    private const FIRST_TIME_VIEWING_PERCENTAGE = 0.75;
    private const RETURNING_VISITOR_PERCENTAGE = 0.25;
    private const PERCENTAGE_OF_DATA_LOADED_ON_SUBSEQUENT_LOAD = 0.02;

    /**
     * Grams of CO2e per visit, taking cached assets of returning visitors into account (CO2.js perVisit()).
     */
    public static function perVisit(int|float $bytes, bool $green = false): float
    {
        $total = 0.0;

        foreach (self::energyByComponent($bytes) as $segment => $energy) {
            $intensity = self::intensity($segment, $green);
            $total += $energy * self::FIRST_TIME_VIEWING_PERCENTAGE * $intensity;
            $total += $energy * self::RETURNING_VISITOR_PERCENTAGE * self::PERCENTAGE_OF_DATA_LOADED_ON_SUBSEQUENT_LOAD * $intensity;
        }

        return $total;
    }

    /**
     * Grams of CO2e for transferring $bytes once (CO2.js perByte()).
     */
    public static function perByte(int|float $bytes, bool $green = false): float
    {
        $total = 0.0;

        foreach (self::energyByComponent($bytes) as $segment => $energy) {
            $total += $energy * self::intensity($segment, $green);
        }

        return $total;
    }

    /**
     * @return array<string, mixed> The "co2" block of the API response.
     */
    public static function report(int $bytes, bool $green): array
    {
        return [
            'model' => self::MODEL,
            'model_version' => self::MODEL_VERSION,
            'co2js_version' => self::CO2JS_VERSION,
            'per_visit' => self::perVisit($bytes, $green),
            'per_byte' => self::perByte($bytes, $green),
            'unit' => 'g',
        ];
    }

    /**
     * @return array<string, float> kWh per segment
     */
    private static function energyByComponent(int|float $bytes): array
    {
        $energy = $bytes / self::GIGABYTE * self::KWH_PER_GB;

        return array_map(static fn (float $share): float => $energy * $share, self::SEGMENTS);
    }

    private static function intensity(string $segment, bool $green): int
    {
        return $green && $segment === 'dataCenter' ? self::RENEWABLES_GRID_INTENSITY : self::GLOBAL_GRID_INTENSITY;
    }
}
