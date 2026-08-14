<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ProjectBackupService;
use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonException;
use Throwable;

class ProjectBackupController extends Controller
{
    public function backup(Project $project, ProjectBackupService $backupService)
    {
        $backup = $backupService->backup($project);
        $filename = str($project->code ?: $project->name)->slug().'-backup-'.now()->format('Ymd-His').'.json';

        return response()->json($backup, 200, [
            'Content-Disposition' => "attachment; filename=\"$filename\"",
            'Content-Type' => 'application/json',
        ]);
    }

    public function restore(Request $request, ProjectBackupService $backupService)
    {
        $request->validateWithBag('restoreBackup', [
            'backup' => 'required|file|mimes:json,txt|max:'.config('uploads.project_backup_kb'),
            'project_name' => 'nullable|string|max:255',
        ]);

        try {
            $backupFile = $request->file('backup');
            $backup = json_decode(file_get_contents($backupFile->getRealPath()), true, 512, JSON_THROW_ON_ERROR);
            $restoredProject = $backupService->restore($backup, $request->input('project_name'));

            return redirect()->route('projects.index')->with('success',
                "Projekt '{$restoredProject->name}' je uspješno restauriran.");
        } catch (JsonException) {
            return redirect()->back()->with('error', 'Backup datoteka nije ispravan JSON dokument.');
        } catch (InvalidArgumentException) {
            return redirect()->back()->with('error', 'Datoteka nije podržani FTTH Manager backup.');
        } catch (Throwable $e) {
            report($e);

            return redirect()->back()->with('error', 'Backup nije moguće vratiti. Provjeri datoteku i pokušaj ponovo.');
        }
    }
}
