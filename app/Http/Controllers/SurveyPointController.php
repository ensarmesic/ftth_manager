<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\SurveyPoint;
use App\Services\ProjectSnapshotService;
use App\Services\SurveyImportMaintenanceService;
use App\Services\SurveyPointImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class SurveyPointController extends Controller
{
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
            return response()->json($this->importer->preview(
                $project,
                $data['points_file']->get(),
                $data['points_file']->getClientOriginalName(),
            ));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
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
}
