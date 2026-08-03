<?php

namespace App\Console\Commands;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class AuditFtthIntegrity extends Command
{
    protected $signature = 'ftth:audit-integrity {--project= : Provjeri samo jedan projekat}';

    protected $description = 'Provjeri projektne veze, geometrije i cikluse FTTH mreže';

    public function handle(): int
    {
        $projectId = $this->option('project') !== null ? (int) $this->option('project') : null;
        $issues = collect();

        $this->auditCabinets($issues, $projectId);
        $this->auditHouses($issues, $projectId);
        $this->auditRoutes($issues, $projectId);
        $this->auditCycles($issues, Cabinet::class, 'parent_cabinet_id', 'ODO', $projectId);
        $this->auditCycles($issues, NetworkBranch::class, 'parent_branch_id', 'krak', $projectId);

        if ($issues->isEmpty()) {
            $this->info('FTTH audit integriteta: nema pronađenih problema.');

            return self::SUCCESS;
        }

        $this->error("FTTH audit integriteta: pronađeno {$issues->count()} problema.");
        $this->table(['Tip', 'ID', 'Problem'], $issues->all());

        return self::FAILURE;
    }

    private function scoped(string $model, ?int $projectId): Builder
    {
        return $model::query()->when($projectId, fn (Builder $query) => $query->where('project_id', $projectId));
    }

    private function auditCabinets($issues, ?int $projectId): void
    {
        $this->scoped(Cabinet::class, $projectId)->with(['odf:id,project_id', 'parentCabinet:id,project_id', 'branch:id,project_id'])->each(function (Cabinet $cabinet) use ($issues): void {
            foreach ([['odf', $cabinet->odf_id], ['parentCabinet', $cabinet->parent_cabinet_id], ['branch', $cabinet->branch_id]] as [$relation, $id]) {
                if ($id && (! $cabinet->{$relation} || $cabinet->{$relation}->project_id !== $cabinet->project_id)) {
                    $issues->push(['ODO', $cabinet->id, "{$relation} veza ne pripada projektu {$cabinet->project_id}"]);
                }
            }
            $this->auditCoordinates($issues, 'ODO', $cabinet->id, $cabinet->latitude, $cabinet->longitude);
        });
    }

    private function auditHouses($issues, ?int $projectId): void
    {
        $this->scoped(House::class, $projectId)->with(['cabinet:id,project_id', 'branch:id,project_id'])->each(function (House $house) use ($issues): void {
            foreach ([['cabinet', $house->cabinet_id], ['branch', $house->branch_id]] as [$relation, $id]) {
                if ($id && (! $house->{$relation} || $house->{$relation}->project_id !== $house->project_id)) {
                    $issues->push(['Kuća', $house->id, "{$relation} veza ne pripada projektu {$house->project_id}"]);
                }
            }
            $this->auditCoordinates($issues, 'Kuća', $house->id, $house->latitude, $house->longitude);
        });
    }

    private function auditRoutes($issues, ?int $projectId): void
    {
        $this->scoped(NetworkRoute::class, $projectId)->with(['odf:id,project_id', 'cabinet:id,project_id'])->each(function (NetworkRoute $route) use ($issues): void {
            foreach ([['odf', $route->odf_id], ['cabinet', $route->cabinet_id]] as [$relation, $id]) {
                if ($id && (! $route->{$relation} || $route->{$relation}->project_id !== $route->project_id)) {
                    $issues->push(['Trasa', $route->id, "{$relation} veza ne pripada projektu {$route->project_id}"]);
                }
            }

            $path = $route->path ?? [];
            if (count($path) < 2) {
                $issues->push(['Trasa', $route->id, 'Geometrija mora imati najmanje dvije tačke']);

                return;
            }
            foreach ($path as $point) {
                if (! is_array($point) || count($point) !== 2 || ! is_numeric($point[0]) || ! is_numeric($point[1]) || $point[0] < -90 || $point[0] > 90 || $point[1] < -180 || $point[1] > 180) {
                    $issues->push(['Trasa', $route->id, 'Geometrija sadrži neispravnu koordinatu']);
                    break;
                }
            }
        });
    }

    private function auditCycles($issues, string $model, string $parentColumn, string $label, ?int $projectId): void
    {
        $parents = $this->scoped($model, $projectId)->pluck($parentColumn, 'id');
        foreach ($parents as $id => $parentId) {
            $visited = [];
            while ($parentId) {
                if ((int) $parentId === (int) $id || isset($visited[$parentId])) {
                    $issues->push([$label, $id, 'Otkriven ciklus u hijerarhiji']);
                    break;
                }
                $visited[$parentId] = true;
                $parentId = $parents[$parentId] ?? null;
            }
        }
    }

    private function auditCoordinates($issues, string $type, int $id, mixed $latitude, mixed $longitude): void
    {
        if (($latitude !== null && ($latitude < -90 || $latitude > 90)) || ($longitude !== null && ($longitude < -180 || $longitude > 180))) {
            $issues->push([$type, $id, 'Koordinate su izvan dozvoljenog raspona']);
        }
    }
}
