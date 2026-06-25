<?php

namespace App\Services;

class DXFParserService
{
    /**
     * Parse LINE, LWPOLYLINE, and POLYLINE entities from DXF file contents.
     * Returns an array of paths, each path being [[lat, lng], …].
     */
    public function parsePolylines(string $contents): array
    {
        $lines = preg_split('/\R/', $contents);
        $pairs = [];
        for ($i = 0; $i + 1 < count($lines); $i += 2) {
            $pairs[] = [trim($lines[$i]), trim($lines[$i + 1])];
        }

        $entities = [];
        $currentType = null;
        $current = [];
        $point = [];

        $flushPoint = function () use (&$current, &$point): void {
            if (isset($point['x'], $point['y'])) {
                $current[] = [(float) $point['y'], (float) $point['x']];
            }
            $point = [];
        };

        $flushEntity = function () use (&$entities, &$current, &$point): void {
            if (isset($point['x'], $point['y'])) {
                $current[] = [(float) $point['y'], (float) $point['x']];
            }
            $point = [];
            if (count($current) >= 2) {
                $entities[] = $current;
            }
            $current = [];
        };

        foreach ($pairs as [$code, $value]) {
            if ($code === '0') {
                if (in_array($value, ['LINE', 'LWPOLYLINE', 'POLYLINE'], true)) {
                    $flushEntity();
                    $currentType = $value;

                    continue;
                }
                if ($value === 'VERTEX' && $currentType === 'POLYLINE') {
                    $flushPoint();

                    continue;
                }
                if ($currentType === 'LINE' || $currentType === 'LWPOLYLINE' || ($currentType === 'POLYLINE' && $value === 'SEQEND')) {
                    $flushEntity();
                    $currentType = null;
                }

                continue;
            }

            if (! $currentType) {
                continue;
            }
            if ($code === '10') {
                if (isset($point['x'])) {
                    $flushPoint();
                }
                $point['x'] = $value;
            }
            if ($code === '20') {
                $point['y'] = $value;
            }
            if ($currentType === 'LINE' && $code === '11') {
                $flushPoint();
                $point['x'] = $value;
            }
            if ($currentType === 'LINE' && $code === '21') {
                $point['y'] = $value;
            }
        }
        $flushEntity();

        return $entities;
    }
}
