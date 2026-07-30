<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::command('ftth:prune-dxf-cache --days=30')->dailyAt('03:15');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ftth:backfill-house-branches {--project=}', function () {
    $toMeters = function (float $lat, float $lng, float $originLat, float $originLng): array {
        return [
            'x' => ($lng - $originLng) * 111320 * cos(deg2rad($originLat)),
            'y' => ($lat - $originLat) * 111320,
        ];
    };
    $distanceToSegment = function (float $lat, float $lng, array $start, array $end) use ($toMeters): float {
        [$aLat, $aLng] = $start;
        [$bLat, $bLng] = $end;
        $a = $toMeters((float) $aLat, (float) $aLng, $lat, $lng);
        $b = $toMeters((float) $bLat, (float) $bLng, $lat, $lng);
        $p = $toMeters($lat, $lng, $lat, $lng);
        $abx = $b['x'] - $a['x'];
        $aby = $b['y'] - $a['y'];
        $ab2 = max(0.000001, ($abx ** 2) + ($aby ** 2));
        $t = max(0, min(1, ((($p['x'] - $a['x']) * $abx) + (($p['y'] - $a['y']) * $aby)) / $ab2));
        $x = $a['x'] + ($abx * $t);
        $y = $a['y'] + ($aby * $t);

        return sqrt((($p['x'] - $x) ** 2) + (($p['y'] - $y) ** 2));
    };
    $distanceToRoute = function (float $lat, float $lng, array $points) use ($distanceToSegment): float {
        $best = INF;
        for ($i = 1; $i < count($points); $i++) {
            $best = min($best, $distanceToSegment($lat, $lng, $points[$i - 1], $points[$i]));
        }

        return $best;
    };

    $projectId = $this->option('project');
    $branchesQuery = DB::table('network_branches')
        ->join('routes', 'routes.id', '=', 'network_branches.route_id')
        ->where('network_branches.type', 'secondary')
        ->whereNotNull('routes.path')
        ->select('network_branches.id', 'network_branches.project_id', 'routes.path');
    if ($projectId) {
        $branchesQuery->where('network_branches.project_id', (int) $projectId);
    }
    $branches = $branchesQuery->get()
        ->map(fn ($branch) => [
            'id' => (int) $branch->id,
            'project_id' => (int) $branch->project_id,
            'path' => json_decode($branch->path, true) ?: [],
        ])
        ->filter(fn (array $branch) => count($branch['path']) > 1)
        ->groupBy('project_id');

    $housesQuery = DB::table('houses')
        ->whereNull('branch_id')
        ->whereNotNull('latitude')
        ->whereNotNull('longitude')
        ->select('id', 'project_id', 'latitude', 'longitude');
    if ($projectId) {
        $housesQuery->where('project_id', (int) $projectId);
    }

    $updated = 0;
    foreach ($housesQuery->cursor() as $house) {
        $projectBranches = $branches->get((int) $house->project_id, collect());
        $nearest = $projectBranches
            ->map(fn (array $branch) => [
                'id' => $branch['id'],
                'distance_m' => $distanceToRoute((float) $house->latitude, (float) $house->longitude, $branch['path']),
            ])
            ->sortBy('distance_m')
            ->first();
        if (! $nearest || ! is_finite($nearest['distance_m'])) {
            continue;
        }

        DB::table('houses')->where('id', $house->id)->update(['branch_id' => $nearest['id']]);
        $updated++;
    }

    $this->info("Backfilled {$updated} houses.");
})->purpose('Assign existing houses to their nearest secondary FTTH branch');

Artisan::command('ftth:renumber-cabinets {--project=}', function () {
    $toMeters = function (float $lat, float $lng, float $originLat, float $originLng): array {
        return [
            'x' => ($lng - $originLng) * 111320 * cos(deg2rad($originLat)),
            'y' => ($lat - $originLat) * 111320,
        ];
    };
    $projectPointToRoute = function (float $lat, float $lng, array $points) use ($toMeters): float {
        $best = INF;
        $chainage = 0.0;
        for ($i = 1; $i < count($points); $i++) {
            [$aLat, $aLng] = $points[$i - 1];
            [$bLat, $bLng] = $points[$i];
            $a = $toMeters((float) $aLat, (float) $aLng, $lat, $lng);
            $b = $toMeters((float) $bLat, (float) $bLng, $lat, $lng);
            $abx = $b['x'] - $a['x'];
            $aby = $b['y'] - $a['y'];
            $ab2 = max(0.000001, ($abx ** 2) + ($aby ** 2));
            $t = max(0, min(1, ((0 - $a['x']) * $abx + (0 - $a['y']) * $aby) / $ab2));
            $x = $a['x'] + ($abx * $t);
            $y = $a['y'] + ($aby * $t);
            $distance = sqrt(($x ** 2) + ($y ** 2));
            $segmentLength = sqrt($ab2);
            if ($distance < $best) {
                $best = $distance;
                $bestChainage = $chainage + ($segmentLength * $t);
            }
            $chainage += $segmentLength;
        }

        return $bestChainage ?? 0.0;
    };
    $branchPrefix = function ($branch): string {
        $label = trim((string) ($branch->code ?? '').' '.(string) ($branch->name ?? ''));
        preg_match('/(\d+(?:[.-]\d+)*)/', $label, $match);

        return str_replace(['.', '_'], '-', $match[1] ?? (string) $branch->id);
    };

    $branches = DB::table('network_branches')
        ->join('routes', 'routes.id', '=', 'network_branches.route_id')
        ->where('network_branches.type', 'secondary')
        ->whereNotNull('routes.path')
        ->when($this->option('project'), fn ($query, $project) => $query->where('network_branches.project_id', (int) $project))
        ->select('network_branches.id', 'network_branches.project_id', 'network_branches.code', 'network_branches.name', 'routes.path')
        ->orderBy('network_branches.sort_order')
        ->orderBy('network_branches.name')
        ->get();

    $updated = 0;
    foreach ($branches as $branch) {
        $points = json_decode($branch->path, true) ?: [];
        $cabinets = DB::table('cabinets')
            ->where('project_id', $branch->project_id)
            ->where('branch_id', $branch->id)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->map(fn ($cabinet) => (object) [
                ...get_object_vars($cabinet),
                'chainage' => count($points) > 1 ? $projectPointToRoute((float) $cabinet->latitude, (float) $cabinet->longitude, $points) : 0,
            ])
            ->sortBy([['chainage', 'asc'], ['id', 'asc']])
            ->values();

        $prefix = $branchPrefix($branch);
        foreach ($cabinets as $index => $cabinet) {
            DB::table('cabinets')->where('id', $cabinet->id)->update([
                'name' => 'FTTH '.$prefix.'-'.($index + 1),
                'branch_order' => $index + 1,
            ]);
            $updated++;
        }
    }

    $this->info("Renumbered {$updated} FTTH cabinets.");
})->purpose('Rename FTTH cabinets as FTTH branch-subbranch-index and set branch_order by route chainage');
