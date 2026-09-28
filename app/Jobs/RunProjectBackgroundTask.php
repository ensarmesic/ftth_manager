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
use Illuminate\Support\Facades\Log;
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
        if (in_array($task->status, ['cancelled', 'cancelling'], true)) {
            $task->update(['status' => 'cancelled', 'status_message' => 'Proračun je otkazan.', 'finished_at' => now()]);

            return;
        }
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
            if ($task->fresh()->status === 'cancelling') {
                $task->update(['status' => 'cancelled', 'status_message' => 'Proračun je otkazan.', 'finished_at' => now()]);

                return;
            }
            $task->update(['status' => 'completed', 'progress' => 100, 'status_message' => 'Proračun je završen.', 'finished_at' => now()]);
        } catch (Throwable $exception) {
            if ($task->fresh()->status === 'cancelling') {
                $task->update(['status' => 'cancelled', 'status_message' => 'Proračun je otkazan.', 'finished_at' => now(), 'error' => null]);

                return;
            }
            $task->update(['status' => 'failed', 'status_message' => 'Proračun nije završen.', 'finished_at' => now(), 'error' => mb_substr($exception->getMessage(), 0, 2000)]);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        ProjectBackgroundTask::query()->whereKey($this->taskId)->update([
            'status' => 'failed',
            'status_message' => 'Proračun je prekinut nakon svih dozvoljenih pokušaja.',
            'finished_at' => now(),
            'error' => mb_substr($exception?->getMessage() ?? 'Queue worker je prekinuo zadatak.', 0, 2000),
        ]);
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
        $startedAt = hrtime(true);
        $memoryStarted = memory_get_usage(true);
        $phaseStartedAt = $startedAt;
        $activePhase = null;
        $phases = [];
        $result = app(LargePlannerService::class)->prepare(
            $task->project,
            function (int $progress, string $message) use ($task, &$activePhase, &$phaseStartedAt, &$phases): void {
                if ($task->fresh()->status === 'cancelling') {
                    throw new \RuntimeException('Proračun je otkazan na zahtjev korisnika.');
                }
                if ($activePhase !== null) {
                    $phases[] = $this->phaseMetric($activePhase, $phaseStartedAt);
                }
                $activePhase = ['progress' => $progress, 'message' => $message];
                $phaseStartedAt = hrtime(true);
                $task->update(['progress' => $progress, 'status_message' => $message]);
            },
        );
        if ($activePhase !== null) {
            $phases[] = $this->phaseMetric($activePhase, $phaseStartedAt);
        }
        $result['execution'] = [
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 1),
            'memory_delta_mb' => round(max(memory_get_usage(true) - $memoryStarted, 0) / 1_048_576, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1_048_576, 2),
            'houses' => (int) data_get($result, 'clustering.summary.houses', 0),
            'nodes' => (int) data_get($result, 'graph.summary.nodes', 0),
            'segments' => (int) data_get($result, 'graph.summary.edges', 0),
            'phases' => $phases,
        ];
        Log::info('Veliki planer je završio proračun.', ['task_id' => $task->id, 'project_id' => $task->project_id] + $result['execution']);
        $result = $this->preserveLockedElements($task, $result);
        $path = "background-tasks/{$task->id}/large-plan.json";
        Storage::put($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $task->update(['result_path' => $path, 'result_name' => 'large-plan.json']);
    }

    private function phaseMetric(array $phase, int $startedAt): array
    {
        $metric = $phase + ['duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 1)];
        Log::info('Završena faza velikog planera.', $metric);

        return $metric;
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
