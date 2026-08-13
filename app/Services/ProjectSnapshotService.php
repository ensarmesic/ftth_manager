<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProjectSnapshotService
{
    private const TABLES = [
        'odfs', 'routes', 'cabinets', 'houses', 'network_branches', 'materials',
        'project_appendix_items', 'gis_segments', 'gis_restricted_areas',
        'survey_points', 'map_drafts',
    ];

    public function create(Project $project, string $label): ProjectSnapshot
    {
        $payload = collect(self::TABLES)->mapWithKeys(fn (string $table) => [
            $table => DB::table($table)->where('project_id', $project->id)->get()->map(fn ($row) => (array) $row)->all(),
        ])->all();

        $snapshot = ProjectSnapshot::create([
            'project_id' => $project->id,
            'label' => $label,
            'payload' => $payload,
        ]);

        $expiredIds = ProjectSnapshot::query()->where('project_id', $project->id)
            ->latest()->pluck('id')->slice(10)->all();
        if ($expiredIds) {
            ProjectSnapshot::query()->whereKey($expiredIds)->delete();
        }

        return $snapshot;
    }

    public function restore(Project $project, ProjectSnapshot $snapshot): void
    {
        abort_unless($snapshot->project_id === $project->id, 404);
        $payload = $snapshot->payload;
        $this->create($project, 'Automatski: prije vraćanja verzije "'.$snapshot->label.'"');

        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($project, $payload): void {
                foreach (array_reverse(self::TABLES) as $table) {
                    DB::table($table)->where('project_id', $project->id)->delete();
                }
                foreach (self::TABLES as $table) {
                    foreach (array_chunk($payload[$table] ?? [], 250) as $rows) {
                        if ($rows) {
                            DB::table($table)->insert($rows);
                        }
                    }
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
}
