<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectBackgroundTask;
use App\Services\LargePlanner\FinalValidationService;
use App\Services\LargePlanner\PlanConfirmationService;
use App\Services\LargePlanner\PreviewEditorService;
use App\Services\ProjectSnapshotService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LargePlannerPreviewController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        abort_unless($project->planning_mode === 'large_auto', 404);

        return response()->json(['tasks' => $project->backgroundTasks()->where('type', 'large_plan')->latest()->limit(20)->get()]);
    }

    public function show(Project $project, ProjectBackgroundTask $task): JsonResponse
    {
        return response()->json(['task' => $task, 'preview' => $this->read($project, $task)]);
    }

    public function move(Request $request, Project $project, ProjectBackgroundTask $task, PreviewEditorService $editor): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['odf', 'odo'])],
            'key' => ['required', 'string', 'max:80'],
            'point' => ['required', 'array', 'size:2'],
            'point.0' => ['required', 'numeric', 'between:-90,90'],
            'point.1' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return $this->edit($project, $task, fn (array $preview) => $editor->move($project, $preview, $data['type'], $data['key'], $data['point']));
    }

    public function assignHouse(Request $request, Project $project, ProjectBackgroundTask $task, PreviewEditorService $editor): JsonResponse
    {
        $data = $request->validate(['house_id' => ['required', 'integer'], 'odo_key' => ['required', 'string', 'max:80']]);

        return $this->edit($project, $task, fn (array $preview) => $editor->assignHouse($project, $preview, $data['house_id'], $data['odo_key']));
    }

    public function assignHouses(Request $request, Project $project, ProjectBackgroundTask $task, PreviewEditorService $editor): JsonResponse
    {
        $data = $request->validate([
            'house_ids' => ['required', 'array', 'min:1', 'max:500'],
            'house_ids.*' => ['required', 'integer', 'distinct'],
            'odo_key' => ['required', 'string', 'max:80'],
        ]);

        return $this->edit($project, $task, function (array $preview) use ($editor, $project, $data): array {
            foreach ($data['house_ids'] as $houseId) {
                $preview = $editor->assignHouse($project, $preview, $houseId, $data['odo_key']);
            }

            return $preview;
        });
    }

    public function lock(Request $request, Project $project, ProjectBackgroundTask $task, PreviewEditorService $editor): JsonResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(['odf', 'odo'])], 'key' => ['required', 'string', 'max:80'], 'locked' => ['required', 'boolean']]);

        return $this->edit($project, $task, fn (array $preview) => $editor->lock($preview, $data['type'], $data['key'], $data['locked']));
    }

    public function compare(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate(['first_task_id' => ['required', 'integer'], 'second_task_id' => ['required', 'integer', 'different:first_task_id']]);
        $first = $project->backgroundTasks()->findOrFail($data['first_task_id']);
        $second = $project->backgroundTasks()->findOrFail($data['second_task_id']);

        return response()->json(['first' => $this->metrics($this->read($project, $first)), 'second' => $this->metrics($this->read($project, $second))]);
    }

    public function reset(Project $project, ProjectBackgroundTask $task): JsonResponse
    {
        $this->read($project, $task);
        abort_if($task->confirmed_at !== null, 409, 'Potvrđeni plan nije moguće mijenjati.');
        $originalPath = $this->originalPath($task);
        abort_unless(Storage::exists($originalPath), 409, 'Nema ručnih korekcija za poništavanje.');

        Storage::put($task->result_path, Storage::get($originalPath));
        Storage::delete($originalPath);

        return response()->json(['preview' => $this->read($project, $task), 'message' => 'Ručne korekcije su poništene.']);
    }

    public function validateFinal(Project $project, ProjectBackgroundTask $task, FinalValidationService $validator): JsonResponse
    {
        $result = $validator->validate($project, $this->read($project, $task));

        return response()->json($result, $result['valid'] ? 200 : 422);
    }

    public function confirm(Request $request, Project $project, ProjectBackgroundTask $task, PlanConfirmationService $confirmation): JsonResponse
    {
        $data = $request->validate(['acknowledge_warnings' => ['nullable', 'boolean']]);
        try {
            $summary = $confirmation->confirm($project, $task, $this->read($project, $task), $request->user(), $request->ip(), (bool) ($data['acknowledge_warnings'] ?? false));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['summary' => $summary, 'task' => $task->fresh(), 'message' => 'Plan je potvrđen i upisan u postojeću mrežu.']);
    }

    public function reopen(Project $project, ProjectBackgroundTask $task, ProjectSnapshotService $snapshots): JsonResponse
    {
        $this->read($project, $task);
        abort_unless($task->confirmed_at !== null && $task->snapshot_id !== null, 409, 'Odabrana varijanta nije potvrđena.');
        $snapshot = $project->snapshots()->findOrFail($task->snapshot_id);
        $snapshots->restore($project, $snapshot);

        return response()->json([
            'task' => $task->fresh(),
            'message' => 'Projekat je vraćen na stanje prije potvrde. Možeš pokrenuti novi proračun.',
        ]);
    }

    public function export(Project $project, ProjectBackgroundTask $task): StreamedResponse
    {
        $preview = $this->read($project, $task);

        return response()->streamDownload(function () use ($preview): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Tip', 'Oznaka', 'Roditelj', 'Kapacitet', 'Zauzeće', 'Dužina m', 'Status/poruka'], ';', '"', '');
            foreach (data_get($preview, 'odf_placement.odfs', []) as $odf) {
                fputcsv($output, ['ODF', $odf['provisional_name'] ?? $odf['key'], '', $odf['capacity'] ?? '', $odf['occupancy'] ?? '', '', $odf['locked'] ?? false ? 'Zaključan' : 'Predložen'], ';', '"', '');
            }
            foreach (data_get($preview, 'odo_placement.odos', []) as $odo) {
                fputcsv($output, ['ODO', $odo['provisional_name'] ?? $odo['key'], $odo['odf_key'] ?? '', $odo['capacity'] ?? '', $odo['occupancy'] ?? count($odo['house_ids'] ?? []), '', $odo['locked'] ?? false ? 'Zaključan' : 'Predložen'], ';', '"', '');
            }
            foreach (data_get($preview, 'odf_placement.primary_routes', []) as $route) {
                fputcsv($output, ['Primarna trasa', $route['key'], $route['from_odf_key'] ?? $route['from_odf_id'] ?? '', '', '', round($route['length_m'] ?? 0, 1), ''], ';', '"', '');
            }
            foreach (data_get($preview, 'routes.secondary_routes', []) as $route) {
                fputcsv($output, ['Sekundarna trasa', $route['key'], $route['odo_key'] ?? '', '', '', round($route['length_m'] ?? 0, 1), ''], ';', '"', '');
            }
            foreach (data_get($preview, 'routes.drop_routes', []) as $route) {
                fputcsv($output, ['Drop trasa', $route['key'], $route['odo_key'] ?? '', '', '', round($route['length_m'] ?? 0, 1), 'Kuća #'.($route['house_id'] ?? '')], ';', '"', '');
            }
            foreach (data_get($preview, 'warnings.items', []) as $warning) {
                fputcsv($output, ['Upozorenje', $warning['code'] ?? '', '', '', '', '', $warning['message'] ?? ''], ';', '"', '');
            }
            fclose($output);
        }, "veliki-plan-{$project->code}-varijanta-{$task->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function edit(Project $project, ProjectBackgroundTask $task, callable $callback): JsonResponse
    {
        abort_if($task->confirmed_at !== null, 409, 'Potvrđeni plan nije moguće mijenjati.');
        $current = $this->read($project, $task);
        $originalPath = $this->originalPath($task);
        try {
            $preview = $callback($current);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        if (! Storage::exists($originalPath)) {
            Storage::put($originalPath, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        Storage::put($task->result_path, json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return response()->json(['preview' => $preview, 'message' => 'Preview je ažuriran.']);
    }

    private function originalPath(ProjectBackgroundTask $task): string
    {
        return $task->result_path.'.original';
    }

    private function read(Project $project, ProjectBackgroundTask $task): array
    {
        abort_unless($project->planning_mode === 'large_auto' && $task->project_id === $project->id && $task->type === 'large_plan' && $task->status === 'completed' && $task->result_path && Storage::exists($task->result_path), 404);

        return json_decode(Storage::get($task->result_path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function metrics(array $preview): array
    {
        return [
            'odos' => count(data_get($preview, 'odo_placement.odos', [])),
            'odfs' => count(data_get($preview, 'odf_placement.odfs', [])),
            'primary_length_m' => array_sum(array_column(data_get($preview, 'odf_placement.primary_routes', []), 'length_m')),
            'secondary_length_m' => data_get($preview, 'routes.summary.secondary_length_m', 0),
            'drop_length_m' => data_get($preview, 'routes.summary.drop_length_m', 0),
            'problems' => data_get($preview, 'warnings.summary.total', 0),
            'can_confirm' => data_get($preview, 'warnings.can_confirm', false),
        ];
    }
}
