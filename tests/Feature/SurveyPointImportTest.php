<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\ProjectAppendixItem;
use App\Models\SurveyPoint;
use App\Services\GeoTransformService;
use App\Services\SurveyPointImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class SurveyPointImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Real-format sample: header variants, a ZO point, a šaht, an ODF pair and
     * a GLUED line (two records on one line) exactly as the instrument exports.
     */
    private function sampleContents(): string
    {
        return implode("\n", [
            '1  6549699.731  4923604.537  234.266  Rov +14/10 Zelena',
            '2  6549703.323  4923595.954  234.148  Rov +14/10 Zelena',
            '3  6549707.842  4923586.519  234.204  Rov +14/10 Zelena',
            '4  6549710.913  4923579.800  234.237  Rov +14/10 Zelena',
            '5  6549712.100  4923578.100  234.100  Saht',
            '6  6549713.437  4923574.447  234.236  rov+mc 10/8 X1 - zo3',
            '7  6549716.317  4923568.990  234.121  rov+mc 10/8 X1 - zo3',
            '8  6549719.187  4923562.499  234.207  rov+mc 10/8 X1 - zo3',
            '9  6549720.000  4923561.000  234.000  ZO 3',
            // glued line: two records merged by the instrument
            '10  6549724.000  4923558.000  233.900  ODF11  6549724.500  4923558.400  233.900  ODF',
            '12  6549730.000  4923550.000  233.800  Slinga u tacki 5 za ovu kucu',
        ]);
    }

    public function test_gk_to_wgs84_transform_round_trips(): void
    {
        $transform = new GeoTransformService;
        [$lat, $lng] = $transform->gaussKrugerToWgs84(6549699.731, 4923604.537, 6);

        // Gornje Zivinice area
        $this->assertEqualsWithDelta(44.4552, $lat, 0.001);
        $this->assertEqualsWithDelta(18.6194, $lng, 0.001);

        [$e, $n] = $transform->wgs84ToGaussKruger($lat, $lng, 6);
        $this->assertEqualsWithDelta(6549699.731, $e, 0.01);
        $this->assertEqualsWithDelta(4923604.537, $n, 0.01);
    }

    public function test_parser_handles_variants_and_glued_lines(): void
    {
        $service = app(SurveyPointImportService::class);
        $points = $service->parse($this->sampleContents());

        // 12 records — the glued line contributes two.
        $this->assertCount(12, $points);

        $byKind = collect($points)->groupBy('kind')->map->count();
        $this->assertSame(7, $byKind['trench']);
        $this->assertSame(1, $byKind['cabinet']);
        $this->assertSame(1, $byKind['manhole']);
        $this->assertSame(2, $byKind['odf']);
        $this->assertSame(1, $byKind['sling']);

        // Trench toward "zo3" is a trench, not a cabinet, carries 10/8 for ZO 3.
        $zo3Trench = collect($points)->firstWhere('code', 'rov+mc 10/8 X1 - zo3');
        $this->assertSame('trench', $zo3Trench['kind']);
        $this->assertSame('10/8', $zo3Trench['microduct_type']);
        $this->assertSame('3', $zo3Trench['zo_tag']);

        // The 14/10 run carries the green duct.
        $green = collect($points)->firstWhere('code', 'Rov +14/10 Zelena');
        $this->assertSame(['Zelena'], $green['colors']);

        // Graph: the šaht in the middle must NOT break the dig — the whole
        // walk is ONE physical trench chain (7 trench points).
        $network = $service->buildNetwork($points);
        $this->assertCount(1, $network['trenches']);
        $this->assertSame(7, $network['trenches'][0]['points']);

        // Ducts: green 14/10 (first 4 points) + the ZO 3 10/8 (last 3) —
        // the transition edge between them carries neither duct.
        $labels = collect($network['ducts'])->pluck('label')->sort()->values()->all();
        $this->assertSame(['MC 10/8 ZO 3', 'MC 14/10 Zelena'], $labels);
        $green = collect($network['ducts'])->firstWhere('label', 'MC 14/10 Zelena');
        $this->assertCount(4, $green['path']);
    }

    public function test_multiple_colors_in_one_trench_become_separate_ducts(): void
    {
        $service = app(SurveyPointImportService::class);
        $contents = implode("\n", [
            '1  6549699.731  4923604.537  234.0  Rov + 14/10 mc Zuta i Plave i Zelena',
            '2  6549703.323  4923595.954  234.0  Rov + 14/10 mc Zuta i Plave i Zelena',
            '3  6549707.842  4923586.519  234.0  Rov +MD+ 14 mc -Ze+Pl+Cr',
            '4  6549710.913  4923579.800  234.0  Rov +MD+ 14 mc -Ze+Pl+Cr',
        ]);

        $points = $service->parse($contents);
        $ducts = $service->buildNetwork($points)['ducts'];

        // Zuta/Plava/Zelena from section 1; Zelena+Plava continue through section 2, Crvena
        // starts there, and "MD" (the shared reserve duct) is its own line through section 2.
        $labels = collect($ducts)->pluck('label')->sort()->values()->all();
        $this->assertSame(['MC 14/10 Crvena', 'MC 14/10 MD', 'MC 14/10 Plava', 'MC 14/10 Zelena', 'MC 14/10 Zuta'], $labels);

        // Green duct spans both sections (graph-connected), yellow only the first.
        $green = collect($ducts)->firstWhere('label', 'MC 14/10 Zelena');
        $yellow = collect($ducts)->firstWhere('label', 'MC 14/10 Zuta');
        $this->assertGreaterThan(count($yellow['path']), count($green['path']));
    }

    public function test_duct_identities_propagate_through_unmarked_transition_points(): void
    {
        $service = app(SurveyPointImportService::class);
        $contents = implode("\n", [
            '1  6549699.731  4923604.537  234.0  Rov +14/10 Zelena',
            '2  6549703.323  4923595.954  234.0  Rov',
            '3  6549707.842  4923586.519  234.0  Rov +14/10 Zelena',
        ]);

        $points = $service->parse($contents);
        $ducts = $service->buildNetwork($points)['ducts'];

        $this->assertCount(1, $ducts);
        $this->assertSame('MC 14/10 Zelena', $ducts[0]['label']);
        $this->assertSame(3, count($ducts[0]['path']));
    }

    public function test_multi_digit_x_count_and_last_zo_destination_are_read_from_description(): void
    {
        $service = app(SurveyPointImportService::class);
        $points = $service->parse(implode("\n", [
            '1  6549699.731  4923604.537  234.0  Rov + mc 10/8 x12 od ZO 1 do ZO 4',
            '2  6549703.323  4923595.954  234.0  Rov + mc 10/8 x12 od ZO 1 do ZO 4',
        ]));

        $this->assertSame(12, $points[0]['microduct_count']);
        $this->assertSame('4', $points[0]['zo_tag']);

        $ducts = $service->buildNetwork($points)['ducts'];
        $this->assertCount(1, $ducts);
        $this->assertSame('MC 12x10/8 ZO 4', $ducts[0]['label']);
    }

    public function test_one_survey_point_can_describe_multiple_ducts_in_the_same_trench(): void
    {
        $service = app(SurveyPointImportService::class);
        $points = $service->parse(implode("\n", [
            '1  6549699.731  4923604.537  234.0  Rov; 14/10 Zelena; 14/10 Plava; 10/8 X1 ZO 3',
            '2  6549703.323  4923595.954  234.0  Rov; 14/10 Zelena; 14/10 Plava; 10/8 X1 ZO 3',
            '3  6549707.842  4923586.519  234.0  Rov; 14/10 Zelena; 14/10 Plava; 10/8 X1 ZO 3',
        ]));

        $network = $service->buildNetwork($points);

        $this->assertCount(1, $network['trenches']);
        $this->assertSame(
            ['MC 10/8 ZO 3', 'MC 14/10 Plava', 'MC 14/10 Zelena'],
            collect($network['ducts'])->pluck('label')->sort()->values()->all()
        );
    }

    public function test_untagged_house_duct_inherits_unique_cabinet_from_touching_main_route(): void
    {
        $service = app(SurveyPointImportService::class);
        $points = $service->parse(implode("\n", [
            '1  6549699.731  4923604.537  234.0  Rov 14/10 ZO 7',
            '2  6549703.323  4923595.954  234.0  Rov 14/10 ZO 7',
            '3  6549707.842  4923586.519  234.0  Rov 14/10 ZO 7',
            // Surveyor returns to point 2 and starts an unlabelled house branch.
            '4  6549703.323  4923595.954  234.0  Rov 10/8',
            '5  6549698.500  4923592.000  234.0  Rov 10/8',
            '6  6549693.500  4923588.000  234.0  Kuca 10/8',
        ]));

        $ducts = $service->buildNetwork($points)['ducts'];
        $houseDuct = collect($ducts)->firstWhere('microduct_type', '10/8');

        $this->assertNotNull($houseDuct);
        $this->assertSame('7', $houseDuct['zo_tag']);
        $this->assertSame('MC 10/8 ZO 7', $houseDuct['label']);
    }

    public function test_later_partial_survey_extends_existing_trench_and_duct_instead_of_duplicating(): void
    {
        $project = Project::factory()->create();
        $service = app(SurveyPointImportService::class);

        $first = implode("\n", [
            '1  6549699.731  4923604.537  234.0  Rov +14/10 Zelena',
            '2  6549703.323  4923595.954  234.0  Rov +14/10 Zelena',
        ]);
        $second = implode("\n", [
            '3  6549703.323  4923595.954  234.0  Rov +14/10 Zelena',
            '4  6549707.842  4923586.519  234.0  Rov +14/10 Zelena',
        ]);

        $service->confirm($project, $first, 'prvi.txt');
        $initialTrenchLength = NetworkRoute::where('project_id', $project->id)->where('route_type', 'trench')->value('duct_length_m');

        $service->confirm($project, $second, 'drugi.txt');

        $this->assertSame(1, NetworkRoute::where('project_id', $project->id)->where('route_type', 'trench')->count());
        $this->assertSame(1, NetworkRoute::where('project_id', $project->id)->where('route_type', 'distribution')->count());

        $trench = NetworkRoute::where('project_id', $project->id)->where('route_type', 'trench')->first();
        $duct = NetworkRoute::where('project_id', $project->id)->where('route_type', 'distribution')->first();

        $this->assertGreaterThan($initialTrenchLength, $trench->duct_length_m);
        $this->assertCount(3, $trench->path);
        $this->assertCount(3, $duct->path);
    }

    public function test_house_endpoints_create_single_duct_chain(): void
    {
        $service = app(SurveyPointImportService::class);
        $contents = implode("\n", [
            '1  6549699.731  4923604.537  234.0  Rov +14/10 Zelena',
            '2  6549703.323  4923595.954  234.0  Rov + 14/10 Zelena',
            '3  6549707.842  4923586.519  234.0  Kuca',
        ]);

        $points = $service->parse($contents);
        $ducts = $service->buildNetwork($points)['ducts'];

        $this->assertCount(1, $ducts);
        $this->assertSame('MC 14/10 Zelena', $ducts[0]['label']);
        $this->assertSame(3, count($ducts[0]['path']));
    }

    public function test_import_creates_elements_and_blocks_reimport(): void
    {
        $project = Project::factory()->create();
        $file = UploadedFile::fake()->createWithContent('snimak.txt', $this->sampleContents());

        $this->postJson(route('projects.survey-points.import', $project), ['points_file' => $file])
            ->assertCreated()
            ->assertJsonPath('created.points', 12)
            ->assertJsonPath('created.trenches', 1) // one continuous physical dig
            ->assertJsonPath('created.ducts', 2)
            ->assertJsonPath('created.cabinets', 1)
            ->assertJsonPath('created.odfs', 1) // two ODF points a metre apart merge into one
            ->assertJsonPath('created.manholes', 1)
            ->assertJsonPath('created.houses', 1); // the slinga point

        $this->assertSame(12, SurveyPoint::where('project_id', $project->id)->count());
        $this->assertSame(1, NetworkRoute::where('project_id', $project->id)->where('route_type', 'trench')->count());
        // The ZO 3 duct's walk (within TRENCH_GAP_M) continues straight through to the
        // "Slinga" point, so it ends AT that house and is a drop; the green 14/10 duct
        // doesn't reach a house or ODF, so it stays a plain distribution run.
        $this->assertSame(1, NetworkRoute::where('project_id', $project->id)->where('route_type', 'drop')->count());
        $this->assertSame(1, NetworkRoute::where('project_id', $project->id)->where('route_type', 'distribution')->count());
        $this->assertSame(1, Cabinet::where('project_id', $project->id)->count());
        $this->assertSame(1, Odf::where('project_id', $project->id)->count());
        $this->assertSame(1, ProjectAppendixItem::where('project_id', $project->id)->where('type', 'manhole')->count());

        $trench = NetworkRoute::where('project_id', $project->id)->where('route_type', 'trench')->first();
        $this->assertStringContainsString('Geodetski snimak', $trench->note);
        $this->assertSame('ZO 3', Cabinet::where('project_id', $project->id)->value('name'));

        // The ZO 3 duct is bound to the ZO 3 cabinet created in the same import, and ends
        // at the house tapped by the "Slinga" point.
        $cabinet = Cabinet::where('project_id', $project->id)->first();
        $house = House::where('project_id', $project->id)->first();
        $zoDuct = NetworkRoute::where('project_id', $project->id)->where('name', 'MC 10/8 ZO 3')->first();
        $this->assertNotNull($zoDuct);
        $this->assertSame($cabinet->id, $zoDuct->cabinet_id);
        $this->assertSame('10/8', $zoDuct->microduct_type);
        $this->assertSame('drop', $zoDuct->route_type);
        $this->assertSame('cabinet', $zoDuct->from_type);
        $this->assertSame($cabinet->id, $zoDuct->from_id);
        $this->assertSame('house', $zoDuct->to_type);
        $this->assertSame($house->id, $zoDuct->to_id);

        // The green 14/10 duct exists and carries its colour in the name.
        $this->assertNotNull(NetworkRoute::where('project_id', $project->id)->where('name', 'MC 14/10 Zelena')->first());

        // The slinga produced an unassigned house (no branch/cabinet assignment of its own —
        // the drop route above is what connects it to the network).
        $this->assertNotNull($house);
        $this->assertNull($house->cabinet_id);

        // Re-importing the same file is refused.
        $again = UploadedFile::fake()->createWithContent('snimak.txt', $this->sampleContents());
        $this->postJson(route('projects.survey-points.import', $project), ['points_file' => $again])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Ovaj fajl je vec uvezen u ovaj projekat.']);
        $this->assertSame(12, SurveyPoint::where('project_id', $project->id)->count());
    }

    public function test_preview_reports_summary_without_writing(): void
    {
        $project = Project::factory()->create();
        $file = UploadedFile::fake()->createWithContent('snimak.txt', $this->sampleContents());

        $this->postJson(route('projects.survey-points.preview', $project), ['points_file' => $file])
            ->assertOk()
            ->assertJsonPath('total_points', 12)
            ->assertJsonPath('already_imported', false)
            ->assertJsonPath('by_kind.trench', 7)
            ->assertJsonCount(1, 'trench_runs')
            ->assertJsonCount(1, 'odfs');

        $this->assertSame(0, SurveyPoint::count());
        $this->assertSame(0, NetworkRoute::count());
    }

    public function test_boring_and_splice_points_create_appendix_items_without_duplicating_on_reimport(): void
    {
        $project = Project::factory()->create();
        $service = app(SurveyPointImportService::class);
        $contents = implode("\n", [
            '1  6549699.731  4923604.537  234.0  Rov +14/10 Zelena',
            '2  6549703.323  4923595.954  234.0  Busenje FI 130',
            '3  6549707.842  4923586.519  234.0  Spojnica mc 14/10 Zelene',
        ]);

        $created = $service->confirm($project, $contents, 'prvi.txt');

        $this->assertSame(1, $created['borings']);
        $this->assertSame(1, $created['splices']);

        $boring = ProjectAppendixItem::where('project_id', $project->id)->where('type', 'boring_fi_130')->first();
        $this->assertNotNull($boring);
        $this->assertSame(0.0, (float) $boring->quantity); // no measured length from a single survey point
        $this->assertNull($boring->length_m);

        $splice = ProjectAppendixItem::where('project_id', $project->id)->where('type', 'splice')->first();
        $this->assertNotNull($splice);
        $this->assertSame(1.0, (float) $splice->quantity);

        // Same physical points surveyed again must not duplicate the appendix items.
        $second = implode("\n", [
            '4  6549699.731  4923604.537  234.0  Rov +14/10 Zelena',
            '5  6549703.323  4923595.954  234.0  Busenje FI 130',
            '6  6549707.842  4923586.519  234.0  Spojnica mc 14/10 Zelene',
        ]);
        $service->confirm($project, $second, 'drugi.txt');

        $this->assertSame(1, ProjectAppendixItem::where('project_id', $project->id)->where('type', 'boring_fi_130')->count());
        $this->assertSame(1, ProjectAppendixItem::where('project_id', $project->id)->where('type', 'splice')->count());
    }

    public function test_duct_ending_at_house_gets_drop_route_type_and_house_binding(): void
    {
        $project = Project::factory()->create();
        $service = app(SurveyPointImportService::class);
        $contents = implode("\n", [
            '1  6549699.731  4923604.537  234.0  ZO 9',
            '2  6549703.323  4923595.954  234.0  Rov 10/8 ZO 9',
            '3  6549707.842  4923586.519  234.0  Rov 10/8 ZO 9',
            '4  6549710.913  4923579.800  234.0  Kuca',
        ]);

        $service->confirm($project, $contents, 'kuca.txt');

        $cabinet = Cabinet::where('project_id', $project->id)->firstOrFail();
        $house = House::where('project_id', $project->id)->firstOrFail();
        $route = NetworkRoute::where('project_id', $project->id)->where('route_type', 'drop')->first();

        $this->assertNotNull($route);
        $this->assertSame('cabinet', $route->from_type);
        $this->assertSame($cabinet->id, $route->from_id);
        $this->assertSame($cabinet->id, $route->cabinet_id);
        $this->assertSame('house', $route->to_type);
        $this->assertSame($house->id, $route->to_id);
    }

    public function test_duct_starting_near_odf_gets_feeder_route_type(): void
    {
        $project = Project::factory()->create();
        $service = app(SurveyPointImportService::class);
        $contents = implode("\n", [
            '1  6549699.731  4923604.537  234.0  ODF',
            '2  6549703.323  4923595.954  234.0  Rov 14/10 Plava',
            '3  6549707.842  4923586.519  234.0  Rov 14/10 Plava',
        ]);

        $service->confirm($project, $contents, 'feeder.txt');

        $odf = Odf::where('project_id', $project->id)->firstOrFail();
        $route = NetworkRoute::where('project_id', $project->id)->where('route_type', 'feeder')->first();

        $this->assertNotNull($route);
        $this->assertSame('odf', $route->from_type);
        $this->assertSame($odf->id, $route->from_id);
    }

    public function test_preview_flags_ambiguous_cabinet_match_and_import_applies_manual_override(): void
    {
        $project = Project::factory()->create();
        $service = app(SurveyPointImportService::class);

        // Two cabinets exist from an earlier import.
        $service->confirm($project, implode("\n", [
            '1  6549699.731  4923604.537  234.0  ZO 1',
            '2  6549703.323  4923595.954  234.0  ZO 2',
        ]), 'cabinets.txt');
        $zo1 = Cabinet::where('project_id', $project->id)->where('name', 'ZO 1')->firstOrFail();
        $zo2 = Cabinet::where('project_id', $project->id)->where('name', 'ZO 2')->firstOrFail();

        // An untagged duct now surveyed near both — no explicit ZO tag to disambiguate.
        $ductContents = implode("\n", [
            '3  6549707.842  4923586.519  234.0  Rov 14/10 Plava',
            '4  6549710.913  4923579.800  234.0  Rov 14/10 Plava',
        ]);
        $preview = $service->preview($project, $ductContents, 'duct.txt');

        $this->assertCount(1, $preview['ducts']);
        $duct = $preview['ducts'][0];
        $this->assertSame('ambiguous', $duct['match_confidence']);
        $this->assertSame($zo2->id, $duct['matched_cabinet_id']); // closer cabinet wins by default
        $candidateIds = collect($duct['candidates'])->pluck('id')->all();
        $this->assertContains($zo1->id, $candidateIds);
        $this->assertContains($zo2->id, $candidateIds);

        // The user picks the farther cabinet instead — that choice must win on import.
        $service->confirm($project, $ductContents, 'duct.txt', [$duct['key'] => $zo1->id]);

        $route = NetworkRoute::where('project_id', $project->id)->where('name', 'like', 'MC%Plava%')->first();
        $this->assertNotNull($route);
        $this->assertSame($zo1->id, $route->cabinet_id);
    }

    public function test_bare_reserve_loop_is_not_mistaken_for_a_house(): void
    {
        $service = app(SurveyPointImportService::class);

        // "Slinga" with no house word is a cable reserve coil, not a customer tap.
        $points = $service->parse(implode("\n", [
            '1  6549699.731  4923604.537  234.0  Rov +14/10 Plava',
            '2  6549703.323  4923595.954  234.0  Slinga 14 Mc Plava',
            '3  6549707.842  4923586.519  234.0  Rov +14/10 Plava',
        ]));

        $this->assertSame('loop', $points[1]['kind']);

        // The duct still runs straight through the reserve point instead of stopping there.
        $ducts = $service->buildNetwork($points)['ducts'];
        $this->assertCount(1, $ducts);
        $this->assertCount(3, $ducts[0]['path']);

        $project = Project::factory()->create();
        $created = $service->confirm($project, implode("\n", [
            '1  6549699.731  4923604.537  234.0  Rov +14/10 Plava',
            '2  6549703.323  4923595.954  234.0  Slinga 14 Mc Plava',
            '3  6549707.842  4923586.519  234.0  Rov +14/10 Plava',
        ]), 'rezerva.txt');

        $this->assertSame(0, $created['houses']);
        $this->assertSame(0, House::count());
    }

    public function test_each_house_on_a_shared_10_8_run_gets_its_own_independent_drop(): void
    {
        $project = Project::factory()->create();
        $service = app(SurveyPointImportService::class);

        // Surveyor keeps walking straight past house A to reach house B — both share the
        // same "10/8 ZO 5" identity, so without special handling B's drop would read as a
        // continuation of A's cable instead of its own path back to the cabinet.
        $service->confirm($project, implode("\n", [
            '1  6549699.731  4923604.537  234.0  ZO 5',
            '2  6549703.323  4923595.954  234.0  Rov 10/8 ZO 5',
            '3  6549707.842  4923586.519  234.0  Rov 10/8 ZO 5',
            '4  6549708.500  4923585.000  234.0  Kuca A',
            '5  6549710.913  4923579.800  234.0  Rov 10/8 ZO 5',
            '6  6549712.000  4923577.000  234.0  Kuca B',
        ]), 'daisy.txt');

        $cabinet = Cabinet::where('project_id', $project->id)->firstOrFail();
        $houseA = House::where('project_id', $project->id)->where('label', 'Kuca t4')->firstOrFail();
        $houseB = House::where('project_id', $project->id)->where('label', 'Kuca t6')->firstOrFail();

        $dropA = NetworkRoute::where('project_id', $project->id)->where('to_id', $houseA->id)->first();
        $dropB = NetworkRoute::where('project_id', $project->id)->where('to_id', $houseB->id)->first();

        $this->assertNotNull($dropA);
        $this->assertNotNull($dropB);
        $this->assertSame('drop', $dropA->route_type);
        $this->assertSame('drop', $dropB->route_type);
        $this->assertSame($cabinet->id, $dropA->cabinet_id);
        $this->assertSame($cabinet->id, $dropB->cabinet_id);

        // B's path is the FULL run back to the cabinet (through A's tap point), not just
        // the leftover continuation after A — so it must be longer than A's own drop,
        // and neither route should have been merged into the other.
        $this->assertCount(3, $dropA->path);
        $this->assertCount(5, $dropB->path);
        $this->assertGreaterThan($dropA->duct_length_m, $dropB->duct_length_m);
        $this->assertNotSame($dropA->id, $dropB->id);
    }

    public function test_reserve_loop_gets_its_own_dedicated_microduct_without_becoming_a_house(): void
    {
        $project = Project::factory()->create();
        $service = app(SurveyPointImportService::class);

        // A reserve loop ("Slinga") sits between the cabinet and a house on the same 10/8
        // run — it should get its own microduct back to the cabinet (like a house would),
        // but must NOT become a House, and the house further down must still get its FULL
        // path (through the loop), not one starting at the loop.
        $service->confirm($project, implode("\n", [
            '1  6549699.731  4923604.537  234.0  ZO 5',
            '2  6549703.323  4923595.954  234.0  Rov 10/8 ZO 5',
            '3  6549707.842  4923586.519  234.0  Rov 10/8 ZO 5',
            '4  6549708.500  4923585.000  234.0  Slinga',
            '5  6549710.913  4923579.800  234.0  Rov 10/8 ZO 5',
            '6  6549712.000  4923577.000  234.0  Kuca B',
        ]), 'loop-drop.txt');

        $this->assertSame(1, House::where('project_id', $project->id)->count());
        $this->assertSame(1, ProjectAppendixItem::where('project_id', $project->id)->where('type', 'loop')->count());

        $house = House::where('project_id', $project->id)->firstOrFail();

        $houseDrop = NetworkRoute::where('project_id', $project->id)->where('to_type', 'house')->where('to_id', $house->id)->first();
        $loopDuct = NetworkRoute::where('project_id', $project->id)
            ->where('microduct_type', '10/8')
            ->where('id', '!=', $houseDrop?->id)
            ->first();

        $this->assertNotNull($loopDuct, 'the reserve loop should have its own dedicated microduct');
        $this->assertNotNull($houseDrop);
        $this->assertSame('drop', $houseDrop->route_type);
        // The house's drop is the FULL run through the loop, not just the leftover after it.
        $this->assertGreaterThan($loopDuct->duct_length_m, $houseDrop->duct_length_m);
    }

    public function test_clear_imported_data_removes_survey_rows_but_keeps_the_project(): void
    {
        $project = Project::factory()->create();
        $service = app(SurveyPointImportService::class);
        $service->confirm($project, $this->sampleContents(), 'snimak.txt');

        $this->assertGreaterThan(0, SurveyPoint::where('project_id', $project->id)->count());
        $this->assertGreaterThan(0, NetworkRoute::where('project_id', $project->id)->count());
        $this->assertGreaterThan(0, Cabinet::where('project_id', $project->id)->count());
        $this->assertGreaterThan(0, Odf::where('project_id', $project->id)->count());
        $this->assertGreaterThan(0, House::where('project_id', $project->id)->count());
        $this->assertGreaterThan(0, ProjectAppendixItem::where('project_id', $project->id)->count());

        $removed = $service->clearImportedData($project);

        $this->assertSame(0, SurveyPoint::where('project_id', $project->id)->count());
        $this->assertSame(0, NetworkRoute::where('project_id', $project->id)->count());
        $this->assertSame(0, Cabinet::where('project_id', $project->id)->count());
        $this->assertSame(0, Odf::where('project_id', $project->id)->count());
        $this->assertSame(0, House::where('project_id', $project->id)->count());
        $this->assertSame(0, ProjectAppendixItem::where('project_id', $project->id)->count());
        $this->assertGreaterThan(0, $removed['points']);
        $this->assertNotNull(Project::find($project->id), 'the project itself must survive');
    }

    public function test_clear_imported_data_never_touches_a_manually_drawn_route_that_import_later_extended(): void
    {
        $project = Project::factory()->create();
        $service = app(SurveyPointImportService::class);
        $transform = new GeoTransformService;

        // A route the USER drew by hand (no import_batch), covering the first half of what
        // will become the survey trench below.
        [$lat1, $lng1] = $transform->gaussKrugerToWgs84(6549699.731, 4923604.537, 6);
        [$lat2, $lng2] = $transform->gaussKrugerToWgs84(6549703.323, 4923595.954, 6);
        $manual = NetworkRoute::create([
            'project_id' => $project->id,
            'name' => 'Rucni rov',
            'route_type' => 'trench',
            'installation_type' => 'underground',
            'counts_as_trench' => true,
            'duct_length_m' => 10,
            'fiber_length_m' => 0,
            'fiber_count' => 0,
            'microduct_count' => 0,
            'microduct_type' => null,
            'status' => 'planned',
            'path' => [[$lat1, $lng1], [$lat2, $lng2]],
        ]);
        $this->assertNull($manual->import_batch);

        // The survey trench starts at the SAME point and continues — findExistingRouteGeometry
        // matches it to the manual route above and extends it via mergeTouchingPaths(), not a
        // fresh create().
        $service->confirm($project, $this->sampleContents(), 'snimak.txt');
        $manual->refresh();

        $this->assertNull($manual->import_batch, 'a route merely extended by import must stay untagged');
        $this->assertGreaterThan(2, count($manual->path), 'the merge should have grown the manual route');

        $service->clearImportedData($project);

        $this->assertNotNull(NetworkRoute::find($manual->id), 'the manually-drawn (now merged) route must survive clearImportedData()');
    }

    public function test_reimporting_the_same_file_succeeds_after_clearing(): void
    {
        $project = Project::factory()->create();
        $service = app(SurveyPointImportService::class);
        $service->confirm($project, $this->sampleContents(), 'snimak.txt');

        $service->clearImportedData($project);

        // No exception — the batch hash check no longer finds a matching survey_points row.
        $created = $service->confirm($project, $this->sampleContents(), 'snimak.txt');
        $this->assertGreaterThan(0, $created['points']);
    }

    public function test_bare_md_point_is_recognized_as_its_own_14_10_duct(): void
    {
        $service = app(SurveyPointImportService::class);

        // "MD" (the shared reserve duct) keeps running even where a later point only says
        // "Rov +MD" with no size restated — it must default to 14/10 and get its own line,
        // distinct from whichever colours happen to be riding along with it.
        $points = $service->parse(implode("\n", [
            '1  6549699.731  4923604.537  234.0  Rov +MD+ 14 mc -Ze+Pl',
            '2  6549703.323  4923595.954  234.0  Rov +MD+ 14 mc -Ze+Pl',
        ]));

        $this->assertSame('14/10', $points[0]['microduct_type']);
        $this->assertContains('MD', $points[0]['colors']);

        $labels = collect($service->buildNetwork($points)['ducts'])->pluck('label')->sort()->values()->all();
        $this->assertSame(['MC 14/10 MD', 'MC 14/10 Plava', 'MC 14/10 Zelena'], $labels);
    }

    public function test_a_real_branch_point_stays_three_separate_routes_not_one_fused_line(): void
    {
        $project = Project::factory()->create();
        $service = app(SurveyPointImportService::class);

        // A genuine Y-junction: the surveyor stands at the SAME point (J) three times,
        // walking out-and-back to record three different legs (1=3=5, all identical coords).
        // Welding must never fuse two of them into one straight-through line just because
        // they touch there — that would draw a physical connection that doesn't exist.
        $contents = implode("\n", [
            '1  6549703.323  4923595.954  234.0  Rov +14 Mc Plava', // J
            '2  6549710.000  4923590.000  234.0  Rov +14 Mc Plava', // leg B
            '3  6549703.323  4923595.954  234.0  Rov +14 Mc Plava', // back at J
            '4  6549700.000  4923585.000  234.0  Rov +14 Mc Plava', // leg C
            '5  6549703.323  4923595.954  234.0  Rov +14 Mc Plava', // back at J
            '6  6549712.000  4923600.000  234.0  Rov +14 Mc Plava', // leg D
        ]);

        $ducts = $service->buildNetwork($service->parse($contents))['ducts'];
        $this->assertCount(3, $ducts, 'the junction must produce three distinct legs, not one welded line');

        $service->confirm($project, $contents, 'junction.txt');
        // Persistence must preserve that — routes created in the SAME import are never
        // candidates for findExistingDuctRoute's cross-import continuation matching.
        $this->assertSame(
            3,
            NetworkRoute::where('project_id', $project->id)->where('name', 'like', 'MC 14/10 Plava%')->count()
        );
    }

    public function test_branch_that_jumps_a_few_metres_from_a_junction_still_reconnects(): void
    {
        $service = app(SurveyPointImportService::class);

        // Surveyor records the main "MD" run (1-2), walks off to record other branches
        // (not shown), then returns to within a few metres of point 1 — not close enough to
        // auto-merge as the same node (>1.5m) but clearly the same physical junction — to
        // record one more short leg (3-4). It must reconnect as one "MD" duct, not sit as a
        // disconnected 2-point island.
        $points = app(SurveyPointImportService::class)->parse(implode("\n", [
            '1  6549699.731  4923604.537  234.0  Rov +MD+ 14 mc -Ze',
            '2  6549680.000  4923604.537  234.0  Rov +MD+ 14 mc -Ze',
            '3  6549703.731  4923604.537  234.0  Rov +MD',
            '4  6549703.731  4923594.537  234.0  Rov +MD',
        ]));

        $md = collect($service->buildNetwork($points)['ducts'])->firstWhere('label', 'MC 14/10 MD');
        $this->assertNotNull($md);
        $this->assertCount(4, $md['path'], 'the jump-back leg must be welded onto the main run');

        // The "Zelena" riding along on points 1-2 only never had a leg near point 3-4, so it
        // must stay exactly as recorded — unaffected by the MD welding.
        $zelena = collect($service->buildNetwork($points)['ducts'])->firstWhere('label', 'MC 14/10 Zelena');
        $this->assertCount(2, $zelena['path']);
    }
}
