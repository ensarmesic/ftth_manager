<?php

namespace App\Console\Commands;

use App\Models\ProjectBackgroundTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneBackgroundTasks extends Command
{
    protected $signature = 'ftth:prune-background-tasks {--days=30 : Starost nepotvrđenih zadataka u danima}';

    protected $description = 'Remove stale temporary files from unconfirmed project background tasks';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $files = 0;
        $tasks = 0;

        ProjectBackgroundTask::query()
            ->whereNull('confirmed_at')
            ->whereIn('status', ['completed', 'failed'])
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(200, function ($staleTasks) use (&$files, &$tasks): void {
                foreach ($staleTasks as $task) {
                    foreach (array_filter([$task->input_path, $task->result_path]) as $path) {
                        if (Storage::exists($path) && Storage::delete($path)) {
                            $files++;
                        }
                    }

                    $directory = 'background-tasks/'.$task->id;
                    if (Storage::directoryExists($directory)) {
                        Storage::deleteDirectory($directory);
                    }

                    $task->update([
                        'status' => 'expired',
                        'status_message' => 'Privremeni rezultat je automatski uklonjen nakon isteka roka.',
                        'input_path' => null,
                        'result_path' => null,
                        'result_name' => null,
                    ]);
                    $tasks++;
                }
            });

        $this->info("Očišćeno zadataka: {$tasks}; datoteka: {$files}.");

        return self::SUCCESS;
    }
}
