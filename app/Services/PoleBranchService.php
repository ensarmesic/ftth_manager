<?php

namespace App\Services;

use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\PoleBranch;
use App\Models\PoleBranchLink;
use App\Models\Project;
use App\Models\SurveyPoint;
use App\Support\NearbyPointIndex;
use App\Support\SurveyCategories;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Generates and stores the pole branches ("krakovi") of a project. */
class PoleBranchService
{
    /** Poles closer than this are one cable point: a pole measured twice, or two poles side by side. */
    public const SAME_POLE_DISTANCE_M = 4.0;

    public const DEFAULT_MAX_SPAN_M = 80.0;

    public function __construct(private readonly PoleBranchPlanner $planner) {}

    /**
     * Replaces the project's branches. When the project has underground lines (and no
     * start pole is chosen) the branches start at risers next to those lines. Otherwise
     * they grow from $rootPointId, or from the pole nearest the project's ODF.
     *
     * @return array{branches: int, poles: int, length_m: float, long_spans: int, root_label: string, warnings: list<string>, risers: int, skipped: int}
     */
    public function generate(Project $project, ?int $rootPointId = null, float $maxSpan = self::DEFAULT_MAX_SPAN_M): array
    {
        $poles = SurveyPoint::where('project_id', $project->id)
            ->whereIn('survey_category', SurveyCategories::poleKeys())
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->orderBy('id')->get();
        if ($poles->count() < 2) {
            throw new InvalidArgumentException('Za krakove su potrebna najmanje dva stuba sa koordinatama.');
        }

        $origin = (float) $poles->avg('latitude');
        [$metric, $sameAs] = $this->mergeRemeasured($poles, $origin);
        $root = $rootPointId !== null ? ($sameAs[$rootPointId] ?? $rootPointId) : null;
        if ($root !== null && ! isset($metric[$root])) {
            throw new InvalidArgumentException('Odabrani početni stub ne pripada ovom projektu.');
        }
        $byId = $poles->keyBy('id');
        // Survey numbers hint how the crew walked along the streets.
        $this->planner->withSequence($poles->filter(fn (SurveyPoint $pole) => isset($metric[$pole->id]) && $pole->pole_no !== null)
            ->mapWithKeys(fn (SurveyPoint $pole) => [$pole->id => (int) $pole->pole_no])->all());
        $this->applyManualLinks($project, $metric, $sameAs);
        $routes = $root === null ? $this->undergroundRoutes($project) : collect();
        if ($routes->isNotEmpty()) {
            $odfs = Odf::where('project_id', $project->id)->whereNotNull('latitude')->whereNotNull('longitude')->orderBy('id')->get()->keyBy('id');
            $plan = $this->planner->planFromLines(
                $metric,
                $routes->map(fn (NetworkRoute $route) => array_map(fn (array $point) => $this->toMetric((float) $point[0], (float) $point[1], $origin), $this->routePath($route)))->values()->all(),
                $odfs->map(fn (Odf $odf) => $this->toMetric((float) $odf->latitude, (float) $odf->longitude, $origin))->all(),
                $maxSpan,
            );
            $routeIds = $routes->pluck('id')->values()->all();
            foreach ($plan['branches'] as &$branch) {
                if (isset($branch['entry'])) {
                    $branch['entry']['route_id'] = $routeIds[$branch['entry']['line']] ?? null;
                    [$branch['entry']['lat'], $branch['entry']['lng']] = $this->fromMetric($branch['entry']['x'], $branch['entry']['y'], $origin);
                }
            }
            unset($branch);
            $rootLabel = 'podzemna linija';
        } else {
            $root ??= $this->poleNearestOdf($project, $metric, $origin);
            $plan = $this->planner->plan($metric, $root, $maxSpan) + ['skipped_ids' => []];
            $rootPole = $byId[$root];
            $rootLabel = SurveyCategories::cadLabel($rootPole->survey_category, (int) ($rootPole->pole_no ?: $rootPole->point_no));
            // The whole tree hangs from one pole, so the ODF nearest that pole feeds every branch (and names them).
            $odfId = $this->odfNearest($project, $rootPole);
            foreach ($plan['branches'] as &$branch) {
                $branch['odf_id'] = $odfId;
            }
            unset($branch);
        }

        DB::transaction(function () use ($project, $plan, $sameAs, $maxSpan): void {
            $this->clear($project);
            $branchIds = [];
            $positions = [];
            foreach ($plan['branches'] as $order => $branch) {
                $model = PoleBranch::create([
                    'project_id' => $project->id,
                    'parent_id' => $branch['parent_code'] === null ? null : $branchIds[$branch['parent_code']],
                    'from_point_id' => $branch['from_id'],
                    'code' => $branch['code'],
                    'depth' => $branch['depth'],
                    'sort_order' => $order + 1,
                    'pole_count' => count($branch['pole_ids']),
                    'length_m' => $branch['length_m'],
                    'long_spans' => $branch['long_spans'] ?: null,
                    'max_span_m' => $maxSpan,
                    'odf_id' => $branch['odf_id'] ?? null,
                    'route_id' => $branch['entry']['route_id'] ?? null,
                    'entry_lat' => $branch['entry']['lat'] ?? null,
                    'entry_lng' => $branch['entry']['lng'] ?? null,
                    'entry_length_m' => $branch['entry']['length_m'] ?? null,
                ]);
                $branchIds[$branch['code']] = $model->id;
                foreach ($branch['pole_ids'] as $index => $poleId) {
                    $positions[$poleId] = [$model->id, $index + 1];
                }
            }
            foreach ($sameAs as $duplicate => $primary) {
                if (isset($positions[$primary])) {
                    $positions[$duplicate] = $positions[$primary];
                }
            }
            foreach ($positions as $poleId => [$branchId, $position]) {
                SurveyPoint::whereKey($poleId)->update(['pole_branch_id' => $branchId, 'branch_position' => $position]);
            }
        });

        return [
            'branches' => count($plan['branches']),
            'poles' => count($metric) - count($plan['skipped_ids']),
            'length_m' => round(array_sum(array_column($plan['branches'], 'length_m')), 1),
            'long_spans' => array_sum(array_map(fn (array $branch) => count($branch['long_spans']), $plan['branches'])),
            'root_label' => $rootLabel,
            'warnings' => $plan['warnings'],
            'risers' => count(array_filter($plan['branches'], fn (array $branch) => isset($branch['entry']))),
            'skipped' => count($plan['skipped_ids']),
        ];
    }

    /**
     * Forces ("connect") or forbids ("cut") the span between two poles and rebuilds the branches.
     *
     * @return array{branches: int, poles: int, length_m: float, long_spans: int, root_label: string, warnings: list<string>, risers: int, skipped: int}|null
     */
    public function link(Project $project, int $firstPointId, int $secondPointId, string $type): ?array
    {
        if ($firstPointId === $secondPointId) {
            throw new InvalidArgumentException('Odaberi dva različita stuba.');
        }
        $poles = SurveyPoint::where('project_id', $project->id)->whereIn('id', [$firstPointId, $secondPointId])
            ->whereIn('survey_category', SurveyCategories::poleKeys())->count();
        if ($poles !== 2) {
            throw new InvalidArgumentException('Oba stuba moraju pripadati ovom projektu.');
        }
        PoleBranchLink::updateOrCreate(
            ['project_id' => $project->id, 'from_point_id' => min($firstPointId, $secondPointId), 'to_point_id' => max($firstPointId, $secondPointId)],
            ['type' => $type],
        );

        return $this->refresh($project);
    }

    /** @return array{branches: int, poles: int, length_m: float, long_spans: int, root_label: string, warnings: list<string>, risers: int, skipped: int}|null */
    public function unlink(Project $project, ?int $linkId = null): ?array
    {
        PoleBranchLink::where('project_id', $project->id)->when($linkId !== null, fn ($query) => $query->whereKey($linkId))->delete();

        return $this->refresh($project);
    }

    /** Hands the manual corrections to the planner, on the merged (deduplicated) pole ids. */
    private function applyManualLinks(Project $project, array $metric, array $sameAs): void
    {
        $connect = [];
        $cut = [];
        foreach (PoleBranchLink::where('project_id', $project->id)->get() as $link) {
            $a = $sameAs[$link->from_point_id] ?? $link->from_point_id;
            $b = $sameAs[$link->to_point_id] ?? $link->to_point_id;
            if ($a === $b || ! isset($metric[$a], $metric[$b])) {
                continue;
            }
            if ($link->type === PoleBranchLink::CUT) {
                $cut[] = [$a, $b];
            } else {
                $connect[] = [$a, $b];
            }
        }
        $this->planner->withLinks($connect, $cut);
    }

    /** Underground routes the aerial branches start from. */
    private function undergroundRoutes(Project $project): Collection
    {
        return NetworkRoute::where('project_id', $project->id)->where('installation_type', 'underground')->orderBy('id')->get()
            ->filter(fn (NetworkRoute $route) => count($this->routePath($route)) >= 2)
            ->values();
    }

    /** @return list<array{0: float, 1: float}> [lat, lng] points of a route, skipping malformed ones */
    private function routePath(NetworkRoute $route): array
    {
        $path = $route->getAttribute('path');

        return is_array($path) ? array_values(array_filter($path, fn ($point) => is_array($point) && is_numeric($point[0] ?? null) && is_numeric($point[1] ?? null))) : [];
    }

    /**
     * Rebuilds the branches after the poles changed (e.g. a TXT import), keeping the
     * start pole and max span chosen earlier. Returns null when there is nothing to connect.
     *
     * @return array{branches: int, poles: int, length_m: float, long_spans: int, root_label: string, warnings: list<string>, risers: int, skipped: int}|null
     */
    public function refresh(Project $project): ?array
    {
        $main = PoleBranch::where('project_id', $project->id)->whereNull('parent_id')->orderBy('sort_order')->first();
        // Branches started from underground lines are rebuilt from the lines again.
        $rootId = $main?->entry_lat === null ? $main?->poles()->value('id') : null;
        $root = $rootId !== null && SurveyPoint::whereKey($rootId)->whereIn('survey_category', SurveyCategories::poleKeys())->exists() ? (int) $rootId : null;

        try {
            return $this->generate($project, $root, $main?->max_span_m ?? self::DEFAULT_MAX_SPAN_M);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function clear(Project $project): int
    {
        SurveyPoint::where('project_id', $project->id)->whereNotNull('pole_branch_id')
            ->update(['pole_branch_id' => null, 'branch_position' => null]);
        $count = PoleBranch::where('project_id', $project->id)->count();
        // Children first, so the parent foreign key never blocks the delete.
        PoleBranch::where('project_id', $project->id)->orderByDesc('depth')->get()->each->delete();

        return $count;
    }

    /**
     * Local metric coordinates per pole; re-measured poles collapse onto the first one.
     *
     * @return array{0: array<int, array{0: float, 1: float}>, 1: array<int, int>}
     */
    private function mergeRemeasured(Collection $poles, float $originLatitude): array
    {
        $index = new NearbyPointIndex(self::SAME_POLE_DISTANCE_M);
        $metric = [];
        $sameAs = [];
        foreach ($poles as $pole) {
            [$x, $y] = $this->toMetric((float) $pole->latitude, (float) $pole->longitude, $originLatitude);
            $primary = $index->find($x, $y);
            if ($primary !== null) {
                $sameAs[$pole->id] = $primary;

                continue;
            }
            $index->add($x, $y, $pole->id);
            $metric[$pole->id] = [$x, $y];
        }

        return [$metric, $sameAs];
    }

    private function poleNearestOdf(Project $project, array $metric, float $originLatitude): int
    {
        $odf = Odf::where('project_id', $project->id)->whereNotNull('latitude')->whereNotNull('longitude')->orderBy('id')->first()
            ?? SurveyPoint::where('project_id', $project->id)->where('survey_category', 'odf')
                ->whereNotNull('latitude')->whereNotNull('longitude')->orderBy('id')->first();
        if ($odf === null) {
            return array_key_first($metric);
        }
        [$ox, $oy] = $this->toMetric((float) $odf->latitude, (float) $odf->longitude, $originLatitude);
        $nearest = null;
        $best = INF;
        foreach ($metric as $id => [$x, $y]) {
            $distance = hypot($x - $ox, $y - $oy);
            if ($distance < $best) {
                [$nearest, $best] = [$id, $distance];
            }
        }

        return $nearest;
    }

    private function odfNearest(Project $project, SurveyPoint $pole): ?int
    {
        $origin = (float) $pole->latitude;
        [$px, $py] = $this->toMetric((float) $pole->latitude, (float) $pole->longitude, $origin);

        return Odf::where('project_id', $project->id)->whereNotNull('latitude')->whereNotNull('longitude')->orderBy('id')->get()
            ->sortBy(function (Odf $odf) use ($px, $py, $origin): float {
                [$x, $y] = $this->toMetric((float) $odf->latitude, (float) $odf->longitude, $origin);

                return hypot($x - $px, $y - $py);
            })->first()?->id;
    }

    /** @return array{0: float, 1: float} [lat, lng] */
    private function fromMetric(float $x, float $y, float $originLatitude): array
    {
        return [round($y / 110574.0, 8), round($x / (111320.0 * cos(deg2rad($originLatitude))), 8)];
    }

    /** Equirectangular projection — accurate to centimetres over a few kilometres. */
    private function toMetric(float $latitude, float $longitude, float $originLatitude): array
    {
        return [$longitude * 111320.0 * cos(deg2rad($originLatitude)), $latitude * 110574.0];
    }
}
