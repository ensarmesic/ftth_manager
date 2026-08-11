<?php

namespace App\Services;

use Closure;

class SurveyPointParser
{
    public function __construct(private readonly GeoTransformService $transform) {}

    /**
     * Parse raw survey TXT records and enrich them with domain classification.
     *
     * @param  Closure(string): array  $classify
     */
    public function parse(string $contents, Closure $classify): array
    {
        $pattern = '/(\d{1,5})\s+([4-7]\d{6}(?:\.\d{1,3})?)\s+([3-5]\d{6}(?:\.\d{1,3})?)\s+(-?\d{1,4}(?:\.\d{1,3})?)[ \t]*/';
        if (! preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [];
        }

        $points = [];
        foreach ($matches as $index => $match) {
            $start = $match[0][1] + strlen($match[0][0]);
            $end = isset($matches[$index + 1]) ? $matches[$index + 1][0][1] : strlen($contents);
            $code = trim(str_replace(['"', "\r"], '', substr($contents, $start, $end - $start)));
            $code = trim(preg_replace('/\s+/', ' ', $code) ?? '');

            $x = (float) $match[2][0];
            $y = (float) $match[3][0];
            $zone = $this->transform->detectZone($x);
            [$lat, $lng] = $this->transform->gaussKrugerToWgs84($x, $y, $zone);

            $classification = $classify($code);
            if (($classification['prepared_sling'] ?? false) && blank($classification['house_ref'] ?? null)) {
                $classification['house_ref'] = 'T'.(int) $match[1][0];
                $classification['house_ref_generated'] = true;
            }

            $points[] = [
                'point_no' => (int) $match[1][0],
                'x' => $x,
                'y' => $y,
                'z' => (float) $match[4][0],
                'code' => $code,
                'lat' => $lat,
                'lng' => $lng,
            ] + $classification;
        }

        return $points;
    }
}
