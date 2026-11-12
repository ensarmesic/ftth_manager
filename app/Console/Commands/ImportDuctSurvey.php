<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\DuctSurveyImportService;
use Illuminate\Console\Command;

/**
 * Rebuilds a project's network from its duct survey TXT files. Replaces
 * everything a previous survey import created in that project (manually
 * drawn elements are never touched).
 */
class ImportDuctSurvey extends Command
{
    protected $signature = 'survey:import-ducts {project : id projekta} {files* : svi TXT fajlovi snimanja projekta}';

    protected $description = 'Uvezi TXT snimak rova u projekat (zamjenjuje raniji uvoz snimka)';

    public function handle(DuctSurveyImportService $import): int
    {
        $project = Project::find($this->argument('project'));
        if ($project === null) {
            $this->error('Nema projekta '.$this->argument('project'));

            return self::FAILURE;
        }
        $files = [];
        foreach ($this->argument('files') as $file) {
            if (! is_file($file)) {
                $this->error("Nema fajla: {$file}");

                return self::FAILURE;
            }
            $files[basename($file)] = (string) file_get_contents($file);
        }

        $outcome = $import->import($project, $files);

        $this->info("Projekat {$project->name}: uvoz završen.");
        $this->line('Uklonjeno iz ranijeg uvoza: '.json_encode(array_filter($outcome['removed'])));
        $this->line('Napravljeno: '.json_encode($outcome['created']));
        foreach ($outcome['result']['warnings'] as $warning) {
            $this->warn('#'.$warning['point_no'].': '.$warning['message']);
        }

        return self::SUCCESS;
    }
}
