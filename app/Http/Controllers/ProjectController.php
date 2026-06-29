<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesFtthData;
use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProjectController extends Controller
{
    use ManagesFtthData;

    public function projects(): View
    {
        return view('ftth.projects', [
            'projects' => Project::latest()->paginate(12),
            'projectStats' => [
                'total' => Project::count(),
                'active' => Project::where('status', 'active')->count(),
                'planning' => Project::where('status', 'planning')->count(),
                'completed' => Project::where('status', 'completed')->count(),
            ],
        ]);
    }

    public function showProject(Project $project): View
    {
        $project->load([
            'odfs.cabinets.houses',
            'cabinets' => fn ($q) => $q->withCount('houses')->with(['odf', 'houses', 'branch']),
            'houses.cabinet',
            'routes',
            'branches' => fn ($q) => $q->withCount('cabinets')->orderBy('sort_order'),
            'materials',
        ]);

        $validationItems = collect($this->ftthIntelligence->validateProject($project));
        $materials = $this->ftthIntelligence->materialSummary($project);

        $cableRoutes = $project->routes->where('route_type', '!=', 'trench');
        $trenchRoutes = $project->routes->where('route_type', 'trench');

        $odfCapacity = $project->odfs->map(fn ($odf) => [
            'odf' => $odf,
            'total' => $odf->fiber_capacity,
            'used' => $odf->cabinets->filter(fn ($c) => $c->parent_cabinet_id === null)->sum('splitter_count'),
        ]);

        return view('ftth.projects.show', compact('project', 'validationItems', 'materials', 'cableRoutes', 'trenchRoutes', 'odfCapacity'));
    }

    public function deleteProject(int $id)
    {
        Project::findOrFail($id)->delete();

        return back()->with('success', 'Projekat je obrisan.');
    }

    public function updateProject(Request $request, int $id): RedirectResponse
    {
        $project = Project::findOrFail($id);
        $project->update($request->validate([
            'name' => ['required', 'max:255'],
            'code' => ['required', 'max:50', 'unique:projects,code,'.$project->id],
            'location' => ['required', 'max:255'],
            'investor' => ['nullable', 'max:255'],
            'status' => ['required', 'in:planning,active,paused,completed'],
            'start_date' => ['nullable', 'date'],
            'deadline' => ['nullable', 'date'],
            'description' => ['nullable', 'max:2000'],
        ]));

        return back()->with('success', 'Projekat je azuriran.');
    }

    public function previewOdoPlan(Request $request, Project $project)
    {
        try {
            return response()->json($this->ftthIntelligence->previewOdoPlan($project, $request->all()));
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function confirmOdoPlan(Request $request, Project $project)
    {
        $data = $request->validate([
            'plan' => ['required', 'array'],
            'create_drop_routes' => ['nullable', 'boolean'],
        ]);

        try {
            return response()->json($this->ftthIntelligence->confirmOdoPlan(
                $project,
                $data['plan'],
                $request->boolean('create_drop_routes')
            ), 201);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'Plan nije snimljen. Sve izmjene su ponistene.'], 500);
        }
    }

    public function validateProject(Project $project)
    {
        return response()->json([
            'project' => ['id' => $project->id, 'name' => $project->name],
            'items' => $this->ftthIntelligence->validateProject($project),
            'materials' => $this->ftthIntelligence->materialSummary($project),
        ]);
    }

    public function calculateMaterials(Project $project): RedirectResponse
    {
        $routes = $project->routes()->where('route_type', '!=', 'trench')->get();

        $toUpsert = [];
        foreach ($routes->groupBy('microduct_type')->filter(fn ($g, $t) => filled($t)) as $type => $group) {
            $toUpsert["Mikrocijev $type"] = ['unit' => 'm', 'planned_quantity' => $group->sum(fn ($r) => $r->duct_length_m * max((int) $r->microduct_count, 1))];
        }
        foreach ($routes->groupBy('fiber_count')->filter(fn ($g, $c) => filled($c)) as $count => $group) {
            $toUpsert["Opticki kabl $count niti"] = ['unit' => 'm', 'planned_quantity' => $group->sum('fiber_length_m')];
        }

        DB::transaction(function () use ($project, $toUpsert) {
            foreach ($toUpsert as $name => $data) {
                $project->materials()->updateOrCreate(['name' => $name], $data);
            }
        });

        return redirect()->back();
    }

    public function createMissingDropRoutes(Project $project)
    {
        $existingDropHouseIds = array_flip(
            NetworkRoute::where('project_id', $project->id)
                ->where('route_type', 'drop')
                ->where('to_type', 'house')
                ->pluck('to_id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );

        $houses = $project->houses()
            ->with('cabinet')
            ->whereNotNull('cabinet_id')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->reject(fn (House $house) => isset($existingDropHouseIds[$house->id]));

        $routes = DB::transaction(function () use ($project, $houses) {
            return $houses->map(function (House $house) use ($project) {
                $cabinet = $house->cabinet;
                if (! $cabinet || $cabinet->latitude === null || $cabinet->longitude === null) {
                    return null;
                }

                $path = $this->dropPathForHouse($cabinet, $house);
                $length = $this->polylineLength($path);

                return NetworkRoute::create([
                    'project_id' => $project->id,
                    'cabinet_id' => $cabinet->id,
                    'from_type' => 'cabinet',
                    'from_id' => $cabinet->id,
                    'to_type' => 'house',
                    'to_id' => $house->id,
                    'name' => $this->uniqueProjectName(NetworkRoute::class, $project->id, "Drop {$cabinet->name}-{$house->label}"),
                    'route_type' => 'drop',
                    'installation_type' => 'underground',
                    'duct_length_m' => $length,
                    'fiber_length_m' => $length,
                    'fiber_count' => 4,
                    'microduct_count' => 1,
                    'microduct_type' => '10/8',
                    'status' => 'planned',
                    'path' => $path,
                ]);
            })->filter()->values();
        });

        return response()->json([
            'message' => 'Nedostajuce drop trase su kreirane.',
            'created' => $routes->count(),
            'routes' => $routes->map(fn (NetworkRoute $route) => [
                'id' => $route->id,
                'name' => $route->name,
                'type' => $route->route_type,
                'length' => $route->duct_length_m,
                'duct_length_m' => $route->duct_length_m,
                'microduct' => $route->microduct_type,
                'fibers' => $route->fiber_count,
                'path' => $route->path,
                'note' => $route->note,
                'from_type' => $route->from_type,
                'from_id' => $route->from_id,
                'to_type' => $route->to_type,
                'to_id' => $route->to_id,
                'cabinet_id' => $route->cabinet_id,
            ]),
        ]);
    }

    public function exportGeoJson(Project $project)
    {
        $project->load(['odfs', 'cabinets', 'houses', 'routes']);
        $features = collect();

        $project->odfs->whereNotNull('latitude')->whereNotNull('longitude')->each(function (Odf $odf) use ($features): void {
            $features->push($this->geoJsonPoint('odf', $odf->id, $odf->name, (float) $odf->latitude, (float) $odf->longitude, [
                'address' => $odf->address,
                'fiber_capacity' => $odf->fiber_capacity,
                'port_count' => $odf->port_count,
            ]));
        });

        $project->cabinets->whereNotNull('latitude')->whereNotNull('longitude')->each(function (Cabinet $cabinet) use ($features): void {
            $features->push($this->geoJsonPoint('ftth', $cabinet->id, $cabinet->name, (float) $cabinet->latitude, (float) $cabinet->longitude, [
                'odf_id' => $cabinet->odf_id,
                'parent_cabinet_id' => $cabinet->parent_cabinet_id,
                'splitter_count' => $cabinet->splitter_count,
                'capacity' => $cabinet->capacity,
            ]));
        });

        $project->houses->whereNotNull('latitude')->whereNotNull('longitude')->each(function (House $house) use ($features): void {
            $features->push($this->geoJsonPoint('house', $house->id, $house->label, (float) $house->latitude, (float) $house->longitude, [
                'cabinet_id' => $house->cabinet_id,
                'status' => $house->status,
                'address' => $house->address,
            ]));
        });

        $project->routes->filter(fn (NetworkRoute $route) => count($route->path ?? []) > 1)->each(function (NetworkRoute $route) use ($features): void {
            $features->push([
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => collect($route->path)->map(fn (array $point) => [(float) $point[1], (float) $point[0]])->values()->all(),
                ],
                'properties' => [
                    'element_type' => 'route',
                    'id' => $route->id,
                    'name' => $route->name,
                    'route_type' => $route->route_type,
                    'duct_length_m' => $route->duct_length_m,
                    'fiber_length_m' => $route->fiber_length_m,
                    'fiber_count' => $route->fiber_count,
                    'microduct_count' => $route->microduct_count,
                    'microduct_type' => $route->microduct_type,
                ],
            ]);
        });

        $payload = [
            'type' => 'FeatureCollection',
            'name' => $project->code ?: $project->name,
            'features' => $features->values()->all(),
        ];

        return response()->json($payload, 200, [
            'Content-Disposition' => 'attachment; filename="'.$project->code.'-ftth.geojson"',
        ], JSON_UNESCAPED_UNICODE);
    }

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

        // Diagnostic: logiraj GK koordinate da provjerimo alignment
        if ($hasBbox) {
            \Log::info('DXF Export FTTH bbox (GK Zone '.$zone.')', [
                'minX' => round($bboxMinX), 'maxX' => round($bboxMaxX),
                'minY' => round($bboxMinY), 'maxY' => round($bboxMaxY),
            ]);
        }
        if (! empty($bgEntityFiles)) {
            // Logiraj prvu background koordinatu iz cache-a
            foreach ($request->input('background_layers', []) as $bg) {
                $ck = preg_replace('/[^a-f0-9\-]/i', '', (string) ($bg['cache_key'] ?? ''));
                $sp = 'dxf_layers/'.$ck.'.json';
                if (Storage::exists($sp)) {
                    $sample = json_decode(Storage::get($sp), true);
                    $firstF = $sample[0] ?? null;
                    if ($firstF) {
                        $fc = ($firstF['geometry']['coordinates'][0] ?? $firstF['geometry']['coordinates']) ?? null;
                        if ($fc && isset($fc[0])) {
                            [$bgX, $bgY] = $this->rawCoordToGk((float) $fc[0], (float) $fc[1], $zone);
                            \Log::info('DXF Export BG first point (GK Zone '.$zone.')', [
                                'raw' => [$fc[0], $fc[1]],
                                'gk' => [round($bgX), round($bgY)],
                            ]);
                        }
                    }
                    unset($sample);
                    break;
                }
            }
        }

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

        $filename = $project->code.'-ftth.dxf';

        return response()->download($tmpPath, $filename, [
            'Content-Type' => 'application/dxf',
        ])->deleteFileAfterSend(true);
    }

    public function exportFiberSchema(Project $project)
    {
        $project->load([
            'odfs',
            'branches' => fn ($q) => $q->with([
                'route',
                'cabinets' => fn ($q2) => $q2->orderBy('branch_order')->orderBy('name'),
            ])->orderBy('sort_order'),
        ]);

        // ── Dodjela vlakana ───────────────────────────────────────────────────
        $fiberAlloc = [];
        $nextF = 1;
        $project->branches
            ->where('type', 'secondary')
            ->sortBy(fn ($b) => sprintf('%06d|%s', (int) ($b->sort_order ?? 0), (string) $b->name))
            ->each(function ($branch) use (&$fiberAlloc, &$nextF) {
                $branch->cabinets
                    ->sortBy(fn ($c) => sprintf('%06d|%s', (int) ($c->branch_order ?? 0), (string) $c->name))
                    ->each(function ($cabinet) use (&$fiberAlloc, &$nextF) {
                        $n = max(1, (int) ($cabinet->splitter_count ?? 1));
                        $fiberAlloc[$cabinet->id] = ['from' => $nextF, 'to' => $nextF + $n - 1];
                        $nextF += $n;
                    });
            });

        // ── Konstante ─────────────────────────────────────────────────────────
        $OX = 280.0;
        $OY = 200.0;
        $OW = 30.0;
        $OH = 36.0;
        $OG = 115.0;
        $BG = 23.0;
        $CG = 22.0;
        $FP = 0.8;
        $TM = 40.0;
        $CW = 5.8;
        $CH = 9.6;
        $FCD = 47.0;
        $FCD_CAB = 25.0;
        $CHILD_BG = 22.0; // razmak između dijete-grana u child zoni

        // ── DXF header ───────────────────────────────────────────────────────
        $L = [
            '0', 'SECTION', '2', 'HEADER',
            '9', '$ACADVER', '1', 'AC1009',
            '9', '$LTSCALE', '40', '1.0',
            '0', 'ENDSEC',
            '0', 'SECTION', '2', 'TABLES',
            '0', 'TABLE', '2', 'LTYPE', '70', '1',
            '0', 'LTYPE', '2', 'CONTINUOUS', '70', '64', '3', '', '72', '65', '73', '0', '40', '0.0',
            '0', 'ENDTAB',
            '0', 'TABLE', '2', 'LAYER', '70', '6',
            '0', 'LAYER', '2', '0', '70', '64', '62', '7', '6', 'CONTINUOUS',
            '0', 'LAYER', '2', 'FTTH_ODF', '70', '64', '62', '5', '6', 'CONTINUOUS',
            '0', 'LAYER', '2', 'FTTH_PRIMARY', '70', '64', '62', '5', '6', 'CONTINUOUS',
            '0', 'LAYER', '2', 'FTTH_SECONDARY', '70', '64', '62', '6', '6', 'CONTINUOUS',
            '0', 'LAYER', '2', 'FTTH_CABINETS', '70', '64', '62', '7', '6', 'CONTINUOUS',
            '0', 'LAYER', '2', 'FTTH_LABELS', '70', '64', '62', '1', '6', 'CONTINUOUS',
            '0', 'ENDTAB',
            '0', 'TABLE', '2', 'STYLE', '70', '1',
            '0', 'STYLE', '2', 'FTTH', '70', '0', '40', '0.0', '41', '0.8', '50', '0.0', '71', '0', '42', '3.0',
            '3', 'romans.shx', '4', '',
            '0', 'ENDTAB',
            '0', 'ENDSEC',
            '0', 'SECTION', '2', 'ENTITIES',
        ];

        // ODF Y pozicije
        $odfCY = [];
        foreach ($project->odfs as $oi => $odf) {
            $odfCY[$odf->id] = $OY + $oi * $OG;
        }
        $allCY = array_values($odfCY) ?: [$OY];
        $trunkT = max($allCY) + $OH / 2 + $TM;
        $trunkB = min($allCY) - $OH / 2 - $TM;

        // Primarni trup (vertikalne plave linije)
        $primBranches = $project->branches->where('type', 'primary')->sortBy('sort_order')->values();
        $primCount = max($primBranches->count(), 2);
        $primXs = [];
        for ($pi = 0; $pi < $primCount; $pi++) {
            $px = $OX - 1.4 + $pi * 1.8;
            $primXs[] = $px;
            array_push($L, ...$this->dxfLine($px, $trunkB, $px, $trunkT, 'FTTH_PRIMARY', ($pi % 2 === 0) ? 5 : 6));
        }
        foreach ($primBranches as $pi => $pb) {
            $px = $primXs[$pi] ?? ($OX - 1.4 + $pi * 1.8);
            $spec = $pb->route?->fiber_count ? ' '.$pb->route->fiber_count.'F' : '';
            if ($pb->route?->duct_length_m) {
                $spec .= '/'.(int) $pb->route->duct_length_m.'m';
            }
            array_push($L, ...$this->dxfText($px + 2.0, $trunkT + 2.0, $pb->name.$spec, 'FTTH_PRIMARY', 1, 2.2));
        }

        // Pratimo gdje je svaki ormarić nacrtan: [x, tapY, boxTop, boxBot, side]
        $cabPos = [];

        foreach ($project->odfs as $odf) {
            $cx = $OX;
            $cy = $odfCY[$odf->id];

            // ── FAZA 1: Grane koje dolaze direktno iz ODF-a ──────────────────
            // Uključujemo i grane bez odf_id (koje nisu eksplicitno dodijeljene)
            $directBranches = $project->branches
                ->where('type', 'secondary')
                ->filter(fn ($b) => $b->route !== null
                    && $b->route->from_type !== 'cabinet'
                    && ($b->odf_id === $odf->id
                        || ($b->odf_id === null && $b->route->from_type === 'odf')))
                ->sortBy('sort_order')
                ->values();

            $sideCnt = [1 => 0, -1 => 0];
            $sideSlt = [1 => 0, -1 => 0];
            foreach ($directBranches as $i => $_) {
                $sideCnt[($i % 2 === 0) ? 1 : -1]++;
            }

            // Dinamički OH: ODF visina prati raspon grana (max offset ± BG/2 sa marginom)
            $maxSide = max($sideCnt[1], $sideCnt[-1], 1);
            $maxOffset = (($maxSide - 1) / 2.0) * $BG;
            $dynOH = max($OH, $maxOffset * 2.0 + 10.0);
            $odfL = $cx - $OW / 2;
            $odfR = $cx + $OW / 2;

            // ODF kutija (dinamička visina)
            array_push($L, ...$this->dxfRect($odfL, $cy - $dynOH / 2, $odfR, $cy + $dynOH / 2, 'FTTH_ODF', 5));
            array_push($L, ...$this->dxfText($odfL + 2, $cy + 5, $odf->name, 'FTTH_ODF', 7, 2.8));
            array_push($L, ...$this->dxfText($odfL + 2, $cy + 1, 'ODF / PATCH PANEL', 'FTTH_ODF', 5, 1.6));
            array_push($L, ...$this->dxfText($odfL + 2, $cy - 2.5, ($odf->port_count ?? '?').'P / '.($odf->fiber_capacity ?? '?').'F', 'FTTH_ODF', 5, 1.6));
            array_push($L, ...$this->dxfText($odfL + 2, $cy - 5.5, 'LC/APC', 'FTTH_ODF', 4, 1.4));

            $phaseOneMinY = $cy; // prati najmanji boxBot svih direktnih grana

            foreach ($directBranches as $idx => $branch) {
                $side = ($idx % 2 === 0) ? 1 : -1;
                $slot = $sideSlt[$side]++;
                $maxS = max(1, $sideCnt[$side]);
                $bY = $cy - ($slot - ($maxS - 1) / 2.0) * $BG;

                $portX = ($side > 0) ? $odfR : $odfL;
                $edgeX = $portX + $side * 7.0;

                $tw = 2.8;
                $th = 2.4;
                $tl = ($side > 0) ? $portX : $portX - $tw;
                array_push($L, ...$this->dxfRect($tl, $bY - $th / 2, $tl + $tw, $bY + $th / 2, 'FTTH_ODF', 5));
                array_push($L, ...$this->dxfLine($portX, $bY, $edgeX, $bY, 'FTTH_SECONDARY', 6));

                $this->schemaLabel($L, $branch, $edgeX, $bY, $side);
                $this->schemaCabinets($L, $branch->cabinets, $portX, $edgeX, $bY, $side, $FCD, $CG, $CW, $CH, $FP, $fiberAlloc, $cabPos, $phaseOneMinY);
            }

            // ── FAZA 2: Dijete-grane u zasebnoj zoni ispod svih Faze 1 grana ──
            $childBranches = $project->branches
                ->where('type', 'secondary')
                ->filter(fn ($b) => $b->route?->from_type === 'cabinet')
                ->sortBy('sort_order')
                ->values();

            // Child zona počinje 15 jedinica ispod najnižeg boxBot iz Faze 1
            $childBaseY = $phaseOneMinY - 15.0;
            $childIdx = 0;

            foreach ($childBranches as $branch) {
                $srcId = $branch->route->from_id ?? null;
                if (! $srcId || ! isset($cabPos[$srcId])) {
                    continue;
                }

                $src = $cabPos[$srcId];
                $side = $src['side'];
                $srcX = $src['x'];

                // Sekvencijalni Y u child zoni — svaka grana $CHILD_BG ispod prethodne
                $bY = $childBaseY - ($childIdx * $CHILD_BG);
                $edgeX = $srcX + $side * 12.0;
                $childIdx++;

                // L-konektor: vertikalno od dna izvora do bY, horizontalno do edgeX
                array_push($L, ...$this->dxfLine($srcX, $src['boxBot'], $srcX, $bY, 'FTTH_SECONDARY', 6));
                array_push($L, ...$this->dxfLine($srcX, $bY, $edgeX, $bY, 'FTTH_SECONDARY', 6));

                $this->schemaLabel($L, $branch, $edgeX, $bY, $side);

                $dummyMin = PHP_FLOAT_MAX;
                $this->schemaCabinets($L, $branch->cabinets, $srcX, $edgeX, $bY, $side, $FCD_CAB, $CG, $CW, $CH, $FP, $fiberAlloc, $cabPos, $dummyMin);
            }
        }

        array_push($L, '0', 'ENDSEC', '0', 'EOF');

        return response(implode("\r\n", $L)."\r\n", 200, [
            'Content-Type' => 'application/dxf',
            'Content-Disposition' => 'attachment; filename="'.$project->code.'-fiber-schema.dxf"',
        ]);
    }

    // Oznaka grane iznad linije — desno ako side=+1, lijevo-poravnato ako side=-1
    private function schemaLabel(array &$L, NetworkBranch $branch, float $edgeX, float $bY, int $side): void
    {
        $name = $branch->name ?? '';
        $specs = '';
        if ($branch->route) {
            $specs = 'OPTIKA '.($branch->route->fiber_count ?? '?').'F';
            if ($branch->route->duct_length_m) {
                $specs .= ' / '.(int) $branch->route->duct_length_m.'m';
            }
        }

        if ($side > 0) {
            array_push($L, ...$this->dxfText($edgeX + 2, $bY + 3.2, $name, 'FTTH_LABELS', 1, 1.8));
            if ($specs !== '') {
                array_push($L, ...$this->dxfText($edgeX + 2, $bY + 1.2, $specs, 'FTTH_LABELS', 6, 1.3));
            }
        } else {
            array_push($L, ...$this->dxfTextRight($edgeX - 2, $bY + 3.2, $name, 'FTTH_LABELS', 1, 1.8));
            if ($specs !== '') {
                array_push($L, ...$this->dxfTextRight($edgeX - 2, $bY + 1.2, $specs, 'FTTH_LABELS', 6, 1.3));
            }
        }
    }

    // Crta ormarice uz horizontalnu bus-liniju
    private function schemaCabinets(
        array &$L, Collection $cabinets, float $portX, float $edgeX,
        float $bY, int $side, float $fcd, float $cg,
        float $cw, float $ch, float $fp,
        array $fiberAlloc, array &$cabPos, float &$minBoxBot
    ): void {
        $cabs = $cabinets
            ->sortBy(fn ($c) => sprintf('%06d|%s', (int) ($c->branch_order ?? 0), (string) $c->name))
            ->values();
        $nCab = $cabs->count();
        if ($nCab === 0) {
            return;
        }

        $stackH = ($nCab - 1) * $fp;
        $busT = $bY + $stackH / 2 + 1.5;
        $busB = $bY - $stackH / 2 - 2.4;
        array_push($L, ...$this->dxfLine($edgeX, $busB, $edgeX, $busT + 0.9, 'FTTH_SECONDARY', 6));

        foreach ($cabs as $ci => $cabinet) {
            $x = $portX + $side * ($fcd + $ci * $cg);
            $tapY = $bY - ($ci - $nCab / 2.0 + 0.5) * $fp;
            $boxT = $tapY - 3.4;
            $boxB = $boxT - $ch;
            $boxL = $x - $cw / 2;
            $boxR = $x + $cw / 2;

            // Horizontalna linija vlakna + tap krug + oznaka vlakna
            array_push($L, ...$this->dxfLine($edgeX, $tapY, $x, $tapY, 'FTTH_SECONDARY', 6));
            array_push($L, ...$this->dxfCircle($x, $tapY, 0.55, 'FTTH_SECONDARY', 6));

            $fa = $fiberAlloc[$cabinet->id] ?? null;
            $fl = $fa
                ? ($fa['from'] === $fa['to'] ? (string) $fa['from'] : $fa['from'].'-'.$fa['to'])
                : '?';
            array_push($L, ...$this->dxfText($x - 1.8, $tapY + 1.5, $fl, 'FTTH_LABELS', 6, 1.2));

            // Vertikalna kapljica tap → vrh kutije
            array_push($L, ...$this->dxfLine($x, $tapY, $x, $boxT, 'FTTH_SECONDARY', 6));

            // Kutija ormarića + naziv unutar (90°, h=0.9 stane u CH=9.6)
            array_push($L, ...$this->dxfRect($boxL, $boxB, $boxR, $boxT, 'FTTH_CABINETS', 7));
            array_push($L, ...$this->dxfTextRotated($boxL + 1.0, $boxB + 0.5, $cabinet->name, 'FTTH_CABINETS', 7, 0.9, 90.0));

            $cabPos[$cabinet->id] = ['x' => $x, 'tapY' => $tapY, 'boxTop' => $boxT, 'boxBot' => $boxB, 'side' => $side];
            $minBoxBot = min($minBoxBot, $boxB);
        }
    }

    // Desno-poravnat tekst (DXF group 72=2, alignment point 11/21)
    private function dxfTextRight(float $x, float $y, string $text, string $layer, int $color, float $height = 2.0): array
    {
        return [
            '0', 'TEXT', '8', $layer, '62', (string) $color,
            '7', 'FTTH',
            '10', '0', '20', '0', '30', '0',
            '40', (string) $height,
            '72', '2',
            '11', (string) $x, '21', (string) $y, '31', '0',
            '1', $this->dxfSafeText($text),
        ];
    }

    public function printProject(Project $project): View
    {
        $project->load([
            'odfs.cabinets',
            'cabinets.houses',
            'houses.cabinet',
            'routes' => fn ($query) => $query->orderBy('route_type')->orderBy('name'),
            'appendixItems',
        ]);

        return view('ftth.projects.print', [
            'project' => $project,
            'validationItems' => collect($this->ftthIntelligence->validateProject($project)),
            'materials' => $this->ftthIntelligence->materialSummary($project),
        ]);
    }

    private function geoJsonPoint(string $type, int $id, string $name, float $lat, float $lng, array $properties = []): array
    {
        return [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [$lng, $lat],
            ],
            'properties' => array_merge([
                'element_type' => $type,
                'id' => $id,
                'name' => $name,
            ], $properties),
        ];
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

    private function dxfCircle(float $x, float $y, float $radius, string $layer, int $color): array
    {
        return ['0', 'CIRCLE', '8', $layer, '62', (string) $color, '10', (string) $x, '20', (string) $y, '30', '0', '40', (string) $radius];
    }

    private function dxfCircleDashed(float $x, float $y, float $radius, string $layer, int $color, float $ltScale = 4.0): array
    {
        return ['0', 'CIRCLE', '8', $layer, '62', (string) $color, '6', 'DASHED', '48', (string) $ltScale, '10', (string) $x, '20', (string) $y, '30', '0', '40', (string) $radius];
    }

    // Ispunjeni četverokut (SOLID) — koristi se za ispunjene tačke i simbole
    private function dxfSolid(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3, float $x4, float $y4, string $layer, int $color): array
    {
        return [
            '0', 'SOLID', '8', $layer, '62', (string) $color,
            '10', (string) $x1, '20', (string) $y1, '30', '0',
            '11', (string) $x2, '21', (string) $y2, '31', '0',
            '12', (string) $x3, '22', (string) $y3, '32', '0',
            '13', (string) $x4, '23', (string) $y4, '33', '0',
        ];
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

    // Pravokutnik iz 4 linije
    private function dxfRect(float $x1, float $y1, float $x2, float $y2, string $layer, int $color): array
    {
        return [
            ...$this->dxfLine($x1, $y1, $x2, $y1, $layer, $color),
            ...$this->dxfLine($x2, $y1, $x2, $y2, $layer, $color),
            ...$this->dxfLine($x2, $y2, $x1, $y2, $layer, $color),
            ...$this->dxfLine($x1, $y2, $x1, $y1, $layer, $color),
        ];
    }

    // Tekst s kutom rotacije (group 50)
    private function dxfTextRotated(float $x, float $y, string $text, string $layer, int $color, float $height, float $angle): array
    {
        return [
            '0', 'TEXT', '8', $layer, '62', (string) $color,
            '7', 'FTTH',
            '10', (string) $x, '20', (string) $y, '30', '0',
            '40', (string) $height,
            '50', (string) $angle,
            '1', $this->dxfSafeText($text),
        ];
    }

    private function dxfLine(float $x1, float $y1, float $x2, float $y2, string $layer, int $color): array
    {
        return ['0', 'LINE', '8', $layer, '62', (string) $color, '10', (string) $x1, '20', (string) $y1, '30', '0', '11', (string) $x2, '21', (string) $y2, '31', '0'];
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

    private function dxfText(float $x, float $y, string $text, string $layer, int $color, float $height = 3.0): array
    {
        return [
            '0', 'TEXT', '8', $layer, '62', (string) $color,
            '7', 'FTTH',
            '10', (string) $x, '20', (string) $y, '30', '0',
            '40', (string) $height,
            '1', $this->dxfSafeText($text),
        ];
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

    private function dxfSafeText(string $text): string
    {
        // romans.shx ne podrzava bosanska slova — transliteracija
        $map = [
            'č' => 'c', 'Č' => 'C', 'ć' => 'c', 'Ć' => 'C',
            'š' => 's', 'Š' => 'S', 'ž' => 'z', 'Ž' => 'Z',
            'đ' => 'dj', 'Đ' => 'Dj', 'dž' => 'dz', 'Dž' => 'Dz', 'DŽ' => 'DZ',
        ];
        $text = strtr($text, $map);

        return str_replace(["\r", "\n"], ' ', $text);
    }

    public function projectCheck(): View
    {
        $projects = Project::with([
            'odfs',
            'cabinets' => fn ($query) => $query->withCount('houses'),
            'houses',
            'routes',
        ])->withCount(['odfs', 'cabinets', 'houses', 'routes'])->orderBy('name')->get();

        return view('ftth.project-check', ['projects' => $projects]);
    }

    public function settings(): View
    {
        return view('ftth.settings');
    }

    public function backup()
    {
        $dbPath = config('database.connections.sqlite.database');
        if (! file_exists($dbPath)) {
            abort(404, 'Baza podataka nije pronađena.');
        }
        // Flush WAL to main file before download so backup is complete
        DB::statement('PRAGMA wal_checkpoint(FULL)');
        $filename = 'ftth-backup-'.now()->format('Y-m-d-His').'.sqlite';

        return response()->download($dbPath, $filename, [
            'Content-Type' => 'application/octet-stream',
        ]);
    }

    public function storeProject(Request $request)
    {
        if ($request->boolean('quick_create')) {
            $request->merge([
                'code' => $this->nextProjectCode($request->input('name')),
                'location' => $request->input('location') ?: 'Sa mape',
                'status' => 'planning',
            ]);
        }

        $project = Project::create($request->validate([
            'name' => ['required', 'max:255'],
            'code' => ['required', 'max:50', 'unique:projects,code'],
            'location' => ['required', 'max:255'],
            'investor' => ['nullable', 'max:255'],
            'status' => ['required', 'in:planning,active,paused,completed'],
            'start_date' => ['nullable', 'date'],
            'deadline' => ['nullable', 'date'],
            'description' => ['nullable', 'max:2000'],
        ]));

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Projekat je kreiran.',
                'project' => [
                    'id' => $project->id,
                    'name' => $project->name,
                ],
            ]);
        }

        return back()->with('success', 'Projekat je kreiran.');
    }
}
