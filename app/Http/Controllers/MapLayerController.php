<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MapLayerController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        ini_set('memory_limit', '1G');

        $request->validate(['file' => 'required|file|max:102400']);

        try {
            $file = $request->file('file');
            $ext = strtolower($file->getClientOriginalExtension());
            $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $path = $file->getPathname();

            if ($ext === 'dwg') {
                return response()->json([
                    'error' => 'DWG nije podržan. Sačuvaj fajl kao DXF (Save As → DXF) iz AutoCAD-a/FreeCAD-a i pokušaj ponovo.',
                ], 422);
            }

            $geojson = $this->dxfToGeoJson($path, $name);

            // Sačuvaj features na disk — export ih čita po ključu bez resendinga
            $cacheKey = (string) Str::uuid();
            Storage::put(
                'dxf_layers/'.$cacheKey.'.json',
                json_encode($geojson['features'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            );
            $geojson['_cache_key'] = $cacheKey;

            return response()->json(
                $geojson,
                200, [],
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE
            );
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'PHP greška: '.$e->getMessage().' u '.basename($e->getFile()).':'.$e->getLine(),
            ], 500);
        }
    }

    private function dxfToGeoJson(string $filePath, string $name): array
    {
        $fh = fopen($filePath, 'rb');
        if (! $fh) {
            throw new \RuntimeException('Ne mogu otvoriti DXF fajl.');
        }

        $pending = null;

        $read = function () use ($fh, &$pending): ?array {
            if ($pending !== null) {
                $p = $pending;
                $pending = null;

                return $p;
            }
            if (feof($fh)) {
                return null;
            }
            $codeLine = fgets($fh);
            $valueLine = fgets($fh);
            if ($codeLine === false) {
                return null;
            }

            return [trim($codeLine), trim((string) $valueLine)];
        };

        $unread = function (array $pair) use (&$pending): void {
            $pending = $pair;
        };

        $features = [];
        $inEntities = false;
        $supported = ['LINE', 'LWPOLYLINE', 'POLYLINE', 'TEXT', 'MTEXT'];

        while (($pair = $read()) !== null) {
            [$code, $value] = $pair;

            if ($code === '0' && $value === 'SECTION') {
                $next = $read();
                $inEntities = $next && $next[1] === 'ENTITIES';

                continue;
            }
            if ($code === '0' && $value === 'ENDSEC') {
                $inEntities = false;

                continue;
            }
            if (! $inEntities) {
                continue;
            }
            if ($code !== '0' || ! in_array($value, $supported, true)) {
                continue;
            }

            $entityType = $value;
            $layer = '0';
            $color = null;
            $pts = [];
            $pt = [];
            $closed = false;
            $extra = [];
            $polylineInVerts = false; // POLYLINE header ima dummy 10/20 — ignorišuj dok ne počnu VERTEX

            $flush = function () use (&$pts, &$pt): void {
                if (isset($pt['x'], $pt['y'])) {
                    $pts[] = [(float) $pt['x'], (float) $pt['y']];
                }
                $pt = [];
            };

            while (($ep = $read()) !== null) {
                [$ec, $ev] = $ep;

                if ($ec === '0') {
                    if ($ev === 'VERTEX' && $entityType === 'POLYLINE') {
                        if ($polylineInVerts) {
                            $flush(); // flush prethodnog VERTEX-a
                        } else {
                            $pt = []; // odbaci dummy header koordinate
                            $polylineInVerts = true;
                        }

                        continue;
                    }
                    if ($ev === 'SEQEND') {
                        $flush();
                        break;
                    }
                    $flush();
                    $unread($ep);
                    break;
                }

                switch ($ec) {
                    case '8':
                        $layer = $this->toUtf8($ev);
                        break;
                    case '62':
                        $color = (int) $ev;
                        break;
                    case '1':
                        $extra['text'] = isset($extra['text']) ? $extra['text'].$ev : $ev;
                        break;
                    case '3':
                        $extra['text'] = ($extra['text'] ?? '').$ev;
                        break;
                    case '40':
                        $extra['radius'] = (float) $ev;
                        break;
                    case '50':
                        $extra['start_angle'] = (float) $ev;
                        break;
                    case '51':
                        $extra['end_angle'] = (float) $ev;
                        break;
                    case '70':
                        $closed = ($entityType === 'LWPOLYLINE') && ((int) $ev & 1) === 1;
                        break;
                    case '10':
                        // Za POLYLINE: skupljaj koordinate samo unutar VERTEX (ne iz headera)
                        if ($entityType === 'POLYLINE' && ! $polylineInVerts) {
                            break;
                        }
                        if (isset($pt['x']) && ! in_array($entityType, ['TEXT', 'MTEXT'], true)) {
                            $flush();
                        }
                        $pt['x'] = (float) $ev;
                        break;
                    case '20':
                        if ($entityType === 'POLYLINE' && ! $polylineInVerts) {
                            break;
                        }
                        $pt['y'] = (float) $ev;
                        break;
                    case '11':
                        if ($entityType === 'LINE') {
                            $flush();
                            $pt['x'] = (float) $ev;
                        }
                        break;
                    case '21':
                        if ($entityType === 'LINE') {
                            $pt['y'] = (float) $ev;
                        }
                        break;
                }
            }

            $flush();

            // ─── Post-processing po tipu entiteta ────────────────────────
            // Napomena: $flush() briše $pt, pa koristimo $pts[0] za poziciju točke

            if ($entityType === 'TEXT' || $entityType === 'MTEXT') {
                if (empty($pts)) {
                    continue;
                }
                $text = $this->cleanText($extra['text'] ?? '');
                if ($text === '') {
                    continue;
                }
                $features[] = [
                    'type' => 'Feature',
                    'properties' => [
                        'layer' => $layer,
                        'color' => $color,
                        'text' => $text,
                        'entity' => $entityType,
                        'height' => isset($extra['radius']) && $extra['radius'] > 0 ? $extra['radius'] : null,
                    ],
                    'geometry' => ['type' => 'Point', 'coordinates' => [$pts[0][0], $pts[0][1]]],
                ];

                continue;
            }

            // ─── LineString / Polygon output ─────────────────────────────

            if (count($pts) < 2) {
                continue;
            }

            $props = ['layer' => $layer, 'color' => $color];

            if ($closed && count($pts) >= 3) {
                if ($pts[0] !== $pts[count($pts) - 1]) {
                    $pts[] = $pts[0];
                }
                $features[] = [
                    'type' => 'Feature',
                    'properties' => $props,
                    'geometry' => ['type' => 'Polygon', 'coordinates' => [$pts]],
                ];
            } else {
                $features[] = [
                    'type' => 'Feature',
                    'properties' => $props,
                    'geometry' => ['type' => 'LineString', 'coordinates' => $pts],
                ];
            }
        }

        fclose($fh);

        return [
            'type' => 'FeatureCollection',
            'name' => $name,
            'features' => $features,
        ];
    }

    private function cleanText(string $text): string
    {
        $text = $this->toUtf8($text);
        // MTEXT RTF-like codes: \P paragraph, \Ffonname; font, \H height; etc.
        $text = preg_replace('/\\\[A-Za-z][^;\\\\]*;/', ' ', $text);
        $text = preg_replace('/\\\[A-Za-z]/', '', $text);
        $text = str_replace(['\\P', '\\p', '{', '}', '~'], [' ', ' ', '', '', ' '], $text);
        // DXF %% overrides: %%d=°, %%p=±, %%c=⌀
        $text = str_replace(['%%d', '%%D', '%%p', '%%P', '%%c', '%%C'], ['°', '°', '±', '±', '⌀', '⌀'], $text);
        $text = preg_replace('/%%[A-Za-z0-9]/', '', $text);

        return trim($text);
    }

    private function toUtf8(string $s): string
    {
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        // iconv podržava CP1250 na svim OS-ima bolje od mbstring
        if (function_exists('iconv')) {
            $r = @iconv('CP1250', 'UTF-8//IGNORE', $s);
            if ($r !== false) {
                return $r;
            }

            $r = @iconv('ISO-8859-2', 'UTF-8//IGNORE', $s);
            if ($r !== false) {
                return $r;
            }
        }

        // Posljednji fallback: ukloni non-ASCII bajtove
        return (string) preg_replace('/[\x80-\xFF]/', '', $s);
    }
}
