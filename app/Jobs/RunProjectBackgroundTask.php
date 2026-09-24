<?php

namespace App\Jobs;

use App\Http\Controllers\ProjectExportController;
use App\Http\Controllers\ProjectPrintController;
use App\Models\ProjectBackgroundTask;
use App\Services\AutoGisPlannerService;
use App\Services\LargePlanner\LargePlannerService;
use App\Services\LargePlanner\PreviewEditorService;
use App\Services\SurveyPointImportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RunProjectBackgroundTask implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 3;

    public array $backoff = [10, 30];

    public function __construct(public int $taskId) {}

    public function handle(): void
    {
        $task = ProjectBackgroundTask::with('project')->findOrFail($this->taskId);
        $task->update([
            'status' => 'running',
            'progress' => 1,
            'status_message' => 'Zadatak je pokrenut.',
            'attempt_count' => $task->attempt_count + 1,
            'started_at' => $task->started_at ?? now(),
            'finished_at' => null,
            'error' => null,
        ]);
        try {
            match ($task->type) {
                'dxf' => $this->dxf($task), 'print_pdf' => $this->pdf($task), 'auto_plan' => $this->autoPlan($task), 'survey_import' => $this->surveyImport($task), 'large_plan' => $this->largePlan($task),
                default => throw new \RuntimeException('Nepoznat tip background zadatka.'),
            };
            $task->update(['status' => 'completed', 'progress' => 100, 'status_message' => 'Proračun je završen.', 'finished_at' => now()]);
        } catch (Throwable $exception) {
            $task->update(['status' => 'failed', 'status_message' => 'Proračun nije završen.', 'finished_at' => now(), 'error' => mb_substr($exception->getMessage(), 0, 2000)]);
            throw $exception;
        }
    }

    private function dxf(ProjectBackgroundTask $task): void
    {
        $response = app(ProjectExportController::class)->exportDxf($task->project, Request::create('/', 'POST', $task->options ?? []));
        $path = "background-tasks/{$task->id}/project.dxf";
        Storage::put($path, file_get_contents($response->getFile()->getPathname()));
        $task->update(['result_path' => $path, 'result_name' => $task->project->code.'.dxf']);
    }

    private function pdf(ProjectBackgroundTask $task): void
    {
        $view = app(ProjectPrintController::class)($task->project);
        $path = "background-tasks/{$task->id}/project.pdf";
        Storage::put($path, Pdf::loadHtml($view->render())->setPaper('a4', 'landscape')->output());
        $task->update(['result_path' => $path, 'result_name' => $task->project->code.'.pdf']);
    }

    private function autoPlan(ProjectBackgroundTask $task): void
    {
        $result = app(AutoGisPlannerService::class)->preview($task->project, (int) ($task->options['limit'] ?? 80));
        $path = "background-tasks/{$task->id}/auto-plan.json";
        Storage::put($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $task->update(['result_path' => $path, 'result_name' => 'auto-plan.json']);
    }

    private function surveyImport(ProjectBackgroundTask $task): void
    {
        $contents = Storage::get($task->input_path);
        $result = app(SurveyPointImportService::class)->confirm($task->project, $contents, $task->options['filename'] ?? 'import.txt');
        $path = "background-tasks/{$task->id}/import-result.json";
        Storage::put($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        Storage::delete($task->input_path);
        $task->update(['result_path' => $path, 'result_name' => 'import-result.json']);
    }

    private function largePlan(ProjectBackgroundTask $task): void
    {
        $result = app(LargePlannerService::class)->prepare(
            $task->project,
            fn (int $progress, string $message) => $task->update(['progress' => $progress, 'status_message' => $message]),
        );
        $result = $this->preserveLockedElements($task, $result);
        $path = "background-tasks/{$task->id}/large-plan.json";
        Storage::put($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $task->update(['result_path' => $path, 'result_name' => 'large-plan.json']);
    }

    private function preserveLockedElements(ProjectBackgroundTask $task, array $result): array
    {
        $baseTaskId = $task->options['base_task_id'] ?? null;
        if (! $baseTaskId) {
            return $result;
        }
        $base = ProjectBackgroundTask::query()->whereKey($baseTaskId)->where('project_id', $task->project_id)->where('type', 'large_plan')->where('status', 'completed')->first();
        if (! $base?->result_path || ! Storage::exists($base->result_path)) {
            return $result;
        }
        $previous = json_decode(Storage::get($base->result_path), true, flags: JSON_THROW_ON_ERROR);
        foreach ([['odo_placement', 'odos'], ['odf_placement', 'odfs']] as [$section, $items]) {
            $locked = collect(data_get($previous, "{$section}.{$items}", []))->filter(fn (array $item) => $item['locked'] ?? false)->keyBy('key');
            $result[$section][$items] = collect($result[$section][$items] ?? [])->map(fn (array $item) => $locked->get($item['key'], $item))->all();
        }

        return app(PreviewEditorService::class)->recalculate($task->project, $result);
    }
}
