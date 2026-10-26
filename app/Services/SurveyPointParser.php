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
        return $this->parseDetailed($contents, $classify)['points'];
    }

    public function parseDetailed(string $contents, Closure $classify): array
    {
        $points = [];
        $errors = [];
        $seenNumbers = [];
        $segmentNo = 0;
        $previousWasBlank = true;
        // Some instruments concatenate records without a newline (for example
        // "... ODF11 6549724.500 ..."). Restore only boundaries followed by a
        // point number and a seven-digit GK easting, avoiding ordinary digits
        // inside descriptions.
        $contents = preg_replace('/(?<=[\pL])(?=\d{1,9}[ \t]+[+-]?\d{7}(?:\.\d+)?[ \t]+)/u', "\n", $contents) ?? $contents;
        $lines = preg_split('/\R/u', preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents) ?: [];
        foreach ($lines as $lineIndex => $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                $previousWasBlank = true;

                continue;
            }
            if ($previousWasBlank) {
                $segmentNo++;
                $previousWasBlank = false;
            }
            if (! preg_match('/^(\d{1,9})[ \t]+([+-]?\d+(?:\.\d+)?)[ \t]+([+-]?\d+(?:\.\d+)?)[ \t]+([+-]?\d+(?:\.\d+)?)[ \t]+(.+)$/u', $line, $match)) {
                $errors[] = ['line' => $lineIndex + 1, 'content' => $line, 'reason' => 'Očekivan je format: broj X Y Z opis.'];

                continue;
            }
            $pointNo = (int) $match[1];
            $x = (float) $match[2];
            $y = (float) $match[3];
            $z = (float) $match[4];
            $code = trim($match[5]);
            if ($code === '') {
                $errors[] = ['line' => $lineIndex + 1, 'point_no' => $pointNo, 'content' => $line, 'reason' => 'Nedostaje opis objekta.'];

                continue;
            }
            if (isset($seenNumbers[$pointNo])) {
                $errors[] = ['line' => $lineIndex + 1, 'point_no' => $pointNo, 'content' => $line, 'reason' => "Dupliran redni broj; prvi je na liniji {$seenNumbers[$pointNo]}."];
            }
            $seenNumbers[$pointNo] = $lineIndex + 1;
            $zone = $this->transform->detectZone($x);
            try {
                [$lat, $lng] = $this->transform->gaussKrugerToWgs84($x, $y, $zone);
            } catch (\Throwable) {
                $errors[] = ['line' => $lineIndex + 1, 'point_no' => $pointNo, 'content' => $line, 'reason' => 'Transformacija koordinata nije uspjela.'];

                continue;
            }
            if ($lat < 40 || $lat > 48 || $lng < 12 || $lng > 24) {
                $errors[] = ['line' => $lineIndex + 1, 'point_no' => $pointNo, 'content' => $line, 'reason' => 'Transformisana koordinata je izvan očekivanog područja BiH.'];

                continue;
            }

            $classification = $classify($code);
            if (($classification['prepared_sling'] ?? false) && blank($classification['house_ref'] ?? null)) {
                $classification['house_ref'] = 'T'.$pointNo;
                $classification['house_ref_generated'] = true;
            }

            $points[] = [
                'point_no' => $pointNo,
                'x' => $x,
                'y' => $y,
                'z' => $z,
                'code' => $code,
                'lat' => $lat,
                'lng' => $lng,
                'source_crs' => 'EPSG:3127'.$zone,
                '_line_no' => $lineIndex + 1,
                '_segment_no' => $segmentNo,
            ] + $classification;
        }

        // Backwards compatibility for the older topology TXT format: in a file
        // that actually contains trench/duct geometry, a bare ODF is the network
        // endpoint used by the existing reconstruction workflow. Object-survey
        // files (poles, ODF, Z.P. and similar points without routes) always keep
        // ODF as a survey object and therefore never create a physical ODF.
        $isLegacyNetworkFile = collect($points)->contains(fn (array $point) => in_array($point['kind'], ['trench', 'sling'], true));
        if ($isLegacyNetworkFile) {
            $points = array_map(function (array $point): array {
                if (($point['survey_category'] ?? null) === 'odf') {
                    $point['kind'] = 'odf';
                    $point['survey_category'] = null;
                }

                return $point;
            }, $points);
        }

        return ['points' => $points, 'errors' => $errors, 'total_rows' => count(array_filter($lines, fn (string $line) => trim($line) !== ''))];
    }
}
