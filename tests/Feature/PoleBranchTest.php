<?php

namespace Tests\Feature;

use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\PoleBranch;
use App\Models\PoleBranchLink;
use App\Models\Project;
use App\Models\SurveyPoint;
use App\Services\MapDataService;
use App\Services\PoleBranchPlanner;
use App\Services\PoleBranchService;
use App\Services\SurveyPointImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PoleBranchTest extends TestCase
{
    use RefreshDatabase;

    private const LAT0 = 44.47;

    private const LNG0 = 18.48;

    public function test_planner_follows_the_street_and_splits_side_streets_into_branches(): void
    {
        // Main street along x (a0..a5, 40 m apart); side street goes north from a2.
        $points = [];
        foreach (range(0, 5) as $i) {
            $points["a{$i}"] = [$i * 40.0, 0.0];
        }
        foreach (range(1, 3) as $i) {
            $points["b{$i}"] = [80.0, $i * 35.0];
        }
        // Survey order must not matter.
        $shuffled = array_reverse($points, true);

        $result = (new PoleBranchPlanner)->plan($shuffled, 'a0', 80);

        $this->assertSame([], $result['warnings']);
        $this->assertCount(2, $result['branches']);
        [$main, $side] = $result['branches'];
        $this->assertSame('K1', $main['code']);
        $this->assertSame(['a0', 'a1', 'a2', 'a3', 'a4', 'a5'], $main['pole_ids']);
        $this->assertNull($main['from_id']);
        $this->assertSame('K1.1', $side['code']);
        $this->assertSame('K1', $side['parent_code']);
        $this->assertSame('a2', $side['from_id']);
        $this->assertSame(['b1', 'b2', 'b3'], $side['pole_ids']);
        $this->assertEqualsWithDelta(105.0, $side['length_m'], 0.01);
    }

    public function test_planner_starting_mid_street_makes_two_top_level_branches(): void
    {
        $points = [];
        foreach (range(0, 4) as $i) {
            $points[$i] = [$i * 40.0, 0.0];
        }

        $result = (new PoleBranchPlanner)->plan($points, 2, 80);

        $this->assertSame(['K1', 'K2'], array_column($result['branches'], 'code'));
        $this->assertSame(2, $result['branches'][0]['pole_ids'][0]);
        $this->assertSame(2, $result['branches'][1]['from_id']);
        $this->assertCount(5, array_merge(...array_column($result['branches'], 'pole_ids')));
    }

    public function test_planner_bridges_a_gap_with_a_flagged_long_span(): void
    {
        $points = ['p1' => [0.0, 0.0], 'p2' => [40.0, 0.0], 'p3' => [190.0, 0.0], 'p4' => [230.0, 0.0]];

        $result = (new PoleBranchPlanner)->plan($points, 'p1', 80);

        $this->assertCount(1, $result['branches']);
        $this->assertSame(['p1', 'p2', 'p3', 'p4'], $result['branches'][0]['pole_ids']);
        $this->assertSame([['from_id' => 'p2', 'to_id' => 'p3', 'length_m' => 150.0]], $result['branches'][0]['long_spans']);
        $this->assertCount(1, $result['warnings']);
    }

    public function test_generate_stores_branches_from_the_pole_nearest_the_odf(): void
    {
        $project = Project::factory()->create();
        // Survey order jumps between streets on purpose.
        $side = $this->pole($project, 1, 80, 35);
        $far = $this->pole($project, 2, 200, 0);
        $start = $this->pole($project, 3, 0, 0);
        $this->pole($project, 4, 80, 70);
        foreach ([40, 80, 120, 160] as $number => $x) {
            $this->pole($project, $number + 5, $x, 0);
        }
        $remeasured = $this->pole($project, 9, 200.6, 0.4);
        Odf::factory()->create(['project_id' => $project->id, 'name' => 'ODF-03-CENTAR', 'latitude' => self::LAT0, 'longitude' => self::LNG0 - 0.0002]);

        $this->postJson(route('projects.pole-branches.generate', $project), ['max_span_m' => 60])
            ->assertOk()->assertJsonPath('summary.branches', 2)->assertJsonPath('summary.poles', 8);

        $main = PoleBranch::where('project_id', $project->id)->where('code', 'K1')->firstOrFail();
        $this->assertSame([$start->id, $far->id], [$main->poles->first()->id, $main->poles->firstWhere('branch_position', 6)->id]);
        $this->assertSame(6, $main->pole_count);
        $sideBranch = PoleBranch::where('project_id', $project->id)->where('code', 'K1.1')->firstOrFail();
        $this->assertSame($main->id, $sideBranch->parent_id);
        $this->assertSame(1, $side->fresh()->branch_position);
        $this->assertSame($sideBranch->id, $side->fresh()->pole_branch_id);
        $this->assertSame([$main->id, 6], [$remeasured->fresh()->pole_branch_id, $remeasured->fresh()->branch_position]);

        $payload = app(MapDataService::class)->build($project->id)['data'];
        $this->assertSame(['K1', 'K1.1'], collect($payload['pole_branches'])->pluck('code')->all());
        $this->assertCount(3, collect($payload['pole_branches'])->firstWhere('code', 'K1.1')['path']);
        $this->assertSame(['SEKUNDARNI KRAK III-1', 'SEKUNDARNI KRAK III-1.1'], collect($payload['pole_branches'])->pluck('name')->all());
        $this->assertSame('SEKUNDARNI KRAK III-1', collect($payload['pole_branches'])->firstWhere('code', 'K1.1')['parent_name']);
        $this->assertSame('SEKUNDARNI KRAK III-1.1', collect($payload['survey_objects'])->firstWhere('id', $side->id)['branch_code']);
    }

    public function test_generate_from_chosen_pole_and_regenerate_replaces_branches(): void
    {
        $project = Project::factory()->create();
        $poles = collect(range(0, 4))->map(fn (int $i) => $this->pole($project, $i + 1, $i * 40, 0));

        $this->postJson(route('projects.pole-branches.generate', $project))->assertOk();
        $this->postJson(route('projects.pole-branches.generate', $project), ['root_point_id' => $poles[2]->id])->assertOk();

        $this->assertSame(['K1', 'K2'], PoleBranch::where('project_id', $project->id)->orderBy('sort_order')->pluck('code')->all());
        $this->assertSame(1, $poles[2]->fresh()->branch_position);

        $other = Project::factory()->create();
        $foreign = $this->pole($other, 1, 0, 0);
        $this->postJson(route('projects.pole-branches.generate', $project), ['root_point_id' => $foreign->id])->assertStatus(422);
    }

    public function test_clear_removes_branches_but_keeps_poles(): void
    {
        $project = Project::factory()->create();
        collect(range(0, 2))->each(fn (int $i) => $this->pole($project, $i + 1, $i * 40, 0));
        $this->postJson(route('projects.pole-branches.generate', $project))->assertOk();

        $this->deleteJson(route('projects.pole-branches.destroy', $project))->assertOk();

        $this->assertSame(0, PoleBranch::where('project_id', $project->id)->count());
        $this->assertSame(3, SurveyPoint::where('project_id', $project->id)->count());
        $this->assertSame(0, SurveyPoint::where('project_id', $project->id)->whereNotNull('pole_branch_id')->count());
    }

    public function test_txt_import_connects_poles_into_branches_and_keeps_the_chosen_start(): void
    {
        $project = Project::factory()->create();
        $importer = app(SurveyPointImportService::class);
        $line = fn (int $no, float $east, string $label) => $no.' '.(6549700 + $east).' 4923600.000 234.000 '.$label;

        $created = $importer->confirm($project, implode('
', [$line(1, 0, 'S.R.'), $line(2, 40, 'S.R. + S.E.'), $line(3, 80, 'drveni s.r.')]), 'stubovi.txt');

        $this->assertSame(1, $created['pole_branches']);
        $this->assertSame(3, PoleBranch::where('project_id', $project->id)->sole()->pole_count);

        $middle = SurveyPoint::where('project_id', $project->id)->where('point_no', 2)->sole();
        app(PoleBranchService::class)->generate($project, $middle->id);
        $created = $importer->confirm($project, $line(4, 120, 'S.E.'), 'stubovi-2.txt');

        $this->assertSame(2, $created['pole_branches']);
        $this->assertSame(1, $middle->fresh()->branch_position);
        $this->assertSame(4, SurveyPoint::where('project_id', $project->id)->whereNotNull('pole_branch_id')->count());
    }

    public function test_planner_starts_branches_at_risers_next_to_the_underground_line(): void
    {
        // Underground line along x; poles stand 3 m beside it. Two side streets go north.
        $points = [];
        foreach (range(0, 10) as $i) {
            $points["l{$i}"] = [$i * 40.0, 3.0];
        }
        foreach (range(1, 3) as $i) {
            $points["s{$i}"] = [160.0, $i * 40.0];
        }
        foreach (range(1, 2) as $i) {
            $points["t{$i}"] = [360.0, $i * 45.0];
        }
        $odfs = ['A' => [0.0, 0.0], 'B' => [400.0, 0.0]];

        $result = (new PoleBranchPlanner)->planFromLines($points, [[[0.0, 0.0], [400.0, 0.0]]], $odfs, 80);

        $this->assertSame([], $result['warnings']);
        $this->assertSame(['K1', 'K2'], array_column($result['branches'], 'code'));
        [$first, $second] = $result['branches'];
        $this->assertSame(['l4', 's1', 's2', 's3'], $first['pole_ids']);
        $this->assertSame('A', $first['odf_id']);
        $this->assertEqualsWithDelta(3.0, $first['entry']['length_m'], 0.01);
        $this->assertSame(['l9', 't1', 't2'], $second['pole_ids']);
        $this->assertSame('B', $second['odf_id']);
        $this->assertCount(9, $result['skipped_ids']);
    }

    public function test_planner_feeds_a_street_from_its_near_end_and_follows_survey_numbers(): void
    {
        // A loop: up from the line at x=0, across the top, and back down to 60 m above the line.
        $points = [];
        $number = 1;
        foreach (range(1, 8) as $i) {
            $points["a{$i}"] = [0.0, $i * 40.0];
        }
        foreach (range(1, 4) as $i) {
            $points["b{$i}"] = [$i * 40.0, 320.0];
        }
        foreach (range(1, 6) as $i) {
            $points["c{$i}"] = [200.0, 320.0 - $i * 40.0 - 20.0];
        }
        // The crew walked the last street upwards from the line: c6, c5, ... c1.
        $sequence = [];
        foreach (array_reverse(array_keys($points)) as $id) {
            $sequence[$id] = $number++;
        }

        $result = (new PoleBranchPlanner)->withSequence($sequence)->planFromLines($points, [[[-100.0, 0.0], [300.0, 0.0]]], [], 80);

        $risers = array_values(array_filter($result['branches'], fn (array $branch) => isset($branch['entry'])));
        $this->assertCount(2, $risers);
        $this->assertSame('c6', collect($risers)->firstWhere('pole_ids.0', 'c6')['pole_ids'][0] ?? null);
    }

    public function test_generate_uses_the_underground_line_and_refresh_keeps_using_it(): void
    {
        $project = Project::factory()->create();
        foreach (range(0, 5) as $i) {
            $this->pole($project, $i + 1, $i * 40, 2);
        }
        $this->pole($project, 7, 80, 45);
        $this->pole($project, 8, 80, 85);
        $odf = Odf::factory()->create(['project_id' => $project->id, 'latitude' => self::LAT0, 'longitude' => self::LNG0]);
        NetworkRoute::factory()->create([
            'project_id' => $project->id,
            'installation_type' => 'underground',
            'path' => [$this->latLng(0, 0), $this->latLng(200, 0)],
        ]);
        // An aerial route is not an underground line.
        NetworkRoute::factory()->create(['project_id' => $project->id, 'installation_type' => 'aerial', 'path' => [$this->latLng(0, 200), $this->latLng(200, 200)]]);

        $this->postJson(route('projects.pole-branches.generate', $project))
            ->assertOk()
            ->assertJsonPath('summary.risers', 1)
            ->assertJsonPath('summary.skipped', 5)
            ->assertJsonPath('summary.poles', 3);

        $branch = PoleBranch::where('project_id', $project->id)->sole();
        $this->assertSame($odf->id, $branch->odf_id);
        $this->assertEqualsWithDelta(2.0, $branch->entry_length_m, 0.1);
        $payload = collect(app(MapDataService::class)->build($project->id)['data']['pole_branches'])->sole();
        $this->assertSame($odf->name, $payload['odf_name']);
        $this->assertEqualsWithDelta(self::LAT0, $payload['entry']['lat'], 0.000001);

        // New poles: the branches are rebuilt from the line again, not from the old riser.
        $this->pole($project, 9, 80, 125);
        $this->assertSame(1, app(PoleBranchService::class)->refresh($project)['risers']);
        $this->assertSame(4, PoleBranch::where('project_id', $project->id)->sole()->pole_count);
    }

    public function test_manual_connect_and_cut_survive_regeneration_and_can_be_undone(): void
    {
        $project = Project::factory()->create();
        // Two parallel streets 60 m apart; the planner joins them at their start.
        $a = collect(range(0, 3))->map(fn (int $i) => $this->pole($project, $i + 1, $i * 40, 0));
        $b = collect(range(0, 3))->map(fn (int $i) => $this->pole($project, $i + 11, $i * 40, 60));
        $this->postJson(route('projects.pole-branches.generate', $project), ['root_point_id' => $a[0]->id])->assertOk();
        $this->assertSame(2, PoleBranch::where('project_id', $project->id)->count());

        // The engineer wants the second street fed from the far end instead.
        $this->postJson(route('projects.pole-branches.link', $project), ['from_point_id' => $a[0]->id, 'to_point_id' => $b[0]->id, 'type' => 'cut'])->assertOk();
        $this->postJson(route('projects.pole-branches.link', $project), ['from_point_id' => $a[3]->id, 'to_point_id' => $b[3]->id, 'type' => 'connect'])
            ->assertOk()->assertJsonPath('summary.branches', 1);

        $main = PoleBranch::where('project_id', $project->id)->sole();
        $this->assertSame([...$a->pluck('id'), ...$b->reverse()->pluck('id')], $main->poles->pluck('id')->all());
        $this->assertCount(2, app(MapDataService::class)->build($project->id)['data']['pole_branch_links']);

        app(PoleBranchService::class)->refresh($project);
        $this->assertSame(8, PoleBranch::where('project_id', $project->id)->sole()->pole_count);

        $this->deleteJson(route('projects.pole-branches.unlink', $project))->assertOk();
        $this->assertSame(0, PoleBranchLink::where('project_id', $project->id)->count());
        $this->assertSame(2, PoleBranch::where('project_id', $project->id)->count());

        $other = $this->pole(Project::factory()->create(), 1, 0, 0);
        $this->postJson(route('projects.pole-branches.link', $project), ['from_point_id' => $a[0]->id, 'to_point_id' => $other->id, 'type' => 'connect'])->assertStatus(422);
    }

    public function test_dxf_contains_pole_branch_lines(): void
    {
        $project = Project::factory()->create(['code' => 'KRAKOVI']);
        collect(range(0, 2))->each(fn (int $i) => $this->pole($project, $i + 1, $i * 40, 0));
        $this->postJson(route('projects.pole-branches.generate', $project))->assertOk();

        $response = $this->post(route('projects.dxf', $project));

        $response->assertOk();
        $contents = file_get_contents($response->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString("POLYLINE\r\n8\r\nFTTH_POLE_BRANCHES", $contents);
        $this->assertSame(3, substr_count($contents, "VERTEX\r\n8\r\nFTTH_POLE_BRANCHES"));
        $this->assertMatchesRegularExpression('/TEXT\r\n8\r\nFTTH_POLE_BRANCHES(?:\r\n[^\r\n]*)*?\r\n1\r\nSEKUNDARNI KRAK 1\r\n/', $contents);
    }

    public function test_branches_are_numbered_per_odf(): void
    {
        $project = Project::factory()->create();
        $first = Odf::factory()->create(['project_id' => $project->id, 'name' => 'ODF-01-BIKODZE']);
        $second = Odf::factory()->create(['project_id' => $project->id, 'name' => 'ODF RUDINE']);
        $branch = fn (string $code, int $order, Odf $odf, ?PoleBranch $parent = null) => PoleBranch::create([
            'project_id' => $project->id, 'parent_id' => $parent?->id, 'code' => $code, 'depth' => $parent ? 1 : 0,
            'sort_order' => $order, 'max_span_m' => 80, 'odf_id' => $odf->id,
        ]);
        $k1 = $branch('K1', 1, $first);
        $branch('K1.1', 2, $first, $k1);
        $k2 = $branch('K2', 3, $second);
        $branch('K2.1', 4, $second, $k2);
        $branch('K3', 5, $second);

        $names = collect(app(MapDataService::class)->build($project->id)['data']['pole_branches'])->pluck('name', 'code')->all();

        // ODF without a number in its name gets its order in the project.
        $this->assertSame([
            'K1' => 'SEKUNDARNI KRAK I-1',
            'K1.1' => 'SEKUNDARNI KRAK I-1.1',
            'K2' => 'SEKUNDARNI KRAK II-1',
            'K2.1' => 'SEKUNDARNI KRAK II-1.1',
            'K3' => 'SEKUNDARNI KRAK II-2',
        ], $names);
    }

    /** @return array{0: float, 1: float} */
    private function latLng(float $east, float $north): array
    {
        return [self::LAT0 + $north / 110574, self::LNG0 + $east / (111320 * cos(deg2rad(self::LAT0)))];
    }

    /** A lighting pole $east/$north metres from a fixed origin. */
    private function pole(Project $project, int $number, float $east, float $north): SurveyPoint
    {
        return SurveyPoint::create([
            'project_id' => $project->id,
            'import_batch' => sha1('poles'),
            'point_no' => $number,
            'pole_no' => $number,
            'x' => 6549700 + $east,
            'y' => 4923600 + $north,
            'latitude' => self::LAT0 + $north / 110574,
            'longitude' => self::LNG0 + $east / (111320 * cos(deg2rad(self::LAT0))),
            'code' => 'S.R.',
            'kind' => 'survey_object',
            'survey_category' => 'lighting_pole',
            'original_label' => 'S.R.',
            'source_crs' => 'EPSG:31276',
        ]);
    }
}
