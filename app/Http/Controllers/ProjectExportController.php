<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesFtthData;
use App\Http\Controllers\Concerns\WritesDxfEntities;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProjectExportController extends Controller
{
    use ManagesFtthData;
    use WritesDxfEntities;

    public function exportDxf(Project $project, Request $request)
    {
        ini_set('memory_limit', '1G');
        set_time_limit(180);
        $project->load(['odfs', 'cabinets', 'houses', 'routes', 'appendixItems']);

        // Zona — koristimo ODF, ormarice i prvu točku prve trase
        $lngs = collect($project->odfs)->pluck('longitude')
            ->merge(collect($project->cabinets)->pluck('longitude'))
            ->filter()->values();
        if ($lngs->isEmpty()) {
            foreach ($project->routes as $r) {
                $path = $r->path ?? [];
                if (! empty($path)) {
                    $lngs = collect([[isset($path[0][1]) ? $path[0][1] : 18.0]])->pluck(0);
                    break;
                }
            }
        }
        $avgLng = $lngs->isNotEmpty() ? $lngs->avg() : 18.0;
        $zone = $avgLng < 16.5 ? 5 : ($avgLng > 19.5 ? 7 : 6);

        // Paleta 6 boja za ormarice — ista boja = iste kuce
        $palette = [1, 2, 3, 4, 5, 6]; // ACI: crvena, zuta, zelena, cijan, plava, magenta
        $cabinetColor = [];
        foreach ($project->cabinets as $idx => $cabinet) {
            $cabinetColor[$cabinet->id] = $palette[$idx % count($palette)];
        }

        // ── FTTH GK bounding box — za clip background-a i $EXTMIN/$EXTMAX ─────────
        $bboxMinX = PHP_FLOAT_MAX;
        $bboxMaxX = -PHP_FLOAT_MAX;
        $bboxMinY = PHP_FLOAT_MAX;
        $bboxMaxY = -PHP_FLOAT_MAX;
        $expandBbox = function (float $lat, float $lng) use (&$bboxMinX, &$bboxMaxX, &$bboxMinY, &$bboxMaxY, $zone) {
            [$gx, $gy] = $this->wgs84ToGaussKruger($lat, $lng, $zone);
            if ($gx < $bboxMinX) {
                $bboxMinX = $gx;
            } if ($gx > $bboxMaxX) {
                $bboxMaxX = $gx;
            }
            if ($gy < $bboxMinY) {
                $bboxMinY = $gy;
            } if ($gy > $bboxMaxY) {
                $bboxMaxY = $gy;
            }
        };
        foreach ($project->odfs as $o) {
            if ($o->latitude !== null) {
                $expandBbox((float) $o->latitude, (float) $o->longitude);
            }
        }
        foreach ($project->cabinets as $c) {
            if ($c->latitude !== null) {
                $expandBbox((float) $c->latitude, (float) $c->longitude);
            }
        }
        foreach ($project->houses as $h) {
            if ($h->latitude !== null) {
                $expandBbox((float) $h->latitude, (float) $h->longitude);
            }
        }
        foreach ($project->routes as $r) {
            foreach ($r->path ?? [] as $pt) {
                if (isset($pt[0], $pt[1])) {
                    $expandBbox((float) $pt[0], (float) $pt[1]);
                } // path: [lat, lng]
                elseif (isset($pt['lat'], $pt['lng'])) {
                    $expandBbox((float) $pt['lat'], (float) $pt['lng']);
                }
            }
        }
        $hasBbox = $bboxMinX < PHP_FLOAT_MAX;

        // ── Background layeri — single-pass: dekodiraj JSON jednom, piši features odmah ──
        $bgLayerNames = [];
        $bgEntityFiles = [];

        if ($request->isMethod('post')) {
            foreach ($request->input('background_layers', []) as $bg) {
                $rawKey = (string) ($bg['cache_key'] ?? '');
                $safeKey = preg_replace('/[^a-f0-9\-]/i', '', $rawKey);
                if ($safeKey === '' || strlen($safeKey) < 10 || strlen($safeKey) > 40) {
                    continue;
                }

                $storagePath = 'dxf_layers/'.$safeKey.'.json';
                if (! Storage::exists($storagePath)) {
                    return response()->json([
                        'error' => 'DXF cache fajl nije pronađen na serveru. Ukloni podlogu u DXF panelu i ponovo importuj fajl, zatim pokušaj export.',
                    ], 422);
                }

                // Jedan decode — skupi layer names + piši entities u temp fajl
                $json = Storage::get($storagePath);
                $features = json_decode($json, true);
                unset($json);

                if (! is_array($features) || empty($features)) {
                    continue;
                }

                $entTmp = tempnam(sys_get_temp_dir(), 'ftth_bg_');
                $entFh = fopen($entTmp, 'wb');
                if ($entFh === false) {
                    @unlink($entTmp);

                    continue;
                }

                foreach ($features as $f) {
                    $g = $f['geometry'] ?? null;
                    $typ = $g['type'] ?? '';
                    if (! $g || ! $typ) {
                        continue;
                    }

                    $rawName = (string) ($f['properties']['layer'] ?? '0');
                    $safeName = 'BG_'.preg_replace('/[^A-Z0-9_]/', '_', strtoupper($rawName));
                    $ln = substr($safeName, 0, 31);
                    $bgLayerNames[$ln] = true;

                    if ($typ === 'LineString') {
                        $pts = array_map(fn ($c) => $this->rawCoordToGk($c[0], $c[1], $zone), $g['coordinates']);
                        if (count($pts) >= 2) {
                            fwrite($entFh, implode("\r\n", $this->dxfPolylineGk($pts, $ln, 9))."\r\n");
                        }
                    } elseif ($typ === 'Polygon') {
                        $outer = array_map(fn ($c) => $this->rawCoordToGk($c[0], $c[1], $zone), $g['coordinates'][0] ?? []);
                        if (count($outer) >= 3) {
                            if ($outer[0] !== $outer[count($outer) - 1]) {
                                $outer[] = $outer[0];
                            }
                            fwrite($entFh, implode("\r\n", $this->dxfPolylineGk($outer, $ln, 9))."\r\n");
                        }
                    } elseif ($typ === 'Point') {
                        $text = trim((string) ($f['properties']['text'] ?? ''));
                        $height = (float) ($f['properties']['height'] ?? 2.0);
                        if ($text !== '') {
                            [$gx, $gy] = $this->rawCoordToGk($g['coordinates'][0], $g['coordinates'][1], $zone);
                            fwrite($entFh, implode("\r\n", $this->dxfText($gx, $gy, $text, $ln, 9, max(0.5, $height)))."\r\n");
                        }
                    }
                }

                fclose($entFh);
                unset($features);
                $bgEntityFiles[] = $entTmp;
            }
        }

        // Defincije DXF layera za background (sivi, ACI 9)
        $bgLayerDefs = [];
        foreach (array_keys($bgLayerNames) as $ln) {
            array_push($bgLayerDefs, '0', 'LAYER', '2', $ln, '70', '64', '62', '9', '6', 'CONTINUOUS');
        }

        // Extents za header (FTTH bbox + 50m margin za $EXTMIN/$EXTMAX)
        $extMargin = 50.0;
        $extMinX = $hasBbox ? (string) ($bboxMinX - $extMargin) : '0.0';
        $extMinY = $hasBbox ? (string) ($bboxMinY - $extMargin) : '0.0';
        $extMaxX = $hasBbox ? (string) ($bboxMaxX + $extMargin) : '1000.0';
        $extMaxY = $hasBbox ? (string) ($bboxMaxY + $extMargin) : '1000.0';

        $lines = [
            // ── HEADER ────────────────────────────────────────────────────────────
            '0', 'SECTION', '2', 'HEADER',
            '9', '$ACADVER', '1', 'AC1009',
            '9', '$PDMODE', '70', '3',
            '9', '$PDSIZE', '40', '4.0',
            '9', '$LTSCALE', '40', '1.0',
            '9', '$EXTMIN', '10', $extMinX, '20', $extMinY,
            '9', '$EXTMAX', '10', $extMaxX, '20', $extMaxY,
            '9', '$LIMMIN', '10', $extMinX, '20', $extMinY,
            '9', '$LIMMAX', '10', $extMaxX, '20', $extMaxY,
            '0', 'ENDSEC',

            // ── TABLES ────────────────────────────────────────────────────────────
            '0', 'SECTION', '2', 'TABLES',

            // Linetypes
            '0', 'TABLE', '2', 'LTYPE', '70', '2',
            '0', 'LTYPE', '2', 'CONTINUOUS', '70', '64', '3', 'Solid line', '72', '65', '73', '0', '40', '0.0',
            '0', 'LTYPE', '2', 'DASHED',     '70', '64', '3', '_ _ _ _ _ _', '72', '65', '73', '2', '40', '3.0', '49', '2.0', '49', '-1.0',
            '0', 'ENDTAB',

            // Layeri (11 FTTH + dinamički background)
            '0', 'TABLE', '2', 'LAYER', '70', (string) (11 + count($bgLayerNames)),
            '0', 'LAYER', '2', '0',              '70', '64', '62',  '7', '6', 'CONTINUOUS',
            '0', 'LAYER', '2', 'FTTH_PRIMARY',   '70', '64', '62',  '5', '6', 'CONTINUOUS',
            '0', 'LAYER', '2', 'FTTH_SECONDARY', '70', '64', '62',  '3', '6', 'CONTINUOUS',
            '0', 'LAYER', '2', 'FTTH_TRENCH',    '70', '64', '62',  '2', '6', 'CONTINUOUS',
            '0', 'LAYER', '2', 'FTTH_DROP',      '70', '64', '62',  '4', '6', 'CONTINUOUS',
            '0', 'LAYER', '2', 'FTTH_LABELS',    '70', '64', '62',  '7', '6', 'CONTINUOUS',
            '0', 'LAYER', '2', 'FTTH_ODF',       '70', '64', '62',  '5', '6', 'CONTINUOUS',
            '0', 'LAYER', '2', 'FTTH_BORING',    '70', '64', '62',  '1', '6', 'CONTINUOUS',
            '0', 'LAYER', '2', 'FTTH_MANHOLES',  '70', '64', '62',  '8', '6', 'CONTINUOUS',
            '0', 'LAYER', '2', 'FTTH_CABINETS',  '70', '64', '62',  '6', '6', 'CONTINUOUS',
            '0', 'LAYER', '2', 'FTTH_HOUSES',    '70', '64', '62',  '4', '6', 'CONTINUOUS',
        ];
        // Dodaj background layer definicije
        array_push($lines, ...$bgLayerDefs);
        array_push($lines,
            '0', 'ENDTAB',
            // Text stil — romans.shx, varijabilna visina (40=0), širina 0.8
            '0', 'TABLE', '2', 'STYLE', '70', '1',
            '0', 'STYLE', '2', 'FTTH',
            '70', '0', '40', '0.0', '41', '0.8', '50', '0.0', '71', '0', '42', '3.0',
            '3', 'romans.shx', '4', '',
            '0', 'ENDTAB',
            '0', 'ENDSEC',
            // ── ENTITIES ──────────────────────────────────────────────────────────
            '0', 'SECTION', '2', 'ENTITIES'
        );

        // ── PASS 1: Podbušivanja ──────────────────────────────────────────────────
        // Crtamo ih PRIJE trasa da njihove labele blokiraju pozicije rutama.
        $usedLabelPositions = [];
        $minLabelDist = 50.0;
        $boringCount = 0;
        $drawnBoringPositions = []; // dedupliciranje — sigurnosni net

        foreach ($project->appendixItems as $item) {
            if ($item->latitude === null || $item->longitude === null) {
                continue;
            }
            [$cx, $cy] = $this->wgs84ToGaussKruger((float) $item->latitude, (float) $item->longitude, $zone);

            if ($item->type === 'boring_fi_130') {
                // Preskoči duplikat na istoj poziciji (unutar 2 m)
                $isDup = false;
                foreach ($drawnBoringPositions as [$bx, $by]) {
                    if (abs($cx - $bx) < 2.0 && abs($cy - $by) < 2.0) {
                        $isDup = true;
                        break;
                    }
                }
                if ($isDup) {
                    continue;
                }
                $drawnBoringPositions[] = [$cx, $cy];
                $angleRad = deg2rad((float) ($item->angle_deg ?? 0));
                $ddx = cos($angleRad);
                $ddy = sin($angleRad);
                $nnx = -$ddy;
                $nny = $ddx;
                $halfLen = max((float) ($item->length_m ?? 12), 1) / 2;
                $halfW = max((float) ($item->width_m ?? 1.8), 0.5) / 2;

                // Dvije paralelne linije koje predstavljaju fi130 cijev
                array_push($lines, ...$this->dxfLine(
                    $cx - $halfLen * $ddx + $halfW * $nnx, $cy - $halfLen * $ddy + $halfW * $nny,
                    $cx + $halfLen * $ddx + $halfW * $nnx, $cy + $halfLen * $ddy + $halfW * $nny,
                    'FTTH_BORING', 1
                ));
                array_push($lines, ...$this->dxfLine(
                    $cx - $halfLen * $ddx - $halfW * $nnx, $cy - $halfLen * $ddy - $halfW * $nny,
                    $cx + $halfLen * $ddx - $halfW * $nnx, $cy + $halfLen * $ddy - $halfW * $nny,
                    'FTTH_BORING', 1
                ));

                $boringCount++;

                // Perpendikularni smjer — uvijek prema sjeveru (pozitivni Y)
                $perpX = $nnx;
                $perpY = $nny;
                if ($perpY < 0) {
                    $perpX = -$perpX;
                    $perpY = -$perpY;
                }

                // Probaj više kandidatnih pozicija da izbjegnemo koliziju s prethodnim labelama
                $candidates = [
                    [$perpX,  $perpY,  10.0],
                    [$perpX,  $perpY,  22.0],
                    [$perpX,  $perpY,  35.0],
                    [-$perpX, -$perpY, 10.0],
                    [-$perpX, -$perpY, 22.0],
                ];
                $textX = $cx + $perpX * 10.0; // default (uvijek postavi nešto)
                $textY = $cy + $perpY * 10.0;
                foreach ($candidates as [$px, $py, $off]) {
                    $tx = $cx + $px * $off;
                    $ty = $cy + $py * $off;
                    $ok = true;
                    foreach ($usedLabelPositions as [$qx, $qy]) {
                        if (sqrt(($tx - $qx) ** 2 + ($ty - $qy) ** 2) < 35.0) {
                            $ok = false;
                            break;
                        }
                    }
                    if ($ok) {
                        $textX = $tx;
                        $textY = $ty;
                        $perpX = $px;
                        $perpY = $py;
                        break;
                    }
                }

                $label1 = 'Busenje ispod ceste '.$boringCount;
                $label2 = 'FI130 / '.number_format((float) ($item->length_m ?? 12), 1).' m';

                $usedLabelPositions[] = [$textX, $textY];

                array_push($lines, ...$this->dxfLine($cx, $cy, $textX, $textY, 'FTTH_BORING', 1));
                array_push($lines, ...$this->dxfArrowhead($cx, $cy, -$perpX, -$perpY, 2.0, 'FTTH_BORING', 1));
                array_push($lines, ...$this->dxfText($textX, $textY, $label1, 'FTTH_BORING', 1, 1.8));
                array_push($lines, ...$this->dxfText($textX, $textY - 3.0, $label2, 'FTTH_BORING', 1, 1.5));
            }
        }

        // ── PASS 2a: Prikupi sve segmente svih trasa + nacrtaj poliline ──────────
        // Segmenti se koriste u PASS 2b da spriječe postavljanje labele preko linije.

        $allRouteSegs = []; // svi segmenti svih trasa u GK metrima
        $routeDataList = []; // pripremljeni podaci za labele

        foreach ($project->routes as $route) {
            if ($route->route_type === 'trench') {
                continue;
            }

            $path = $route->path ?? [];
            if (count($path) < 2) {
                continue;
            }

            $gkPath = array_map(fn ($p) => $this->wgs84ToGaussKruger((float) $p[0], (float) $p[1], $zone), $path);
            $layer = $this->dxfLayerForRoute($route);
            $color = $this->dxfColorForRoute($route);

            array_push($lines, ...$this->dxfPolylineGk($gkPath, $layer, $color));

            // Sakupi sve segmente ove trase
            for ($si = 1; $si < count($gkPath); $si++) {
                $allRouteSegs[] = [$gkPath[$si - 1][0], $gkPath[$si - 1][1], $gkPath[$si][0], $gkPath[$si][1]];
            }

            $lengthM = (float) ($route->duct_length_m ?: $this->gkPathLength($gkPath));
            $specs = [];
            if ($route->duct_length_m) {
                $specs[] = $route->duct_length_m.' m';
            }
            if ($route->fiber_count) {
                $specs[] = $route->fiber_count.'F';
            }
            if ($route->microduct_type) {
                $specs[] = $route->microduct_type.' mc';
            }

            $routeDataList[] = [
                'gkPath' => $gkPath,
                'name' => $route->name,
                'specsLine' => implode(' / ', $specs),
                'lengthM' => $lengthM,
            ];
        }

        // ── PASS 2b: Postavi labele — provjeri koliziju s labelama I s linijama trasa ──

        $leaderOffset = 12.0;
        $routeClearance = 3.5; // minimalna udaljenost teksta od bilo koje linije trase

        foreach ($routeDataList as $rd) {
            $gkPath = $rd['gkPath'];
            $name = $rd['name'];
            $specsLine = $rd['specsLine'];
            $nameW = max(strlen($name), 4) * 1.4;      // procjena širine naziva (m)
            $specsW = max(strlen($specsLine), 4) * 1.05; // procjena širine specifikacija (m)
            $maxW = max($nameW, $specsW);

            $labelCount = $this->labelCountForLength($rd['lengthM']);
            $labelPts = $this->interpolateGkPoints($gkPath, $labelCount);

            $placedForRoute = 0;

            foreach ($labelPts as [$lx, $ly]) {
                [$tanX, $tanY] = $this->pathTangentAt($gkPath, $lx, $ly);
                $perpX = -$tanY;
                $perpY = $tanX;
                if ($perpY < 0) {
                    $perpX = -$perpX;
                    $perpY = -$perpY;
                }

                // Probaj više offseta (bliže → dalje → suprotna strana)
                $offsets = [
                    [$perpX,  $perpY,  $leaderOffset],
                    [$perpX,  $perpY,  $leaderOffset + 15.0],
                    [$perpX,  $perpY,  $leaderOffset + 30.0],
                    [-$perpX, -$perpY, $leaderOffset],
                    [-$perpX, -$perpY, $leaderOffset + 15.0],
                ];

                foreach ($offsets as [$ox, $oy, $off]) {
                    $textX = $lx + $ox * $off;
                    $textY = $ly + $oy * $off;

                    // 1) Anti-collision s prethodnim labelama
                    $labelConflict = false;
                    foreach ($usedLabelPositions as [$px, $py]) {
                        if (sqrt(($textX - $px) ** 2 + ($textY - $py) ** 2) < $minLabelDist) {
                            $labelConflict = true;
                            break;
                        }
                    }
                    if ($labelConflict) {
                        continue;
                    }

                    // 2) Provjeri da tekst ne prelazi preko linije trase
                    // Uzorkuj 4 tačke po širini teksta na 2 visine (naziv + specs)
                    $checkPts = [
                        [$textX,            $textY + 1.0],
                        [$textX + $maxW / 2,  $textY + 1.0],
                        [$textX + $maxW,    $textY + 1.0],
                        [$textX + $maxW / 2,  $textY - 2.5], // specs red
                    ];
                    $routeConflict = false;
                    // bbox pre-filter: provjeri samo segmente blizu kandidatskog mjesta
                    $bbMinX = $textX - $routeClearance;
                    $bbMaxX = $textX + $maxW + $routeClearance;
                    $bbMinY = $textY - 2.5 - $routeClearance;
                    $bbMaxY = $textY + 1.0 + $routeClearance;
                    foreach ($checkPts as [$cpx, $cpy]) {
                        foreach ($allRouteSegs as [$ax, $ay, $bx, $by]) {
                            // Preskoči segment ako mu je bbox daleko od teksta
                            if (max($ax, $bx) < $bbMinX || min($ax, $bx) > $bbMaxX) {
                                continue;
                            }
                            if (max($ay, $by) < $bbMinY || min($ay, $by) > $bbMaxY) {
                                continue;
                            }
                            if ($this->pointToSegmentDist($cpx, $cpy, $ax, $ay, $bx, $by) < $routeClearance) {
                                $routeConflict = true;
                                break 2;
                            }
                        }
                    }
                    if ($routeConflict) {
                        continue;
                    }

                    // Prihvati ovu poziciju
                    $usedLabelPositions[] = [$textX, $textY];
                    $placedForRoute++;

                    array_push($lines, ...$this->dxfLine($lx, $ly, $textX, $textY, 'FTTH_LABELS', 8));
                    array_push($lines, ...$this->dxfArrowhead($lx, $ly, -$ox, -$oy, 2.0, 'FTTH_LABELS', 8));
                    array_push($lines, ...$this->dxfText($textX, $textY, $name, 'FTTH_LABELS', 7, 2.0));
                    if ($specsLine !== '') {
                        array_push($lines, ...$this->dxfText($textX, $textY - 3.5, $specsLine, 'FTTH_LABELS', 8, 1.5));
                    }
                    break;
                }
            }

            // Fallback: ako nijedna pozicija nije prošla, stavi labelu na sredinu bez provjere trasa
            if ($placedForRoute === 0 && count($gkPath) > 0) {
                $mid = $this->interpolateGkPoints($gkPath, 1);
                if (! empty($mid)) {
                    [$lx, $ly] = $mid[0];
                    [$tanX, $tanY] = $this->pathTangentAt($gkPath, $lx, $ly);
                    $perpX = -$tanY;
                    $perpY = $tanX;
                    if ($perpY < 0) {
                        $perpX = -$perpX;
                        $perpY = -$perpY;
                    }
                    $textX = $lx + $perpX * $leaderOffset;
                    $textY = $ly + $perpY * $leaderOffset;
                    $usedLabelPositions[] = [$textX, $textY];
                    array_push($lines, ...$this->dxfLine($lx, $ly, $textX, $textY, 'FTTH_LABELS', 8));
                    array_push($lines, ...$this->dxfArrowhead($lx, $ly, -$perpX, -$perpY, 2.0, 'FTTH_LABELS', 8));
                    array_push($lines, ...$this->dxfText($textX, $textY, $name, 'FTTH_LABELS', 7, 2.0));
                    if ($specsLine !== '') {
                        array_push($lines, ...$this->dxfText($textX, $textY - 3.5, $specsLine, 'FTTH_LABELS', 8, 1.5));
                    }
                }
            }
        }

        // ODF — simbol + naziv u pravokutniku ispod zone (žuta boja = 2, kao u ručnim projektima)
        foreach ($project->odfs as $odf) {
            if ($odf->latitude === null || $odf->longitude === null) {
                continue;
            }
            [$x, $y] = $this->wgs84ToGaussKruger((float) $odf->latitude, (float) $odf->longitude, $zone);
            array_push($lines, ...$this->dxfSymbolOdf($x, $y, 'FTTH_ODF', 2));

            // Naziv u pravokutniku ispod zone (r2=10m, tekst visine 3m)
            $tw = max(strlen($odf->name) * 1.8, 12.0); // procjena širine teksta
            $th = 3.0;   // visina teksta
            $pad = 1.5;   // padding unutar pravokutnika
            $ty = $y - 10.0 - $th - $pad * 2 - 1.0; // ispod zone kruga
            $tx = $x - $tw / 2;
            // Pravokutnik oko naziva
            array_push($lines, ...$this->dxfLine($tx - $pad, $ty - $pad, $tx + $tw + $pad, $ty - $pad, 'FTTH_ODF', 2));
            array_push($lines, ...$this->dxfLine($tx + $tw + $pad, $ty - $pad, $tx + $tw + $pad, $ty + $th + $pad, 'FTTH_ODF', 2));
            array_push($lines, ...$this->dxfLine($tx + $tw + $pad, $ty + $th + $pad, $tx - $pad, $ty + $th + $pad, 'FTTH_ODF', 2));
            array_push($lines, ...$this->dxfLine($tx - $pad, $ty + $th + $pad, $tx - $pad, $ty - $pad, 'FTTH_ODF', 2));
            array_push($lines, ...$this->dxfText($tx, $ty, $odf->name, 'FTTH_ODF', 2, $th));
        }

        // FTTH ormarici — boja iz palete, kružnica r=3m + label
        foreach ($project->cabinets as $cabinet) {
            if ($cabinet->latitude === null || $cabinet->longitude === null) {
                continue;
            }
            [$x, $y] = $this->wgs84ToGaussKruger((float) $cabinet->latitude, (float) $cabinet->longitude, $zone);
            $color = $cabinetColor[$cabinet->id];
            array_push($lines, ...$this->dxfSymbolCabinet($x, $y, 'FTTH_CABINETS', $color));
            array_push($lines, ...$this->dxfText($x + 4.0, $y, $cabinet->name, 'FTTH_LABELS', $color, 2.5));
        }

        // Kuce — ista boja kao ormaric kojoj pripadaju, simbol kuce
        foreach ($project->houses as $house) {
            if ($house->latitude === null || $house->longitude === null) {
                continue;
            }
            [$x, $y] = $this->wgs84ToGaussKruger((float) $house->latitude, (float) $house->longitude, $zone);
            $color = $cabinetColor[$house->cabinet_id] ?? 8;
            array_push($lines, ...$this->dxfSymbolHouse($x, $y, 'FTTH_HOUSES', $color));
        }

        // Šahti (borings su već ucrtani u PASS 1)
        foreach ($project->appendixItems as $item) {
            if ($item->type !== 'manhole') {
                continue;
            }
            if ($item->latitude === null || $item->longitude === null) {
                continue;
            }
            [$cx, $cy] = $this->wgs84ToGaussKruger((float) $item->latitude, (float) $item->longitude, $zone);
            array_push($lines, ...$this->dxfCircle($cx, $cy, 2.0, 'FTTH_MANHOLES', 8));
            array_push($lines, ...$this->dxfText($cx + 3.0, $cy, 'Saht', 'FTTH_MANHOLES', 8, 2.0));
        }

        // ── Streaming u temp fajl — izbjegava $lines + implode peak memorije ────
        $tmpPath = tempnam(sys_get_temp_dir(), 'ftth_dxf_');
        $fh = fopen($tmpPath, 'wb');

        // Piši FTTH dio (mali array, OK u memoriji)
        fwrite($fh, implode("\r\n", $lines)."\r\n");
        unset($lines);

        // Append background entity temp fajlova (svaki je iscrtan u single-pass gore)
        foreach ($bgEntityFiles as $entTmp) {
            $src = fopen($entTmp, 'rb');
            if ($src) {
                stream_copy_to_stream($src, $fh);
                fclose($src);
            }
            @unlink($entTmp);
        }

        fwrite($fh, "0\r\nENDSEC\r\n0\r\nEOF\r\n");
        fclose($fh);

        $exportCode = str($project->code ?: $project->name)->slug()->value() ?: 'projekat-'.$project->id;
        $filename = $exportCode.'-ftth.dxf';

        return response()->download($tmpPath, $filename, [
            'Content-Type' => 'application/dxf',
        ])->deleteFileAfterSend(true);
    }

    private function dxfPolylineGk(array $gkPath, string $layer, int $color): array
    {
        $lines = ['0', 'POLYLINE', '8', $layer, '62', (string) $color, '66', '1', '70', '0'];
        foreach ($gkPath as [$x, $y]) {
            $lines[] = '0';
            $lines[] = 'VERTEX';
            $lines[] = '8';
            $lines[] = $layer;
            $lines[] = '10';
            $lines[] = (string) $x;
            $lines[] = '20';
            $lines[] = (string) $y;
            $lines[] = '30';
            $lines[] = '0';
        }
        $lines[] = '0';
        $lines[] = 'SEQEND';

        return $lines;
    }

    private function interpolateGkPoints(array $gkPath, int $n): array
    {
        $count = count($gkPath);
        if ($count === 0 || $n <= 0) {
            return [];
        }

        $cumDist = [0.0];
        for ($i = 1; $i < $count; $i++) {
            $dx = $gkPath[$i][0] - $gkPath[$i - 1][0];
            $dy = $gkPath[$i][1] - $gkPath[$i - 1][1];
            $cumDist[] = $cumDist[$i - 1] + sqrt($dx * $dx + $dy * $dy);
        }
        $total = end($cumDist);

        $result = [];
        for ($i = 1; $i <= $n; $i++) {
            // Unutrasnje pozicije: i/(n+1) — nikad na samim krajevima trase
            $target = ($i / ($n + 1)) * $total;
            for ($j = 1; $j < $count; $j++) {
                if ($cumDist[$j] >= $target || $j === $count - 1) {
                    $segLen = $cumDist[$j] - $cumDist[$j - 1];
                    $t = $segLen > 0 ? ($target - $cumDist[$j - 1]) / $segLen : 0;
                    $result[] = [
                        $gkPath[$j - 1][0] + $t * ($gkPath[$j][0] - $gkPath[$j - 1][0]),
                        $gkPath[$j - 1][1] + $t * ($gkPath[$j][1] - $gkPath[$j - 1][1]),
                    ];
                    break;
                }
            }
        }

        return $result;
    }

    private function gkPathLength(array $gkPath): float
    {
        $total = 0.0;
        for ($i = 1; $i < count($gkPath); $i++) {
            $dx = $gkPath[$i][0] - $gkPath[$i - 1][0];
            $dy = $gkPath[$i][1] - $gkPath[$i - 1][1];
            $total += sqrt($dx * $dx + $dy * $dy);
        }

        return $total;
    }

    private function labelCountForLength(float $lengthM): int
    {
        // Minimalni razmak 50 m između labela (labela je ~30-40 m široka)
        return max(1, min(6, (int) floor($lengthM / 50)));
    }

    // Ormarić: romb + ispunjena kružnica u centru (kao u ručnom projektu)
    private function dxfSymbolCabinet(float $x, float $y, string $layer, int $color): array
    {
        $s = 4.0;  // pola dijagonale romba
        $rc = 2.2;  // radius unutrašnje ispunjene kružnice (~55% od s)
        // 16 SOLID trokuta = glatka ispunjena kružnica
        $pts = [];
        for ($i = 0; $i < 16; $i++) {
            $a1 = deg2rad($i * 22.5);
            $a2 = deg2rad(($i + 1) * 22.5);
            $p1x = $x + $rc * cos($a1);
            $p1y = $y + $rc * sin($a1);
            $p2x = $x + $rc * cos($a2);
            $p2y = $y + $rc * sin($a2);
            // SOLID trokut: centar, p1, p2 (v3=v4 za trokut)
            $pts = array_merge($pts, $this->dxfSolid(
                $x, $y,
                $p1x, $p1y,
                $p2x, $p2y,
                $p2x, $p2y,
                $layer, $color
            ));
        }

        return [
            ...$this->dxfLine($x, $y + $s, $x + $s, $y, $layer, $color),
            ...$this->dxfLine($x + $s, $y, $x, $y - $s, $layer, $color),
            ...$this->dxfLine($x, $y - $s, $x - $s, $y, $layer, $color),
            ...$this->dxfLine($x - $s, $y, $x, $y + $s, $layer, $color),
            ...$pts,
        ];
    }

    // ODF: žuti isprekidani krug (zona) + žuti mali krug + pravougaonik žuti okvir/plavi ispun
    private function dxfSymbolOdf(float $x, float $y, string $layer, int $color): array
    {
        $w = 2.0;   // pola širine pravougaonika
        $h = 1.2;   // pola visine pravougaonika
        $r1 = 3.5;   // mali puni krug oko simbola
        $r2 = 10.0;  // zona pokrivenosti — isprekidani krug

        return [
            // Isprekidani žuti krug — zona pokrivenosti
            ...$this->dxfCircleDashed($x, $y, $r2, $layer, $color, 1.0),
            // Mali puni žuti krug oko simbola
            ...$this->dxfCircle($x, $y, $r1, $layer, $color),
            // Plavi ispun pravougaonika (SOLID, v1-v2-v4-v3 = filled rect)
            ...$this->dxfSolid(
                $x - $w, $y + $h,   // v1 gornji-lijevi
                $x + $w, $y + $h,   // v2 gornji-desni
                $x - $w, $y - $h,   // v3 donji-lijevi
                $x + $w, $y - $h,   // v4 donji-desni
                $layer, 5            // plava boja
            ),
            // Žuti okvir pravougaonika (na vrhu plavog ispuna)
            ...$this->dxfLine($x - $w, $y - $h, $x + $w, $y - $h, $layer, $color),
            ...$this->dxfLine($x + $w, $y - $h, $x + $w, $y + $h, $layer, $color),
            ...$this->dxfLine($x + $w, $y + $h, $x - $w, $y + $h, $layer, $color),
            ...$this->dxfLine($x - $w, $y + $h, $x - $w, $y - $h, $layer, $color),
        ];
    }

    // Kuca: ispunjeni pravokutnik (zidovi) + ispunjeni trokut (krov)
    private function dxfSymbolHouse(float $x, float $y, string $layer, int $color): array
    {
        $hw = 1.0;  // pola širine
        $yb = -0.9; // dno zidova (relativno)
        $yt = 0.3;  // vrh zidova / osnova krova (relativno)
        $yp = 1.4;  // vrh krova (relativno)

        // Zidovi — ispunjeni pravokutnik
        // SOLID: v1=BL, v2=BR, v3=TL, v4=TR → popunjava BL→BR→TR→TL
        $walls = $this->dxfSolid(
            $x - $hw, $y + $yb,
            $x + $hw, $y + $yb,
            $x - $hw, $y + $yt,
            $x + $hw, $y + $yt,
            $layer, $color
        );
        // Krov — ispunjeni trokut (v3=v4 = vrh)
        $roof = $this->dxfSolid(
            $x - $hw, $y + $yt,
            $x + $hw, $y + $yt,
            $x, $y + $yp,
            $x, $y + $yp,
            $layer, $color
        );

        return [...$walls, ...$roof];
    }

    // Dvije linije koje čine strelicu na vrhu leadera. (dx, dy) = normalizovani smjer strelice prema meti.
    private function dxfArrowhead(float $x, float $y, float $dx, float $dy, float $size, string $layer, int $color): array
    {
        // Barbe pod 150° od smjera (tj. otvaraju se 30° iza vrha)
        $c = -0.866;
        $s = 0.500; // cos(150°), sin(150°)
        $x1 = $x + $size * ($c * $dx - $s * $dy);
        $y1 = $y + $size * ($s * $dx + $c * $dy);
        $x2 = $x + $size * ($c * $dx + $s * $dy);
        $y2 = $y + $size * (-$s * $dx + $c * $dy);

        return [
            ...$this->dxfLine($x, $y, $x1, $y1, $layer, $color),
            ...$this->dxfLine($x, $y, $x2, $y2, $layer, $color),
        ];
    }

    // Udaljenost točke (px,py) od segmenta (ax,ay)-(bx,by) u metrima.
    private function pointToSegmentDist(float $px, float $py, float $ax, float $ay, float $bx, float $by): float
    {
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $len2 = $dx * $dx + $dy * $dy;
        if ($len2 < 0.0001) {
            return sqrt(($px - $ax) ** 2 + ($py - $ay) ** 2);
        }
        $t = max(0.0, min(1.0, (($px - $ax) * $dx + ($py - $ay) * $dy) / $len2));

        return sqrt(($px - $ax - $t * $dx) ** 2 + ($py - $ay - $t * $dy) ** 2);
    }

    // Vraća normalizovani tangentni vektor trase u točki najbližoj (x, y).
    private function pathTangentAt(array $gkPath, float $x, float $y): array
    {
        $best = PHP_FLOAT_MAX;
        $tanX = 1.0;
        $tanY = 0.0;
        $n = count($gkPath);
        for ($i = 1; $i < $n; $i++) {
            $ax = $gkPath[$i - 1][0];
            $ay = $gkPath[$i - 1][1];
            $bx = $gkPath[$i][0];
            $by = $gkPath[$i][1];
            $segDx = $bx - $ax;
            $segDy = $by - $ay;
            $len = sqrt($segDx * $segDx + $segDy * $segDy);
            if ($len < 0.001) {
                continue;
            }
            $t = max(0.0, min(1.0, (($x - $ax) * $segDx + ($y - $ay) * $segDy) / ($len * $len)));
            $cx = $ax + $t * $segDx;
            $cy = $ay + $t * $segDy;
            $d = sqrt(($x - $cx) ** 2 + ($y - $cy) ** 2);
            if ($d < $best) {
                $best = $d;
                $tanX = $segDx / $len;
                $tanY = $segDy / $len;
            }
        }

        return [$tanX, $tanY];
    }

    // Koordinate iz importovanog DXF-a → standardni GK [easting, northing].
    // Tri slučaja:
    //  1) x (group 10) > 5M: standardni GK, x=easting — vrati direktno
    //  2) y (group 20) > 5M: stari jugoslovenski/bosanski DXF kadastar gdje je
    //     group 10 = northing (~4.9M) a group 20 = easting (~6.5M) — zamijeni
    //  3) ostalo: WGS84 (lon, lat) — konvertuj
    private function rawCoordToGk(float $x, float $y, int $zone): array
    {
        if ($x > 5_000_000 && $x < 9_000_000) {
            return [$x, $y]; // standardni GK: x=easting, y=northing
        }
        if ($y > 5_000_000 && $y < 9_000_000) {
            return [$y, $x]; // stari YU kadastar: x=northing, y=easting — swap
        }

        return $this->wgs84ToGaussKruger($y, $x, $zone); // WGS84: (lon, lat)
    }

    private function wgs84ToGaussKruger(float $lat, float $lng, int $zone = 6): array
    {
        // WGS84 ellipsoid
        $aW = 6378137.0;
        $eW2 = 0.00669437999014;

        // Bessel 1841 ellipsoid
        $aB = 6377397.155;
        $eB2 = 0.006674372230614;

        // WGS84 geographic → ECEF
        $latR = deg2rad($lat);
        $lngR = deg2rad($lng);
        $sinLat = sin($latR);
        $cosLat = cos($latR);
        $NW = $aW / sqrt(1.0 - $eW2 * $sinLat * $sinLat);
        $X = $NW * $cosLat * cos($lngR);
        $Y = $NW * $cosLat * sin($lngR);
        $Z = $NW * (1.0 - $eW2) * $sinLat;

        // Helmert shift WGS84 → MGI (obrnuto od towgs84=682,-203,480)
        $X -= 682.0;
        $Y += 203.0;
        $Z -= 480.0;

        // ECEF → MGI Bessel geographic (iterativno)
        $p = sqrt($X * $X + $Y * $Y);
        $lngB = atan2($Y, $X);
        $latB = atan2($Z, $p * (1.0 - $eB2));
        for ($i = 0; $i < 10; $i++) {
            $sLB = sin($latB);
            $NB = $aB / sqrt(1.0 - $eB2 * $sLB * $sLB);
            $latB = atan2($Z + $eB2 * $NB * $sLB, $p);
        }

        // Gauss-Krüger (Transverse Mercator)
        $k0 = 0.9999;
        $lon0 = deg2rad($zone * 3.0);
        $falseE = $zone * 1000000.0 + 500000.0;

        $sinLatB = sin($latB);
        $cosLatB = cos($latB);
        $tanLatB = tan($latB);
        $NB = $aB / sqrt(1.0 - $eB2 * $sinLatB * $sinLatB);
        $T = $tanLatB * $tanLatB;
        $eP2 = $eB2 / (1.0 - $eB2);
        $C = $eP2 * $cosLatB * $cosLatB;
        $A = $cosLatB * ($lngB - $lon0);

        $e4 = $eB2 * $eB2;
        $e6 = $e4 * $eB2;
        $M = $aB * (
            (1.0 - $eB2 / 4.0 - 3.0 * $e4 / 64.0 - 5.0 * $e6 / 256.0) * $latB
            - (3.0 * $eB2 / 8.0 + 3.0 * $e4 / 32.0 + 45.0 * $e6 / 1024.0) * sin(2.0 * $latB)
            + (15.0 * $e4 / 256.0 + 45.0 * $e6 / 1024.0) * sin(4.0 * $latB)
            - (35.0 * $e6 / 3072.0) * sin(6.0 * $latB)
        );

        $easting = $falseE + $k0 * $NB * (
            $A
            + (1.0 - $T + $C) * $A ** 3 / 6.0
            + (5.0 - 18.0 * $T + $T * $T + 72.0 * $C - 58.0 * $eP2) * $A ** 5 / 120.0
        );

        $northing = $k0 * (
            $M + $NB * $tanLatB * (
                $A ** 2 / 2.0
                + (5.0 - $T + 9.0 * $C + 4.0 * $C * $C) * $A ** 4 / 24.0
                + (61.0 - 58.0 * $T + $T * $T + 600.0 * $C - 330.0 * $eP2) * $A ** 6 / 720.0
            )
        );

        return [round($easting, 3), round($northing, 3)];
    }

    private function dxfLayerForRoute(NetworkRoute $route): string
    {
        return match ($route->route_type) {
            'trench' => 'FTTH_TRENCH',
            'drop' => 'FTTH_DROP',
            'backbone', 'feeder' => 'FTTH_PRIMARY',
            default => 'FTTH_SECONDARY',
        };
    }

    private function dxfColorForRoute(NetworkRoute $route): int
    {
        return match ($route->route_type) {
            'trench' => 7,
            'drop' => 30,
            'backbone', 'feeder' => 5,
            default => 3,
        };
    }
}
