<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\ProjectAppendixItem;
use App\Models\SurveyPoint;
use App\Support\DuctSurvey\DuctSurveyDescription;
use App\Support\PoleBranchNames;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Writes the interpreted duct survey into a project: ODFs, closures (ZO) on
 * their secondary, pole boxes, the trench as routes, every tube as a route in
 * its own colour, and splices/reserves/manholes/borings as appendix items.
 *
 * The network is always rebuilt from ALL survey files of the project together
 * (a later day continues the earlier one), replacing the previous import.
 */
class DuctSurveyImportService
{
    public function __construct(
        private readonly DuctSurveyInterpreter $interpreter,
        private readonly GeoTransformService $geo,
        private readonly GeometryService $geometry,
        private readonly SurveyImportMaintenanceService $maintenance,
    ) {}

    /** @return list<array{point_no:int,x:float,y:float,z:float,code:string,source_file:string}> */
    public static function readPoints(string $contents, string $sourceFile): array
    {
        $points = [];
        $contents = (string) preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        foreach (preg_split('/\R/u', $contents) ?: [] as $line) {
            if (preg_match('/^\s*(\d+)\s+([\d.]+)\s+([\d.]+)\s+([-\d.]+)\s+(.+?)\s*$/u', $line, $m)) {
                $points[] = [
                    'point_no' => (int) $m[1], 'x' => (float) $m[2], 'y' => (float) $m[3], 'z' => (float) $m[4],
                    'code' => $m[5], 'source_file' => $sourceFile,
                ];
            }
        }

        return $points;
    }

    /**
     * Is this TXT written in the duct-composition format (closures named with
     * their ODF, MD + coloured 14/10 tubes), as opposed to the older format
     * with 10/8 house drops? A project already imported this way stays so,
     * even for a day file that only continues a line.
     */
    public static function looksLikeDuctSurvey(string $contents, ?Project $project = null): bool
    {
        if ($project !== null && self::projectUsesDuctSurvey($project)) {
            return true;
        }
        $row = '/^\s*\d+\s+[\d.]+\s+[\d.]+\s+[-\d.]+\s+"?';
        $closures = preg_match_all($row.'zo\s*\d+(?:\.\d+)*\s*-?\s*(?:s\s*\d+\s*-?\s*)?odf/imu', $contents);
        $mdTrench = preg_match_all($row.'rov\s*\+?\s*md\b/imu', $contents);
        $drops = preg_match('/\b10\s*\/\s*[78]\b/u', $contents);

        return ! $drops && ($closures > 0 || $mdTrench >= 3);
    }

    public static function projectUsesDuctSurvey(Project $project): bool
    {
        return Cabinet::where('project_id', $project->id)->whereNotNull('import_batch')
            ->where(fn ($q) => $q->where('kind', 'box')->orWhere('name', 'like', 'ZO %-S%-ODF %'))
            ->exists();
    }

    /**
     * Points already imported into the project, plus a new day's file (which
     * replaces an earlier upload of the same file name).
     *
     * @return list<array>
     */
    private function projectPointsWith(Project $project, ?string $contents, ?string $filename, ?string $withoutBatch = null): array
    {
        $points = SurveyPoint::where('project_id', $project->id)->where('source', 'txt')->whereNotNull('import_batch')
            ->when($filename !== null, fn ($q) => $q->where('source_file', '!=', $filename))
            ->when($withoutBatch !== null, fn ($q) => $q->where('import_batch', '!=', $withoutBatch))
            ->orderBy('point_no')->get()
            ->map(fn (SurveyPoint $p) => [
                'point_no' => (int) $p->point_no, 'x' => (float) $p->x, 'y' => (float) $p->y, 'z' => (float) $p->z,
                'code' => (string) ($p->original_label ?: $p->code), 'source_file' => (string) $p->source_file,
                'file_batch' => (string) $p->import_batch,
            ])->keyBy('point_no')->all();
        if ($contents !== null && $filename !== null) {
            $fileBatch = sha1($contents);
            foreach (self::readPoints($contents, $filename) as $p) {
                $points[$p['point_no']] = $p + ['file_batch' => $fileBatch];
            }
        }
        ksort($points);

        return array_values($points);
    }

    /**
     * The duct survey rebuilds the whole project from its TXT points, so it must
     * never take over a project that already holds an import in the older format.
     */
    private function guardAgainstMixedFormats(Project $project): void
    {
        $hasOlderImport = ! self::projectUsesDuctSurvey($project)
            && SurveyPoint::where('project_id', $project->id)->where('source', 'txt')->whereNotNull('import_batch')->exists();
        if ($hasOlderImport) {
            throw new InvalidArgumentException('Ovaj projekat već ima TXT uvoz u starijem formatu (cijevi 10/8 do kuća). Snimak sastava rova (MD + 14/10, ZO-S-ODF) uvezi u novi projekat, ili prvo obriši stari uvoz.');
        }
    }

    /** What the project's network becomes with this file added — nothing is written. */
    public function preview(Project $project, string $contents, string $filename): array
    {
        $this->guardAgainstMixedFormats($project);
        $newPoints = self::readPoints($contents, $filename);
        if ($newPoints === []) {
            throw new InvalidArgumentException('Fajl ne sadrži nijednu tačku (broj X Y Z opis).');
        }
        $points = $this->projectPointsWith($project, $contents, $filename);
        $result = $this->interpreter->interpret($points);
        $nodes = $result['nodes'];

        $secondaries = [];
        $numbers = $this->secondaryNumbers($result['secondaries']);
        foreach ($result['secondaries'] as $key => $list) {
            [$odf] = explode('|', $key);
            $secondaries[] = [
                'name' => PoleBranchNames::PREFIX.' '.PoleBranchNames::roman((int) $odf).'-'.$numbers[$key]['number'],
                'assigned' => $numbers[$key]['assigned'],
                'closures' => array_map(fn (array $zo) => [
                    'label' => 'ZO '.$zo['label'],
                    'point_no' => $nodes[$zo['node']]['points'][0],
                    'reached' => $zo['reached'],
                    'distance_m' => $zo['distance_m'],
                    'parent' => $zo['via_zo'] === [] ? null : 'ZO '.$nodes[end($zo['via_zo'])]['zo']['label'],
                ], $list),
            ];
        }
        $tubes = [];
        foreach ($result['flows'] as $flow) {
            $key = $flow['color'];
            $tubes[$key] = ($tubes[$key] ?? 0) + $flow['length_m'];
        }
        arsort($tubes);
        $files = array_values(array_unique(array_column($points, 'source_file')));

        return [
            'format' => 'duct_survey',
            'filename' => $filename,
            'already_imported' => SurveyPoint::where('project_id', $project->id)->where('import_batch', sha1($contents))->exists(),
            'new_points' => count($newPoints),
            'total_points' => count($points),
            'files' => $files,
            'summary' => [
                'odfs' => count(array_filter($nodes, fn ($n) => $n['kind'] === 'odf')),
                'closures' => count(array_filter($nodes, fn ($n) => $n['kind'] === 'zo')),
                'boxes' => count(array_filter($nodes, fn ($n) => $n['kind'] === 'box')),
                'splices' => count(array_filter($nodes, fn ($n) => $n['kind'] === 'splice')),
                'trench_m' => round(array_sum(array_map(
                    fn ($e) => in_array($e['kind'], ['trench', 'link', 'join', 'crossing'], true) ? hypot($nodes[$e['a']]['x'] - $nodes[$e['b']]['x'], $nodes[$e['a']]['y'] - $nodes[$e['b']]['y']) : 0,
                    $result['edges'],
                ))),
                'tubes' => array_map(fn ($color, $length) => ['tube' => self::tubeLabel($color), 'color' => $color, 'length_m' => round($length)], array_keys($tubes), $tubes),
            ],
            'secondaries' => $secondaries,
            'checks' => $result['checks'],
            'geometry' => $this->previewGeometry($result),
        ];
    }

    /** Add (or replace) one day's file and rebuild the project's network from all files. */
    public function importFile(Project $project, string $contents, string $filename): array
    {
        $this->guardAgainstMixedFormats($project);
        $points = $this->projectPointsWith($project, $contents, $filename);
        if ($points === []) {
            throw new InvalidArgumentException('Fajl ne sadrži nijednu tačku (broj X Y Z opis).');
        }

        return $this->importPoints($project, $points);
    }

    /** Remove one day's file and rebuild the network from the remaining files. */
    public function removeFile(Project $project, string $fileBatch): array
    {
        $points = $this->projectPointsWith($project, null, null, $fileBatch);
        if ($points === []) {
            return ['result' => null, 'created' => [], 'removed' => $this->maintenance->clearImportedData($project)];
        }

        return $this->importPoints($project, $points);
    }

    /**
     * @param  array<string,string>  $files  file name => contents, in survey order
     * @return array{result:array, created:array<string,int>, removed:array<string,int>}
     */
    public function import(Project $project, array $files): array
    {
        $points = [];
        foreach ($files as $name => $contents) {
            $fileBatch = sha1($contents);
            $points = [...$points, ...array_map(fn ($p) => $p + ['file_batch' => $fileBatch], self::readPoints($contents, $name))];
        }
        if ($points === []) {
            throw new InvalidArgumentException('Fajlovi ne sadrže nijednu tačku (broj X Y Z opis).');
        }

        return $this->importPoints($project, $points);
    }

    /** @return array{result:array, created:array<string,int>, removed:array<string,int>} */
    private function importPoints(Project $project, array $points): array
    {
        $result = $this->interpreter->interpret($points);
        $batch = sha1(implode("\n", array_unique(array_column($points, 'file_batch'))));
        $removed = [];
        $created = [];

        DB::transaction(function () use ($project, $points, $result, $batch, &$removed, &$created): void {
            // Secondaries made by the previous import have no route of their own:
            // they go with the imported closures that sit on them.
            $importedBranchIds = Cabinet::where('project_id', $project->id)->whereNotNull('import_batch')
                ->whereNotNull('branch_id')->pluck('branch_id')->unique();
            $removedBranches = NetworkBranch::where('project_id', $project->id)->whereNull('route_id')
                ->whereIn('id', $importedBranchIds)->delete();
            $removed = $this->maintenance->clearImportedData($project) + ['branches' => $removedBranches];
            $created = $this->persist($project, $points, $result, $batch);
        });

        return ['result' => $result, 'created' => $created, 'removed' => $removed];
    }

    private function persist(Project $project, array $points, array $result, string $batch): array
    {
        $nodes = $result['nodes'];
        $created = ['odfs' => 0, 'branches' => 0, 'cabinets' => 0, 'boxes' => 0, 'trenches' => 0, 'ducts' => 0, 'points' => 0, 'appendix' => 0];

        // ODF -----------------------------------------------------------
        $odfIds = [];
        foreach ($nodes as $n) {
            if ($n['kind'] !== 'odf') {
                continue;
            }
            [$lat, $lng] = $this->latLng($n);
            $odfIds[$n['id']] = Odf::create([
                'project_id' => $project->id, 'name' => 'ODF-'.$n['odf_no'],
                'address' => 'Geodetski snimak', 'fiber_capacity' => 144, 'port_count' => 48,
                'latitude' => $lat, 'longitude' => $lng, 'import_batch' => $batch,
                'notes' => 'Geodetski snimak, tačke '.implode(', ', $n['points']),
            ])->id;
            $created['odfs']++;
        }
        $odfByNumber = [];
        foreach ($odfIds as $nodeId => $odfId) {
            $odfByNumber[$nodes[$nodeId]['odf_no']] = $odfId;
        }

        // Secondaries (kraci) and their ZOs ------------------------------
        $cabinetIds = [];
        $sortOrder = 0;
        foreach ($this->secondaryNumbers($result['secondaries']) as $key => $secondary) {
            [$odfNo] = explode('|', $key);
            $odfId = $odfByNumber[(int) $odfNo] ?? null;
            $roman = PoleBranchNames::roman((int) $odfNo);
            $branch = NetworkBranch::create([
                'project_id' => $project->id, 'odf_id' => $odfId,
                'name' => PoleBranchNames::PREFIX.' '.$roman.'-'.$secondary['number'],
                'code' => 'S'.$secondary['number'], 'type' => 'secondary', 'sort_order' => ++$sortOrder,
            ]);
            $created['branches']++;
            foreach ($result['secondaries'][$key] as $order => $zo) {
                $n = $nodes[$zo['node']];
                [$lat, $lng] = $this->latLng($n);
                $parentNode = $zo['via_zo'] === [] ? null : end($zo['via_zo']);
                $cabinetIds[$n['id']] = Cabinet::create([
                    'project_id' => $project->id, 'odf_id' => $odfId, 'branch_id' => $branch->id, 'branch_order' => $order + 1,
                    'parent_cabinet_id' => $parentNode !== null ? ($cabinetIds[$parentNode] ?? null) : null,
                    'name' => 'ZO '.$n['zo']['label'].'-S'.$secondary['number'].'-ODF '.$roman,
                    'address' => $secondary['assigned'] ? 'Geodetski snimak – broj sekundara nije snimljen, dodijeljen automatski' : 'Geodetski snimak',
                    'latitude' => $lat, 'longitude' => $lng, 'import_batch' => $batch,
                ])->id;
                $created['cabinets']++;
            }
        }

        // Pole boxes: a pole with one 1x4 splitter, fed by the tube reaching it.
        $boxNumbers = [];
        $poleNo = (int) SurveyPoint::where('project_id', $project->id)->max('pole_no');
        $boxPoles = [];
        $boxCabinetByPoint = [];
        foreach ($result['flows'] as $flow) {
            $root = $nodes[$flow['root']];
            $odfNo = $root['kind'] === 'odf' ? $root['odf_no'] : ($root['zo']['odf'] ?? null);
            $lastZo = $root['kind'] === 'zo' ? $root['id'] : null;
            foreach ($flow['objects'] as $object) {
                $n = $nodes[$object['node']];
                if ($n['kind'] === 'zo') {
                    $lastZo = $n['id'];

                    continue;
                }
                if (isset($cabinetIds[$n['id']])) {
                    continue;
                }
                $boxNumbers[$odfNo] = ($boxNumbers[$odfNo] ?? 0) + 1;
                [$lat, $lng] = $this->latLng($n);
                $cabinetIds[$n['id']] = Cabinet::create([
                    'project_id' => $project->id, 'odf_id' => $odfNo !== null ? ($odfByNumber[$odfNo] ?? null) : null,
                    'parent_cabinet_id' => $lastZo !== null ? ($cabinetIds[$lastZo] ?? null) : null,
                    'name' => 'BOX '.$boxNumbers[$odfNo].($odfNo !== null ? '-ODF '.PoleBranchNames::roman($odfNo) : ''),
                    'kind' => 'box',
                    'address' => 'Box na stubu (tačka '.$n['points'][0].')',
                    'splitter_count' => 1, 'ports_per_splitter' => 4,
                    'latitude' => $lat, 'longitude' => $lng, 'import_batch' => $batch,
                ])->id;
                $boxPoles[$n['points'][0]] = ++$poleNo;
                $boxCabinetByPoint[$n['points'][0]] = $cabinetIds[$n['id']];
                $created['boxes']++;
            }
        }

        // Trench --------------------------------------------------------
        [$edges, $alias] = $this->foldMarkers($result['edges'], $nodes);
        $trenchEdges = array_keys(array_filter($edges, fn ($e) => in_array($e['kind'], ['trench', 'link', 'join', 'crossing'], true)));
        $group = 'Geodetski rov '.substr($batch, 0, 8);
        foreach ($this->chains($edges, $trenchEdges, fn (array $e) => $this->ductLabel($e).'|'.$e['alkaten']) as $i => $chain) {
            $edge = $edges[$chain['edges'][0]];
            $path = $this->cleanPath(array_map(fn (int $id) => $this->latLng($nodes[$id]), $chain['nodes']));
            $length = $this->pathLength($path);
            if ($length < 0.5) {
                continue;
            }
            NetworkRoute::create([
                'project_id' => $project->id, 'name' => 'Dionica rova '.($created['trenches'] + 1),
                'route_type' => 'trench', 'installation_type' => 'underground', 'counts_as_trench' => true,
                'trench_group' => $group, 'duct_length_m' => round($length, 2),
                'fiber_length_m' => 0, 'fiber_count' => 0, 'microduct_count' => 0, 'status' => 'planned', 'path' => $path,
                'note' => 'Geodetski snimak (tačke '.$this->pointRange($chain['nodes'], $nodes).'): '.$this->ductLabel($edge)
                    .($edge['alkaten'] ? ' | kroz Alkaten DN 50 x'.$edge['alkaten'] : ''),
                'import_batch' => $batch,
            ]);
            $created['trenches']++;
        }

        // Tubes, one route per stretch between branchings, in their colour -----
        foreach ($result['flows'] as $flow) {
            $root = $nodes[$flow['root']];
            $odfNo = $root['kind'] === 'odf' ? $root['odf_no'] : ($root['zo']['odf'] ?? null);
            $runEdges = $this->flowEdges($edges, $alias, $flow);
            foreach ($this->chains($edges, $runEdges, fn () => '') as $chain) {
                $path = $this->cleanPath(array_map(fn (int $id) => $this->latLng($nodes[$id]), $chain['nodes']));
                $length = $this->pathLength($path);
                // A closure standing half a metre beside the trench is not a tube of its own.
                if ($length < 1.0) {
                    continue;
                }
                $endCabinet = null;
                foreach ([end($chain['nodes']), $chain['nodes'][0]] as $end) {
                    $endCabinet ??= $cabinetIds[$end] ?? null;
                }
                $count = max(1, ...array_map(fn (int $i) => is_array($edges[$i]['ducts']) ? ($edges[$i]['ducts'][$flow['color']] ?? 1) : 1, $chain['edges']));
                $isMd = $flow['color'] === 'md';
                NetworkRoute::create([
                    'project_id' => $project->id,
                    'odf_id' => $odfNo !== null ? ($odfByNumber[$odfNo] ?? null) : null,
                    'cabinet_id' => $endCabinet,
                    'from_type' => $root['kind'] === 'odf' ? 'odf' : 'cabinet',
                    'from_id' => $root['kind'] === 'odf' ? ($odfIds[$root['id']] ?? null) : ($cabinetIds[$root['id']] ?? null),
                    'to_type' => $endCabinet !== null ? 'cabinet' : null, 'to_id' => $endCabinet,
                    'name' => $isMd ? 'MC MD 4x12/10' : 'MC 14/10 '.ucfirst($flow['color']),
                    'route_type' => 'distribution', 'installation_type' => 'underground', 'counts_as_trench' => false,
                    'duct_length_m' => round($length, 2), 'fiber_length_m' => 0, 'fiber_count' => 0,
                    'microduct_count' => $count, 'microduct_type' => $isMd ? 'MD 4x12/10' : '14/10',
                    'status' => 'planned', 'path' => $path,
                    'note' => 'Geodetski snimak: '.($isMd ? 'mikrodukt MD' : 'mikrocijev 14/10 '.$flow['color'])
                        .' od '.$this->nodeName($root).', tačke '.$this->pointRange($chain['nodes'], $nodes),
                    'import_batch' => $batch,
                ]);
                $created['ducts']++;
            }
        }

        // Raw survey points ---------------------------------------------
        $now = now();
        $rows = [];
        foreach ($points as $p) {
            $parsed = DuctSurveyDescription::parse($p['code']);
            [$lat, $lng] = $this->latLng($p);
            $isBox = $parsed['type'] === 'box';
            $rows[] = [
                // Points keep the batch of their own file, so one day's file can be listed and removed alone.
                'project_id' => $project->id, 'import_batch' => $p['file_batch'] ?? $batch, 'source_file' => $p['source_file'],
                'point_no' => $p['point_no'], 'x' => $p['x'], 'y' => $p['y'], 'z' => $p['z'],
                'latitude' => $lat, 'longitude' => $lng, 'code' => $p['code'], 'original_label' => $p['code'],
                'kind' => $isBox ? 'survey_object' : match ($parsed['type']) {
                    'line' => 'trench', 'zo' => 'cabinet', 'odf' => 'odf', 'manhole' => 'manhole', 'splice' => 'splice',
                    'izvod', 'slinga' => 'loop', 'boring' => 'boring', default => 'other',
                },
                'survey_category' => $isBox ? 'fiber_pole' : null,
                'pole_no' => $isBox ? ($boxPoles[$p['point_no']] ?? null) : null,
                'source_crs' => 'EPSG:3127'.$this->geo->detectZone($p['x']),
                'source' => 'txt', 'imported_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            SurveyPoint::insert($chunk);
        }
        $created['points'] = count($rows);
        // Each box remembers the pole it hangs on: drawn together as one symbol.
        $poleIds = SurveyPoint::where('project_id', $project->id)->where('source', 'txt')->where('survey_category', 'fiber_pole')
            ->whereIn('point_no', array_keys($boxCabinetByPoint))->pluck('id', 'point_no');
        foreach ($boxCabinetByPoint as $pointNo => $cabinetId) {
            Cabinet::whereKey($cabinetId)->update(['survey_point_id' => $poleIds[$pointNo] ?? null]);
        }

        // Appendix: splices, reserves, manholes, borings -------------------
        // Splices are numbered in survey order and named after the tube they join:
        // "SP 5 · 14/10 plava · S3" (title before " | ", survey detail after).
        $spliceNo = 0;
        $byPoint = $nodes;
        usort($byPoint, fn (array $a, array $b) => $a['points'][0] <=> $b['points'][0]);
        foreach ($byPoint as $n) {
            $type = match ($n['kind']) {
                'splice' => 'splice', 'slinga' => 'loop', 'manhole' => 'manhole', default => null,
            };
            if ($type === null) {
                continue;
            }
            [$lat, $lng] = $this->latLng($n);
            $note = 'Tačka '.$n['points'][0].': '.$n['code'];
            if ($type === 'splice') {
                $note = $this->spliceTitle(++$spliceNo, $n['code']).' | '.$note;
            }
            ProjectAppendixItem::create([
                'project_id' => $project->id, 'type' => $type, 'quantity' => 1, 'unit' => 'KOMADA',
                'latitude' => $lat, 'longitude' => $lng, 'note' => $note, 'import_batch' => $batch,
            ]);
            $created['appendix']++;
        }
        foreach ($result['edges'] as $e) {
            if ($e['kind'] !== 'crossing') {
                continue;
            }
            $a = $nodes[$e['a']];
            $b = $nodes[$e['b']];
            $length = hypot($a['x'] - $b['x'], $a['y'] - $b['y']);
            if ($length < 1.0) {
                continue;
            }
            [$lat, $lng] = $this->latLng(['x' => ($a['x'] + $b['x']) / 2, 'y' => ($a['y'] + $b['y']) / 2]);
            ProjectAppendixItem::create([
                'project_id' => $project->id, 'type' => 'boring_fi_130', 'quantity' => round($length, 1), 'unit' => 'metara',
                'length_m' => round($length, 1), 'latitude' => $lat, 'longitude' => $lng,
                'note' => 'Bušenje ispod puta, tačke '.$a['points'][0].'–'.$b['points'][0], 'import_batch' => $batch,
            ]);
            $created['appendix']++;
        }

        return $created;
    }

    /**
     * Lines and objects for the import preview on the map, in WGS84: the
     * trench, every tube in its colour, and ODF / ZO / box / splice markers.
     *
     * @return array{lines: list<array>, points: list<array>}
     */
    private function previewGeometry(array $result): array
    {
        $nodes = $result['nodes'];
        [$edges, $alias] = $this->foldMarkers($result['edges'], $nodes);
        $path = fn (array $chain) => array_map(fn (int $id) => $this->latLng($nodes[$id]), $chain['nodes']);
        $lines = [];
        $trenchEdges = array_keys(array_filter($edges, fn ($e) => in_array($e['kind'], ['trench', 'link', 'join', 'crossing'], true)));
        foreach ($this->chains($edges, $trenchEdges, fn () => '') as $chain) {
            $lines[] = ['kind' => 'trench', 'color' => null, 'path' => $path($chain)];
        }
        foreach ($result['flows'] as $flow) {
            foreach ($this->chains($edges, $this->flowEdges($edges, $alias, $flow), fn () => '') as $chain) {
                $lines[] = ['kind' => 'tube', 'color' => $flow['color'], 'path' => $path($chain)];
            }
        }

        $points = [];
        $spliceNo = 0;
        $byPoint = $nodes;
        usort($byPoint, fn (array $a, array $b) => $a['points'][0] <=> $b['points'][0]);
        foreach ($byPoint as $n) {
            $label = match ($n['kind']) {
                'odf' => 'ODF '.$n['odf_no'],
                'zo' => 'ZO '.$n['zo']['label'].($n['zo']['secondary'] !== null ? '-S'.$n['zo']['secondary'] : ''),
                'box' => 'BOX',
                'splice' => self::spliceTitle(++$spliceNo, $n['code']),
                'izvod' => 'Izvod',
                'slinga' => 'Slinga',
                default => null,
            };
            if ($label !== null) {
                [$lat, $lng] = $this->latLng($n);
                $points[] = ['kind' => $n['kind'], 'label' => $label, 'point_no' => $n['points'][0], 'code' => $n['code'], 'lat' => $lat, 'lng' => $lng];
            }
        }

        return ['lines' => $lines, 'points' => $points];
    }

    /**
     * Secondary numbers per ODF: as surveyed ("S2"), otherwise the lowest
     * number not used by that ODF ("stavi ga na kraku na kojem se nalazi").
     *
     * @return array<string,array{number:int,assigned:bool}>
     */
    private function secondaryNumbers(array $secondaries): array
    {
        $used = [];
        foreach (array_keys($secondaries) as $key) {
            [$odf, $s] = explode('|', $key);
            if ($s !== '-') {
                $used[$odf][(int) $s] = true;
            }
        }
        $numbers = [];
        foreach (array_keys($secondaries) as $key) {
            [$odf, $s] = explode('|', $key);
            if ($s !== '-') {
                $numbers[$key] = ['number' => (int) $s, 'assigned' => false];

                continue;
            }
            $free = 1;
            while (isset($used[$odf][$free])) {
                $free++;
            }
            $used[$odf][$free] = true;
            $numbers[$key] = ['number' => $free, 'assigned' => true];
        }

        return $numbers;
    }

    /**
     * Markers sitting on the line (splice, izvod, slinga, šaht, note) are a
     * point ON the line, not a fork: merge each into its line point so tubes
     * and trench run through it in one piece.
     *
     * @return array{0: array<int,array>, 1: array<int,int>} edges, marker => line node
     */
    private function foldMarkers(array $edges, array $nodes): array
    {
        $markers = ['splice', 'izvod', 'slinga', 'manhole', 'note', 'boring'];
        $alias = [];
        foreach ($edges as $e) {
            if ($e['kind'] !== 'attach') {
                continue;
            }
            foreach ([[$e['a'], $e['b']], [$e['b'], $e['a']]] as [$marker, $line]) {
                if (in_array($nodes[$marker]['kind'], $markers, true) && in_array($nodes[$line]['kind'], ['line', 'spur'], true)) {
                    $alias[$marker] = $line;
                }
            }
        }
        $folded = [];
        $pairs = [];
        foreach ($edges as $i => $e) {
            $a = $alias[$e['a']] ?? $e['a'];
            $b = $alias[$e['b']] ?? $e['b'];
            if ($a === $b) {
                continue;
            }
            $pair = min($a, $b).'-'.max($a, $b);
            if (isset($pairs[$pair])) {
                $kept = &$folded[$pairs[$pair]];
                if (is_array($kept['ducts']) && is_array($e['ducts'])) {
                    foreach ($e['ducts'] as $color => $count) {
                        $kept['ducts'][$color] = max($kept['ducts'][$color] ?? 0, $count);
                    }
                }
                unset($kept);

                continue;
            }
            $pairs[$pair] = $i;
            $folded[$i] = ['a' => $a, 'b' => $b] + $e;
        }

        return [$folded, $alias];
    }

    /** Edge indexes carrying the flow's colour inside the flow's run. */
    private function flowEdges(array $edges, array $alias, array $flow): array
    {
        $inside = array_fill_keys(array_map(fn (int $id) => $alias[$id] ?? $id, $flow['nodes']), true);
        $indexes = [];
        foreach ($edges as $i => $e) {
            if (! isset($inside[$e['a']], $inside[$e['b']])) {
                continue;
            }
            if (is_array($e['ducts']) ? isset($e['ducts'][$flow['color']]) : $flow['color'] !== 'md') {
                $indexes[] = $i;
            }
        }

        return $indexes;
    }

    /**
     * Split a set of edges into polylines that run between branch points
     * (and wherever the key changes, e.g. the tube set in the trench).
     *
     * @return list<array{nodes:list<int>,edges:list<int>}>
     */
    private function chains(array $edges, array $indexes, callable $key): array
    {
        $adj = [];
        foreach ($indexes as $i) {
            $adj[$edges[$i]['a']][] = $i;
            $adj[$edges[$i]['b']][] = $i;
        }
        $isBreak = function (int $node) use ($adj, $edges, $key): bool {
            $incident = $adj[$node] ?? [];

            return count($incident) !== 2 || $key($edges[$incident[0]]) !== $key($edges[$incident[1]]);
        };
        $used = [];
        $chains = [];
        $walk = function (int $start, int $edge) use (&$used, $adj, $edges, $isBreak): array {
            $nodes = [$start];
            $chainEdges = [];
            $current = $start;
            while (true) {
                $used[$edge] = true;
                $chainEdges[] = $edge;
                $current = $edges[$edge]['a'] === $current ? $edges[$edge]['b'] : $edges[$edge]['a'];
                $nodes[] = $current;
                if ($isBreak($current)) {
                    break;
                }
                $next = array_values(array_filter($adj[$current], fn (int $i) => ! isset($used[$i])));
                if ($next === []) {
                    break;
                }
                $edge = $next[0];
            }

            return ['nodes' => $nodes, 'edges' => $chainEdges];
        };
        foreach (array_keys($adj) as $node) {
            if (! $isBreak($node)) {
                continue;
            }
            foreach ($adj[$node] as $edge) {
                if (! isset($used[$edge])) {
                    $chains[] = $walk($node, $edge);
                }
            }
        }
        foreach ($indexes as $edge) { // closed loops without a break point
            if (! isset($used[$edge])) {
                $chains[] = $walk($edges[$edge]['a'], $edge);
            }
        }

        return $chains;
    }

    private function ductLabel(array $edge): string
    {
        if (! is_array($edge['ducts']) || $edge['ducts'] === []) {
            return 'rov';
        }
        $parts = [];
        foreach ($edge['ducts'] as $color => $count) {
            $parts[] = ($color === 'md' ? 'MD' : '14/10 '.$color).($count > 1 ? ' x'.$count : '');
        }

        return implode(' + ', $parts);
    }

    /** "MD" or "14/10 žuta" — the tube as people write it. */
    public static function tubeLabel(string $tube): string
    {
        return DuctSurveyDescription::tubeLabel($tube);
    }

    /** "SP 5 · 14/10 plava · S3" from "Spojnica 14/10 plave-S3"; "SP 2 · MD" from "Spojnica MD". */
    public static function spliceTitle(int $number, string $code): string
    {
        $parsed = DuctSurveyDescription::parse($code);
        $tubes = array_map(fn (string $tube) => self::tubeLabel($tube), array_keys($parsed['ducts']));

        return implode(' · ', array_filter([
            'SP '.$number,
            $tubes === [] ? null : implode(' + ', $tubes),
            $parsed['secondary'] !== null ? 'S'.$parsed['secondary'] : null,
        ]));
    }

    private function nodeName(array $n): string
    {
        return match ($n['kind']) {
            'odf' => 'ODF-'.$n['odf_no'],
            'zo' => 'ZO '.$n['zo']['label'],
            default => 'tačke '.$n['points'][0],
        };
    }

    private function pointRange(array $nodeIds, array $nodes): string
    {
        $numbers = array_merge(...array_map(fn (int $id) => $nodes[$id]['points'], $nodeIds));

        return min($numbers).'–'.max($numbers);
    }

    /** Drop points closer than 5 cm to the previous one (a point recorded twice); the end stays exact. */
    private function cleanPath(array $path): array
    {
        $clean = [];
        foreach ($path as $index => $point) {
            if ($clean !== [] && $this->geometry->distanceBetweenPoints(end($clean), $point) < 0.05) {
                if ($index === count($path) - 1 && count($clean) > 1) {
                    $clean[count($clean) - 1] = $point;
                }

                continue;
            }
            $clean[] = $point;
        }

        return $clean;
    }

    /** Length in metres, not rounded to whole metres like GeometryService::polylineLength(). */
    private function pathLength(array $path): float
    {
        $length = 0.0;
        for ($i = 1; $i < count($path); $i++) {
            $length += $this->geometry->distanceBetweenPoints($path[$i - 1], $path[$i]);
        }

        return $length;
    }

    /** @return array{0:float,1:float} */
    private function latLng(array $p): array
    {
        [$lat, $lng] = $this->geo->gaussKrugerToWgs84((float) $p['x'], (float) $p['y'], $this->geo->detectZone((float) $p['x']));

        return [round($lat, 7), round($lng, 7)];
    }
}
