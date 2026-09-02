<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\SurveyPoint;
use App\Services\ProjectSnapshotService;
use App\Services\SurveyImportMaintenanceService;
use App\Services\SurveyPointImportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class SurveyPointController extends Controller
{
    private const PREVIEW_CACHE_MINUTES = 15;

    public function __construct(
        private readonly SurveyPointImportService $importer,
        private readonly SurveyImportMaintenanceService $maintenance,
        private readonly ProjectSnapshotService $snapshots,
    ) {}

    public function preview(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'points_file' => ['required', 'file', 'max:'.config('uploads.survey_txt_kb'), 'mimes:txt'],
        ]);

        try {
            return response()->json($this->previewPayload(
                $project,
                $data['points_file']->get(),
                $data['points_file']->getClientOriginalName(),
            ));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function previewReport(Request $request, Project $project, string $format): Response
    {
        abort_unless(in_array($format, ['csv', 'pdf'], true), 404);
        $data = $request->validate([
            'points_file' => ['required', 'file', 'max:'.config('uploads.survey_txt_kb'), 'mimes:txt'],
        ]);

        try {
            $preview = $this->previewPayload(
                $project,
                $data['points_file']->get(),
                $data['points_file']->getClientOriginalName(),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $baseName = 'kontrola-geodetskog-uvoza-'.str($project->code ?: $project->name)->slug().'-'.now()->format('Ymd-His');
        if ($format === 'csv') {
            return response($this->validationReportCsv($preview), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$baseName.'.csv"',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return Pdf::loadView('ftth.survey-preview-report', compact('project', 'preview'))
            ->setPaper('a4', 'landscape')
            ->download($baseName.'.pdf');
    }

    public function import(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'points_file' => ['required', 'file', 'max:'.config('uploads.survey_txt_kb'), 'mimes:txt'],
            'overrides' => ['nullable', 'string'],
            'route_corrections' => ['nullable', 'string'],
        ]);

        $cabinetOverrides = [];
        if (! empty($data['overrides'])) {
            $decoded = json_decode($data['overrides'], true);
            if (is_array($decoded)) {
                $cabinetOverrides = array_map('intval', $decoded);
            }
        }
        $routeCorrections = [];
        if (! empty($data['route_corrections'])) {
            $decoded = json_decode($data['route_corrections'], true);
            if (! is_array($decoded)) {
                return response()->json(['message' => 'Ručne korekcije trase nisu ispravan JSON.'], 422);
            }
            $routeCorrections = $decoded;
        }

        try {
            $this->snapshots->create($project, 'Automatski: prije TXT uvoza '.$data['points_file']->getClientOriginalName());
            $created = $this->importer->confirm(
                $project,
                $data['points_file']->get(),
                $data['points_file']->getClientOriginalName(),
                $cabinetOverrides,
                $routeCorrections,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => "Uvezeno: {$created['points']} tacaka, {$created['trenches']} rovova, {$created['cabinets']} ZO, {$created['odfs']} ODF, {$created['manholes']} sahtova, {$created['splices']} spojnica, {$created['borings']} busenja, {$created['loops']} rezervi.",
            'created' => $created,
        ], 201);
    }

    public function destroy(Project $project): JsonResponse
    {
        $this->snapshots->create($project, 'Automatski: prije brisanja svih TXT uvoza');
        $removed = $this->maintenance->clearImportedData($project);

        return response()->json([
            'message' => "Obrisano: {$removed['points']} tacaka, {$removed['trenches']} rovova, {$removed['ducts']} mikrocijevi, {$removed['cabinets']} ZO, {$removed['odfs']} ODF, {$removed['houses']} kuca, {$removed['manholes']} sahtova, {$removed['splices']} spojnica, {$removed['borings']} busenja, {$removed['loops']} rezervi. Rucno nacrtani elementi nisu dirani.",
            'removed' => $removed,
        ]);
    }

    public function imports(Project $project): JsonResponse
    {
        return response()->json(['imports' => $this->maintenance->importedBatches($project)]);
    }

    public function destroyImport(Project $project, string $batch): JsonResponse
    {
        try {
            $this->snapshots->create($project, 'Automatski: prije brisanja TXT uvoza');
            $removed = $this->maintenance->clearImportedBatch($project, $batch);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->json([
            'message' => "Obrisan je samo odabrani TXT uvoz: {$removed['points']} tacaka, {$removed['trenches']} rovova, {$removed['ducts']} mikrocijevi i {$removed['houses']} kuca. Ostali fajlovi nisu dirani.",
            'removed' => $removed,
        ]);
    }

    public function storeFieldPoint(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'session_uuid' => ['required', 'uuid'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'kind' => ['required', Rule::in(['trench', 'cabinet', 'odf', 'manhole', 'splice', 'sling', 'loop', 'boring', 'pole', 'other'])],
            'code' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'captured_at' => ['nullable', 'date'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('uploads.field_photo_kb')],
        ]);

        $sequence = (int) SurveyPoint::where('project_id', $project->id)
            ->where('session_uuid', $data['session_uuid'])
            ->max('sequence') + 1;
        $photoPath = $request->file('photo')?->store("field-photos/{$project->id}", 'local');

        $point = SurveyPoint::create([
            'project_id' => $project->id,
            'import_batch' => 'field-'.$data['session_uuid'],
            'source_file' => null,
            'point_no' => $sequence,
            'x' => 0,
            'y' => 0,
            'z' => null,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'code' => $data['code'],
            'kind' => $data['kind'],
            'source' => 'gps',
            'session_uuid' => $data['session_uuid'],
            'sequence' => $sequence,
            'accuracy_m' => $data['accuracy_m'] ?? null,
            'note' => $data['note'] ?? null,
            'photo_path' => $photoPath,
            'captured_at' => $data['captured_at'] ?? now(),
        ]);

        return response()->json([
            'message' => "GPS tačka #{$sequence} je sačuvana.",
            'point' => [
                'id' => $point->id, 'sequence' => $point->sequence, 'kind' => $point->kind,
                'code' => $point->code, 'lat' => (float) $point->latitude, 'lng' => (float) $point->longitude,
                'accuracy_m' => $point->accuracy_m, 'has_photo' => filled($point->photo_path),
            ],
        ], 201);
    }

    public function fieldPointPhoto(Project $project, SurveyPoint $point)
    {
        abort_unless($point->project_id === $project->id && filled($point->photo_path), 404);

        return Storage::disk('local')->response($point->photo_path);
    }

    /**
     * Cache an immutable preview against both the file hash and the current project
     * network state. Any route/equipment/import change therefore creates a new key.
     */
    private function previewPayload(Project $project, string $contents, string $filename): array
    {
        $startedAt = microtime(true);
        $batch = sha1($contents);
        $duplicateDetectedEarly = SurveyPoint::where('project_id', $project->id)
            ->where('import_batch', $batch)
            ->exists();
        $cacheKey = 'survey-preview:v3:'.hash('sha256', implode('|', [
            $project->id,
            $batch,
            $this->projectNetworkFingerprint($project),
        ]));

        $preview = Cache::get($cacheKey);
        $cacheHit = is_array($preview);
        if (! $cacheHit) {
            $preview = $this->importer->preview($project, $contents, $filename);
            $preview['saved_comparison'] = $this->savedComparison($project, $batch, $preview);
            Cache::put($cacheKey, $preview, now()->addMinutes(self::PREVIEW_CACHE_MINUTES));
        }

        // These values are cheap and authoritative at request time; never trust an old
        // filename or duplicate flag merely because the route calculation was cached.
        $preview['filename'] = $filename;
        $preview['already_imported'] = $duplicateDetectedEarly;
        $elapsedMs = round((microtime(true) - $startedAt) * 1000);
        $executionBudgetSeconds = 30;
        $preview['preview_meta'] = [
            'cache_hit' => $cacheHit,
            'processing_ms' => $elapsedMs,
            'execution_budget_seconds' => $executionBudgetSeconds,
            'budget_used_percent' => min(100, round($elapsedMs / ($executionBudgetSeconds * 10), 1)),
            'duplicate_detected_early' => $duplicateDetectedEarly,
            'file_fingerprint' => substr($batch, 0, 12),
            'cache_ttl_minutes' => self::PREVIEW_CACHE_MINUTES,
        ];

        return $preview;
    }

    private function projectNetworkFingerprint(Project $project): string
    {
        $state = ['project' => [$project->updated_at?->toISOString()]];
        foreach (['survey_points', 'routes', 'cabinets', 'odfs', 'houses'] as $table) {
            $row = DB::table($table)
                ->where('project_id', $project->id)
                ->selectRaw('COUNT(*) as total, MAX(updated_at) as latest')
                ->first();
            $state[$table] = [(int) ($row->total ?? 0), $row->latest ?? null];
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function savedComparison(Project $project, string $batch, array $preview): array
    {
        $routeQuery = NetworkRoute::where('project_id', $project->id)->where('import_batch', $batch);
        $saved = [
            'points' => SurveyPoint::where('project_id', $project->id)->where('import_batch', $batch)->count(),
            'trenches' => (clone $routeQuery)->where('route_type', 'trench')->count(),
            'ducts' => (clone $routeQuery)->where('route_type', '!=', 'trench')->count(),
            'cabinets' => Cabinet::where('project_id', $project->id)->where('import_batch', $batch)->count(),
            'odfs' => Odf::where('project_id', $project->id)->where('import_batch', $batch)->count(),
            'houses' => House::where('project_id', $project->id)->where('import_batch', $batch)->count(),
        ];
        $planned = [
            'points' => (int) ($preview['total_points'] ?? 0),
            'trenches' => count($preview['trench_runs'] ?? []),
            'ducts' => count($preview['ducts'] ?? []),
            'cabinets' => count($preview['cabinets'] ?? []),
            'odfs' => count($preview['odfs'] ?? []),
            'houses' => (int) ($preview['houses'] ?? 0),
        ];

        return [
            'is_saved' => $saved['points'] > 0,
            'saved' => $saved,
            'preview' => $planned,
            'delta' => collect($planned)->map(fn (int $value, string $key) => $value - $saved[$key])->all(),
        ];
    }

    private function validationReportCsv(array $preview): string
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Tip', 'Stavka', 'Oznaka', 'Duzina (m)', 'Status', 'Ciljni ZO', 'Dokaz trase', 'Napomena'], ';');
        fputcsv($stream, ['SAZETAK', 'Fajl', $preview['filename'] ?? '', '', $preview['quality']['status'] ?? '', '', '', ''], ';');
        fputcsv($stream, ['SAZETAK', 'Tacke', $preview['total_points'] ?? 0, '', '', '', '', ''], ';');

        foreach ($preview['trench_runs'] ?? [] as $index => $run) {
            fputcsv($stream, [
                'ROV', $index + 1, $this->safeCsvCell($run['code'] ?? ''),
                $run['length_m'] ?? 0, 'snimljen', '', 'koordinatni graf',
                ($run['points'] ?? 0).' tacaka',
            ], ';');
        }
        foreach ($preview['ducts'] ?? [] as $index => $duct) {
            fputcsv($stream, [
                'MIKROCIJEV', $index + 1, $this->safeCsvCell($duct['label'] ?? ''),
                $duct['length_m'] ?? 0, $duct['routing_status'] ?? '',
                $this->safeCsvCell($duct['target_zo'] ?? $duct['matched_cabinet_name'] ?? ''),
                $this->evidenceLabel($duct['validation_source'] ?? null),
                implode(' | ', $duct['warnings'] ?? []),
            ], ';');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    private function safeCsvCell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@]/', ltrim($value)) ? "'".$value : $value;
    }

    private function evidenceLabel(?string $source): string
    {
        return match ($source) {
            'strict_network_graph' => 'strogi mrezni graf',
            'surveyed_trench_route' => 'snimljeni rov',
            default => $source ? str_replace('_', ' ', $source) : 'nije dokazano',
        };
    }
}
