<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\ProjectAppendixItem;
use App\Models\SurveyPoint;
use App\Services\GeometryService;
use App\Services\GeoTransformService;
use App\Services\SurveyPointImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public function test_mobile_field_point_stores_gps_metadata_photo_and_private_photo_access(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $session = (string) Str::uuid();
        $photo = UploadedFile::fake()->createWithContent(
            'rov.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
        );

        $response = $this->postJson(route('projects.field-points.store', $project), [
            'session_uuid' => $session,
            'latitude' => 44.4493123,
            'longitude' => 18.6498456,
            'accuracy_m' => 2.4,
            'kind' => 'trench',
            'code' => 'Rov T-01',
            'note' => 'Početak planiranog rova uz cestu.',
            'captured_at' => now()->toISOString(),
            'photo' => $photo,
        ])->assertCreated()->assertJsonPath('point.sequence', 1)->assertJsonPath('point.has_photo', true);

        $point = SurveyPoint::findOrFail($response->json('point.id'));
        $this->assertSame('gps', $point->source);
        $this->assertSame($session, $point->session_uuid);
        $this->assertSame('Rov T-01', $point->code);
        $this->assertEqualsWithDelta(2.4, $point->accuracy_m, 0.01);
        Storage::disk('local')->assertExists($point->photo_path);

        $this->get(route('projects.field-points.photo', [$project, $point]))->assertOk();
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

    public function test_missing_colours_bypass_a_blue_only_cabinet_spur_and_rejoin_after_it(): void
    {
        $service = app(SurveyPointImportService::class);
        $points = $service->parse(implode("\n", [
            '1  6549699.731  4923604.537  234.0  Rov 14/10 Plava + Zelena + Zuta',
            '2  6549708.000  4923595.954  234.0  Rov 14/10 Plava',
            '3  6549707.842  4923586.519  234.0  Rov 14/10 Plava + Zelena + Zuta',
        ]));

        $ducts = collect($service->buildNetwork($points)['ducts']);
        $green = $ducts->firstWhere('color', 'Zelena');
        $blue = $ducts->firstWhere('color', 'Plava');
        $yellow = $ducts->firstWhere('color', 'Zuta');

        $this->assertNotNull($green);
        $this->assertNotNull($blue);
        $this->assertNotNull($yellow);
        $this->assertCount(2, $green['path']);
        $this->assertCount(3, $blue['path']);
        $this->assertCount(2, $yellow['path']);
    }

    public function test_survey_walk_back_to_older_junction_does_not_connect_two_customer_branch_ends(): void
    {
        $service = app(SurveyPointImportService::class);
        $points = $service->parse(implode("\n", [
            '59 6536461.736 4918896.400 397.481 Rov 10/8 -ZO 3.2',
            '60 6536459.154 4918894.534 397.484 Rov 10/8 -ZO 3.2',
            '61 6536457.566 4918893.815 397.479 Rov 10/8 -ZO 3.2',
            '62 6536455.908 4918894.701 397.392 Rov 10/8 -ZO 3.2',
            '63 6536453.999 4918895.134 397.097 Rov 10/8 -ZO 3.2',
            '64 6536448.207 4918896.834 396.481 Rov 10/8 -ZO 3.2',
            '65 6536448.182 4918896.805 396.562 Rov 10/8 -ZO 3.2',
            '66 6536445.659 4918897.535 396.372 Rov 10/8 -ZO 3.2',
            // Surveyor walks back beside point 61 and starts another branch.
            '67 6536456.389 4918893.128 397.444 Rov 10/8 -ZO 3.2',
            '68 6536453.290 4918891.228 397.411 Rov 10/8 -ZO 3.2',
            '69 6536450.844 4918889.133 397.541 Rov 10/8 -ZO 3.2',
        ]));

        $ducts = $service->buildNetwork($points)['ducts'];
        $falseA = [$points[7]['lat'], $points[7]['lng']];
        $falseB = [$points[8]['lat'], $points[8]['lng']];
        $hasFalseSegment = collect($ducts)->contains(function (array $duct) use ($falseA, $falseB): bool {
            for ($i = 1; $i < count($duct['path']); $i++) {
                $a = $duct['path'][$i - 1];
                $b = $duct['path'][$i];
                if (($a === $falseA && $b === $falseB) || ($a === $falseB && $b === $falseA)) {
                    return true;
                }
            }

            return false;
        });

        $this->assertFalse($hasFalseSegment, 'walk-back between two branch ends must not become cable');
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
        $this->assertSame('ZO-3', Cabinet::where('project_id', $project->id)->value('name'));

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

        // The proven drop topology is also persisted on the house so validators, reports
        // and capacity calculations agree with the route drawn to ZO-3.
        $this->assertNotNull($house);
        $this->assertSame($cabinet->id, $house->fresh()->cabinet_id);

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
        $zo1 = Cabinet::where('project_id', $project->id)->where('name', 'ZO-1')->firstOrFail();
        $zo2 = Cabinet::where('project_id', $project->id)->where('name', 'ZO-2')->firstOrFail();

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
        // The final ZO coordinate is now included as well, not merely the nearest rov point.
        $this->assertGreaterThanOrEqual(2, count($dropA->path));
        $this->assertGreaterThanOrEqual(2, count($dropB->path));
        $this->assertNotSame($dropA->id, $dropB->id);
    }

    public function test_customer_drop_is_completed_over_unmarked_shared_trench_to_named_cabinet(): void
    {
        $service = app(SurveyPointImportService::class);
        $points = $service->parse(implode("\n", [
            '1  6549699.731  4923604.537  234.0  ZO 7',
            '2  6549703.323  4923595.954  234.0  Rov',
            '3  6549707.842  4923586.519  234.0  Rov',
            '4  6549710.913  4923579.800  234.0  Rov',
            '5  6549713.000  4923576.000  234.0  Kuca H-7 10/8 ZO 7',
        ]));

        $network = $service->buildNetwork($points);
        $drop = collect($network['ducts'])->firstWhere('microduct_type', '10/8');

        $this->assertNotNull($drop);
        $this->assertSame('7', $drop['zo_tag']);
        $this->assertCount(5, $drop['path']);
        $this->assertTrue($drop['routed_via_trench'] ?? false);
        $this->assertTrue($drop['cabinet_reached'] ?? false);
    }

    public function test_every_customer_route_runs_from_its_house_to_its_cabinet_not_to_another_house(): void
    {
        $service = app(SurveyPointImportService::class);
        $points = $service->parse(file_get_contents(base_path('tests/Fixtures/survey/demo-feature-koordinate.txt')));
        $drops = collect($service->buildNetwork($points)['ducts'])
            ->where('microduct_type', '10/8')
            ->filter(fn (array $duct) => isset($duct['_terminal_point']))
            ->values();
        $terminals = collect($points)->whereIn('kind', ['sling', 'loop'])->keyBy('point_no');
        $cabinets = collect($points)->where('kind', 'cabinet');

        $this->assertCount(4, $drops);
        foreach ($drops as $drop) {
            $terminal = $terminals->get($drop['_terminal_point']);
            $cabinet = $cabinets->first(fn (array $point) => $point['zo_tag'] === $drop['zo_tag']);
            $this->assertNotNull($terminal);
            $this->assertNotNull($cabinet);
            $this->assertTrue($drop['cabinet_reached'] ?? false);
            $this->assertLessThanOrEqual(1.5, app(GeometryService::class)->distanceMeters(
                $terminal['lat'], $terminal['lng'], $drop['path'][0][0], $drop['path'][0][1]
            ));
            $this->assertLessThanOrEqual(0.5, app(GeometryService::class)->distanceMeters(
                $cabinet['lat'], $cabinet['lng'], end($drop['path'])[0], end($drop['path'])[1]
            ));
        }
    }

    public function test_long_jump_after_customer_terminal_starts_a_new_walk_instead_of_connecting_houses(): void
    {
        $service = app(SurveyPointImportService::class);
        $points = $service->parse(implode("\n", [
            '1385 6539961.328 4927455.108 372.943 Rov+ mc 10/8-Z7',
            '1386 6539960.358 4927455.044 373.055 Rov+ mc 10/8-Z7',
            '1387 6539947.804 4927442.894 372.680 Rov+ mc 10/8-Z7-Slinga x 3',
            // Surveyor moved about 28 m and began another branch. This is not cable.
            '1388 6539938.225 4927416.193 370.012 Rov+ mc 10/8-Z7',
            '1389 6539938.566 4927416.825 370.055 Rov+ mc 10/8-Z7',
        ]));
        $network = $service->buildNetwork($points);
        $byNumber = collect($points)->keyBy('point_no');
        $from = [$byNumber[1387]['lat'], $byNumber[1387]['lng']];
        $to = [$byNumber[1388]['lat'], $byNumber[1388]['lng']];

        $hasFalseEdge = collect($network['ducts'])->contains(function (array $duct) use ($from, $to): bool {
            for ($index = 1; $index < count($duct['path']); $index++) {
                $a = $duct['path'][$index - 1];
                $b = $duct['path'][$index];
                if (($a === $from && $b === $to) || ($a === $to && $b === $from)) {
                    return true;
                }
            }

            return false;
        });

        $this->assertFalse($hasFalseEdge, 'T1387 se ne smije spojiti direktno na novi krak T1388.');
    }

    public function helper_explicit_customer_duct_edges_after_a_terminal_are_never_dropped(): void
    {
        $service = app(SurveyPointImportService::class);
        $makePoint = function (int $number, float $lng, string $code) use ($service): array {
            return $service->classify($code) + [
                'point_no' => $number, 'x' => 0.0, 'y' => 0.0, 'z' => 0.0,
                'lat' => 43.0, 'lng' => $lng, 'code' => $code,
            ];
        };
        $points = [
            $makePoint(1, 18.00000, 'Rov mc 10/8 ZO 7'),
            $makePoint(2, 18.00005, 'Rov mc 10/8 ZO 7 Slinga'),
            $makePoint(3, 18.00010, 'Rov mc 10/8 ZO 7'),
            $makePoint(4, 18.00015, 'Rov mc 10/8 ZO 7'),
        ];
        $network = $service->buildNetwork($points);
        $ducts = collect($network['ducts'])->where('microduct_type', '10/8');

        foreach ($network['trenches'] as $trench) {
            foreach ($trench['path'] as $vertex) {
                $covered = $ducts->contains(fn (array $duct) => app(GeometryService::class)
                    ->distanceToRoute($vertex[0], $vertex[1], $duct['path']) <= 1.5);
                $this->assertTrue($covered, 'Eksplicitna 10/8 tačka ne smije ostati samo rov bez mikrocijevi.');
            }
        }
    }

    public function test_customer_route_uses_the_connected_trench_graph_instead_of_source_walk_detours(): void
    {
        $service = app(SurveyPointImportService::class);
        $makePoint = function (int $number, float $lat, float $lng, string $code) use ($service): array {
            return $service->classify($code) + [
                'point_no' => $number, 'x' => 0.0, 'y' => 0.0, 'z' => 0.0,
                'lat' => $lat, 'lng' => $lng, 'code' => $code,
            ];
        };
        $house = [43.0, 18.0000];
        $cabinet = [43.0, 18.0030];
        $points = [
            $makePoint(1, 43.00005, 18.00005, 'Rov mc 10/8 ZO 7'),
            $makePoint(2, $house[0], $house[1], 'Rov mc 10/8 ZO 7 Slinga'),
        ];
        $mainTrench = [[
            $house,
            [43.0, 18.0010],
            [43.0, 18.0020],
            $cabinet,
        ]];
        $cabinetPoint = [[
            'kind' => 'cabinet', 'code' => 'ZO 7', 'lat' => $cabinet[0], 'lng' => $cabinet[1],
        ]];

        $drop = collect($service->buildNetwork($points, $cabinetPoint, $mainTrench)['ducts'])
            ->firstWhere('microduct_type', '10/8');

        $this->assertNotNull($drop);
        $this->assertSame($house, $drop['path'][0]);
        $this->assertSame($cabinet, end($drop['path']));
        $this->assertFalse(collect($drop['path'])->contains(fn (array $point) => app(GeometryService::class)
            ->distanceMeters(43.00005, 18.00005, $point[0], $point[1]) <= 0.1), json_encode($drop['path']));
        $this->assertTrue($drop['cabinet_reached']);
    }

    public function test_customer_routes_start_at_unique_houses_merge_on_the_trench_and_end_in_zo(): void
    {
        $service = app(SurveyPointImportService::class);
        $makePoint = function (int $number, float $lat, float $lng, string $code) use ($service): array {
            return $service->classify($code) + [
                'point_no' => $number,
                'x' => 0.0,
                'y' => 0.0,
                'z' => 0.0,
                'lat' => $lat,
                'lng' => $lng,
                'code' => $code,
            ];
        };
        $points = [
            $makePoint(1, 43.0, 18.003, 'ZO 5'),
            $makePoint(2, 42.9999, 18.0000, 'Rov mc 10/8 ZO 5 Slinga'),
            $makePoint(3, 42.9999, 18.0012, 'Rov mc 10/8 ZO 5 Slinga'),
            $makePoint(4, 42.9999, 18.0024, 'Rov mc 10/8 ZO 5 Slinga'),
            // Duplicate field shot of the third physical house must not create a fourth route.
            $makePoint(5, 42.9999001, 18.0024001, 'Rov mc 10/8 ZO 5 Slinga'),
        ];
        $mainTrench = [[
            [43.0, 18.0000],
            [43.0, 18.0006],
            [43.0, 18.0012],
            [43.0, 18.0018],
            [43.0, 18.0024],
            [43.0, 18.0030],
        ]];

        $drops = collect($service->buildNetwork($points, [], $mainTrench)['ducts'])
            ->where('microduct_type', '10/8')
            ->values();

        $this->assertCount(3, $drops);
        $this->assertTrue($drops->every(fn (array $drop) => isset($drop['_terminal_point'])));
        $this->assertTrue($drops->every(fn (array $drop) => count($drop['path']) >= 3));
        $this->assertTrue($drops->every(fn (array $drop) => $drop['routed_via_trench'] ?? false));
        $this->assertTrue($drops->every(fn (array $drop) => end($drop['path']) === [43.0, 18.003]));

        $terminals = collect($points)->whereIn('point_no', [2, 3, 4, 5]);
        foreach ($drops as $drop) {
            $own = $terminals->firstWhere('point_no', (int) $drop['_terminal_point']);
            $this->assertNotNull($own);
            $this->assertSame([$own['lat'], $own['lng']], $drop['path'][0]);

            foreach ($terminals as $other) {
                $samePhysicalHouse = app(GeometryService::class)->distanceMeters(
                    $own['lat'], $own['lng'], $other['lat'], $other['lng']
                ) <= 0.5;
                if ($samePhysicalHouse) {
                    continue;
                }
                $passesOtherHouse = collect($drop['path'])->contains(fn (array $coordinate) => app(GeometryService::class)->distanceMeters(
                    $other['lat'], $other['lng'], $coordinate[0], $coordinate[1]
                ) <= 0.1
                );
                $this->assertFalse(
                    $passesOtherHouse,
                    "Ruta T{$drop['_terminal_point']} ne smije prolaziti kroz kucu T{$other['point_no']}."
                );
            }
        }
    }

    public function test_named_slinga_stops_at_prepared_point_but_binds_route_to_future_house(): void
    {
        $project = Project::factory()->create();
        $contents = implode("\n", [
            '1  6549699.731  4923604.537  234.0  ZO 8',
            '2  6549703.323  4923595.954  234.0  Rov',
            '3  6549707.842  4923586.519  234.0  Rov',
            '4  6549710.913  4923579.800  234.0  Rov',
            '5  6549713.000  4923576.000  234.0  SLINGA za kucu H-12 10/8 ZO 8',
        ]);

        $points = app(SurveyPointImportService::class)->parse($contents);
        $this->assertTrue($points[4]['prepared_sling']);
        $this->assertSame('H-12', $points[4]['house_ref']);

        app(SurveyPointImportService::class)->confirm($project, $contents, 'slinga.txt');
        $house = House::where('project_id', $project->id)->where('label', 'H-12')->firstOrFail();
        $drop = NetworkRoute::where('project_id', $project->id)->where('to_id', $house->id)->firstOrFail();

        $this->assertNull($house->latitude); // stvarna lokacija kuce jos nije snimljena
        $this->assertSame('drop', $drop->route_type);
        $this->assertStringContainsString('SLINGA za kucu H-12', $drop->note);
        $dropPath = $drop->path;
        $terminalDistance = min(
            app(GeometryService::class)->distanceMeters($points[4]['lat'], $points[4]['lng'], $dropPath[0][0], $dropPath[0][1]),
            app(GeometryService::class)->distanceMeters($points[4]['lat'], $points[4]['lng'], end($dropPath)[0], end($dropPath)[1]),
        );
        $this->assertLessThanOrEqual(1.5, $terminalDistance);
    }

    public function test_autocad_green_cabinet_callout_is_recognized_as_tagged_cabinet(): void
    {
        $point = app(SurveyPointImportService::class)->parse(
            '398 6549699.731 4923604.537 234.0 ZELENA ORMARICA BR. 7'
        )[0];

        $this->assertSame('cabinet', $point['kind']);
        $this->assertSame('7', $point['zo_tag']);
    }

    public function test_real_field_notation_recognizes_z_ormar_short_tags_and_unnamed_slinga(): void
    {
        $points = app(SurveyPointImportService::class)->parse(implode("\n", [
            '2675 6550341.460 4923900.886 212.390 Rov+ mc 14/10',
            '2676 6550339.358 4923897.137 212.502 Rov+ mc 10/8 Slinga',
            '2704 6550284.829 4923791.218 219.438 Rov+ mc 10/8+ 14/10 Slinga Crvene',
            '2711 6550189.167 4921055.951 260.567 Z Ormar 1',
            '2725 6550195.958 4921144.237 257.932 Rov+ mc 10/8 -Z1',
        ]));

        $this->assertSame('sling', $points[1]['kind']);
        $this->assertTrue($points[1]['prepared_sling']);
        $this->assertSame('T2676', $points[1]['house_ref']);
        $this->assertSame(['10/8', '14/10'], collect($points[2]['duct_identities'])->pluck('type')->sort()->values()->all());
        $this->assertSame('T2704', $points[2]['house_ref']);
        $this->assertSame('cabinet', $points[3]['kind']);
        $this->assertSame('1', $points[4]['zo_tag']);
    }

    public function test_bare_z_number_is_a_green_cabinet_without_renaming_route_tags(): void
    {
        $points = app(SurveyPointImportService::class)->parse(implode("\n", [
            '1 6539066.246 4926447.735 274.589 Z1',
            '2 6539076.821 4926512.629 281.341 Z 2',
            '3 6539077.058 4926512.506 281.153 Rov + mc 10/8 -Z1',
        ]));

        $this->assertSame('cabinet', $points[0]['kind']);
        $this->assertSame('Z1', $points[0]['code']);
        $this->assertSame('1', $points[0]['zo_tag']);
        $this->assertSame('cabinet', $points[1]['kind']);
        $this->assertSame('Z 2', $points[1]['code']);
        $this->assertSame('trench', $points[2]['kind']);
        $this->assertSame('1', $points[2]['zo_tag']);
    }

    public function test_field_fi_14_notation_creates_coloured_microducts_with_the_recorded_count(): void
    {
        $service = app(SurveyPointImportService::class);
        $points = $service->parse(implode("\n", [
            '1 6539061.263 4926435.457 272.990 Rov + 5x fi 14 Zelena X2 i Plava x2 I Zuta',
            '2 6539060.629 4926434.435 272.843 Rov + 5x fi 14 Zelena X2 i Plava x2 I Zuta',
            '3 6539058.236 4926430.871 272.451 Rov + 3 fi 14 Zelena Plava i Zuta',
        ]));

        $this->assertSame('14/10', $points[0]['microduct_type']);
        $this->assertSame(5, $points[0]['microduct_count']);
        $this->assertSame(3, $points[2]['microduct_count']);
        $this->assertEqualsCanonicalizing(['Zelena', 'Plava', 'Zuta'], $points[0]['colors']);
        $this->assertSame(['Zelena' => 2, 'Plava' => 2, 'Zuta' => 1], $points[0]['color_counts']);
        $this->assertNotEmpty($service->buildNetwork($points)['ducts']);
    }

    public function test_total_bundle_count_does_not_invent_unlabelled_duct_routes(): void
    {
        $service = app(SurveyPointImportService::class);
        $points = $service->parse(implode("\n", [
            '1 6539061.263 4926435.457 272.990 Rov + 5x fi 14 Zelena Plava',
            '2 6539065.629 4926428.435 272.843 Rov + 5x fi 14 Zelena Plava',
        ]));

        $ducts = collect($service->buildNetwork($points)['ducts']);

        $this->assertSame(5, $points[0]['microduct_count']);
        $this->assertSame(['Zelena' => 1, 'Plava' => 1], $points[0]['color_counts']);
        $this->assertCount(2, $ducts);
        $this->assertFalse($ducts->contains(fn (array $duct) => $duct['color'] === null));
    }

    public function test_count_transition_snaps_a_sub_half_metre_gap_to_an_exact_shared_node(): void
    {
        $service = app(SurveyPointImportService::class);
        $ducts = [
            [
                'microduct_type' => '14/10',
                'microduct_count' => 2,
                'color' => 'Zelena',
                'path' => [[43.0, 18.0], [43.0, 18.001]],
                'length_m' => 0.0,
            ],
            [
                'microduct_type' => '14/10',
                'microduct_count' => 1,
                'color' => 'Zelena',
                'path' => [[43.0000027, 18.0005], [43.001, 18.0005]],
                'length_m' => 0.0,
            ],
        ];

        $method = new \ReflectionMethod($service, 'connectColoredCountTransitions');
        $connected = $method->invoke($service, $ducts, [], 10.0);
        $junction = $connected[1]['path'][0];

        $this->assertContains($junction, $connected[0]['path'], true);
        $this->assertSame([43.0, 18.0005], $junction);
        $this->assertCount(2, $connected[1]['path'], 'Spajanje treba pomjeriti kraj, a ne dodati kratki lažni segment.');
        $this->assertGreaterThan(0, $connected[0]['length_m']);
        $this->assertGreaterThan(0, $connected[1]['length_m']);
    }

    public function test_corrected_real_field_file_builds_cabinets_and_all_three_mc_colours(): void
    {
        $service = app(SurveyPointImportService::class);
        $points = $service->parse(file_get_contents(base_path('tests/Fixtures/survey/uredjen-i-ispravljen-opis.txt')));
        $network = $service->buildNetwork($points);

        $missingPointNumbers = array_values(array_diff(range(1753, 2113), collect($points)->pluck('point_no')->all()));
        $this->assertSame([], $missingPointNumbers, 'Parser je preskocio tacke: '.implode(', ', $missingPointNumbers));
        $this->assertCount(361, $points);
        $this->assertCount(11, collect($points)->where('kind', 'cabinet'));
        $lowerBranchOrder = collect($points)
            ->filter(fn (array $point) => $point['point_no'] >= 2065 && $point['point_no'] <= 2069)
            ->pluck('point_no')
            ->all();
        $this->assertSame([2069, 2068, 2067, 2066, 2065], $lowerBranchOrder);

        $byNumber = collect($points)->keyBy('point_no');
        $coordinate = fn (int $number): array => [
            $byNumber[$number]['lat'],
            $byNumber[$number]['lng'],
        ];
        $hasEdge = function (array $from, array $to) use ($network): bool {
            foreach ($network['trenches'] as $trench) {
                for ($index = 1; $index < count($trench['path']); $index++) {
                    if (($trench['path'][$index - 1] === $from && $trench['path'][$index] === $to)
                        || ($trench['path'][$index - 1] === $to && $trench['path'][$index] === $from)) {
                        return true;
                    }
                }
            }

            return false;
        };
        $this->assertTrue($hasEdge($coordinate(1760), $coordinate(1761)), '2x krak mora nastaviti od zajednickog snopa.');
        $this->assertTrue($hasEdge($coordinate(1760), $coordinate(2069)), '3x krak mora krenuti iz istog racvanja.');

        $odf = array_map(fn (float $value) => round($value, 7), $coordinate(1753));
        $anchoredByColor = collect($network['ducts'])
            ->where('microduct_type', '14/10')
            ->filter(fn (array $duct) => $duct['path'][0] === $odf || end($duct['path']) === $odf)
            ->groupBy('color');
        $this->assertCount(2, $anchoredByColor->get('Zelena', collect()), 'Obje zelene moraju krenuti direktno iz ODF-a.');
        $this->assertCount(2, $anchoredByColor->get('Plava', collect()), 'Obje plave moraju krenuti direktno iz ODF-a.');
        $this->assertCount(1, $anchoredByColor->get('Zuta', collect()), 'Zuta mora krenuti direktno iz ODF-a.');
        $this->assertTrue($anchoredByColor->flatten(1)->every(fn (array $duct) => $duct['microduct_count'] === 1));

        $geometry = app(GeometryService::class);
        $yellowHasArtificialJump = collect($network['ducts'])
            ->where('color', 'Zuta')
            ->contains(function (array $duct) use ($geometry): bool {
                for ($index = 1; $index < count($duct['path']); $index++) {
                    if ($geometry->distanceBetweenPoints($duct['path'][$index - 1], $duct['path'][$index]) > 20.0) {
                        return true;
                    }
                }

                return false;
            });
        $this->assertFalse($yellowHasArtificialJump, 'Zuta ne smije praviti precice izmedju udaljenih krakova.');
        $this->assertNotEmpty($network['trenches']);
        $this->assertNotEmpty($network['ducts']);
        $this->assertEqualsCanonicalizing(
            ['Plava', 'Zelena', 'Zuta'],
            collect($network['ducts'])->pluck('color')->filter()->unique()->sort()->values()->all()
        );
        $fourteen = collect($network['ducts'])->where('microduct_type', '14/10');
        $odfPoint = collect($points)->firstWhere('kind', 'odf');
        $odfCoordinate = [round((float) $odfPoint['lat'], 7), round((float) $odfPoint['lng'], 7)];
        foreach (['Zelena', 'Plava'] as $color) {
            $anchored = $fourteen->where('color', $color)->filter(function (array $duct) use ($odfCoordinate): bool {
                return $duct['path'][0] === $odfCoordinate || end($duct['path']) === $odfCoordinate;
            });
            $this->assertCount(2, $anchored, "$color x2 mora dati dvije zasebne dionice direktno iz ODF-a.");
            $this->assertTrue($anchored->every(fn (array $duct) => $duct['microduct_count'] === 1));
            $this->assertFalse($anchored->contains(fn (array $duct) => $duct['path'][0] === end($duct['path'])), "$color ne smije napraviti petlju ODF→ODF.");
        }
    }

    public function test_clean_gps_example_uses_only_supplied_points_and_builds_the_expected_network(): void
    {
        $service = app(SurveyPointImportService::class);
        $points = $service->parse(file_get_contents(base_path('tests/Fixtures/survey/test-gps-odf-1753-2113.txt')));
        $network = $service->buildNetwork($points);

        $this->assertCount(359, $points);
        $this->assertSame([1861, 1862], array_values(array_diff(
            range(1753, 2113),
            collect($points)->pluck('point_no')->all()
        )));
        $this->assertCount(1, collect($points)->where('kind', 'odf'));
        $this->assertCount(11, collect($points)->where('kind', 'cabinet'));
        $this->assertNotEmpty($network['trenches']);
        $this->assertNotEmpty($network['ducts']);
        $this->assertEqualsCanonicalizing(
            ['Plava', 'Zelena', 'Zuta'],
            collect($network['ducts'])->pluck('color')->filter()->unique()->values()->all()
        );
    }

    public function test_adjusted_four_cabinet_manual_test_file_has_numbered_cabinets_and_tagged_customer_lines(): void
    {
        $points = app(SurveyPointImportService::class)->parse(
            file_get_contents(base_path('tests/Fixtures/survey/test-4-ormara.txt'))
        );

        $this->assertCount(148, $points);
        $this->assertSame(
            ['Z Ormar 1', 'Z Ormar 2', 'Z Ormar 3', 'Z Ormar 4'],
            collect($points)->where('kind', 'cabinet')->pluck('code')->values()->all()
        );
        $customerPoints = collect($points)->filter(fn (array $point) => in_array($point['kind'], ['trench', 'sling'], true)
            && ($point['microduct_type'] === '10/8' || collect($point['duct_identities'])->contains('type', '10/8')));
        $this->assertNotEmpty($customerPoints);
        $this->assertFalse($customerPoints->contains(fn (array $point) => $point['zo_tag'] === null));
        $this->assertSame(6, collect($points)->where('prepared_sling', true)->count());
    }

    public function test_generated_complete_four_cabinet_network_has_segmented_working_duct_and_continuous_reserve(): void
    {
        $service = app(SurveyPointImportService::class);
        $points = $service->parse(file_get_contents(base_path('tests/Fixtures/survey/test-4-ormara-kompletna-mreza.txt')));
        $network = $service->buildNetwork($points);
        $ducts = collect($network['ducts']);

        $this->assertSame(
            ['Z Ormar 1', 'Z Ormar 2', 'Z Ormar 3', 'Z Ormar 4'],
            collect($points)->where('kind', 'cabinet')->pluck('code')->values()->all()
        );
        $this->assertSame(3, collect($points)->where('kind', 'splice')->count());
        $this->assertSame(4, collect($points)->where('prepared_sling', true)->count());

        $working = $ducts->where('microduct_type', '14/10')->where('color', 'Crvena');
        $this->assertSame(['2', '3', '4'], $working->pluck('zo_tag')->sort()->values()->all());
        $this->assertCount(1, $ducts->where('microduct_type', '14/10')->where('color', 'Plava'));

        $drops = $ducts->where('microduct_type', '10/8');
        $this->assertSame(['1', '2', '3', '4'], $drops->pluck('zo_tag')->sort()->values()->all());
        $this->assertSame(['H-1', 'H-2', 'H-3', 'H-4'], $drops->pluck('house_ref')->sort()->values()->all());
    }

    public function test_normalized_real_network_has_unique_cabinets_and_destination_aware_implicit_drops(): void
    {
        $service = app(SurveyPointImportService::class);
        $points = $service->parse(file_get_contents(base_path('tests/Fixtures/survey/test-normalizovana-pametna-mreza.txt')));

        $this->assertGreaterThan(350, count($points));
        $this->assertCount(9, collect($points)->where('kind', 'cabinet'));
        $slings = collect($points)->where('kind', 'sling');
        $this->assertGreaterThan(25, $slings->count());
        $this->assertFalse($slings->contains(fn (array $point) => $point['zo_tag'] === null));

        $network = $service->buildNetwork($points);
        $drops = collect($network['ducts'])->where('microduct_type', '10/8');
        $uniqueSlings = [];
        foreach ($slings as $sling) {
            $duplicate = collect($uniqueSlings)->contains(fn (array $kept) => $kept['zo_tag'] === $sling['zo_tag']
                && app(GeometryService::class)->distanceMeters(
                    $kept['lat'], $kept['lng'], $sling['lat'], $sling['lng']
                ) <= 1.5);
            if (! $duplicate) {
                $uniqueSlings[] = $sling;
            }
        }
        $this->assertGreaterThanOrEqual(count($uniqueSlings), $drops->count());
        $this->assertEmpty(collect($uniqueSlings)->pluck('house_ref')->diff($drops->pluck('house_ref')->filter())->values()->all());

        $t309 = $drops->firstWhere('house_ref', 'T309');
        $this->assertNotNull($t309);
        $this->assertTrue($t309['routed_via_trench'] ?? false, json_encode($t309));
        $zo4 = collect($points)->first(fn (array $point) => $point['kind'] === 'cabinet' && $point['code'] === 'Z Ormar 4');
        $this->assertNotNull($zo4);
        $this->assertGreaterThan(20.0, $t309['length_m'], json_encode($t309));
        $this->assertLessThanOrEqual(30.0, min(...array_map(
            fn (array $endpoint) => app(GeometryService::class)->distanceMeters($zo4['lat'], $zo4['lng'], $endpoint[0], $endpoint[1]),
            [$t309['path'][0], end($t309['path'])]
        )), json_encode($t309));
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
        $this->assertNotSame($loopDuct->id, $houseDrop->id);
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

    public function test_one_txt_import_can_be_deleted_without_touching_another_import(): void
    {
        $project = Project::factory()->create();
        $batchA = str_repeat('a', 40);
        $batchB = str_repeat('b', 40);
        foreach ([[$batchA, 'glavna.txt', 1], [$batchB, 'korisnici.txt', 2]] as [$batch, $file, $number]) {
            SurveyPoint::create([
                'project_id' => $project->id, 'import_batch' => $batch, 'source_file' => $file,
                'point_no' => $number, 'x' => 6549000 + $number, 'y' => 4923000 + $number,
                'z' => 200, 'latitude' => 44.4 + $number / 1000, 'longitude' => 18.5,
                'code' => 'Rov', 'kind' => 'trench',
            ]);
            NetworkRoute::create([
                'project_id' => $project->id, 'name' => "Rov {$number}", 'route_type' => 'trench',
                'installation_type' => 'underground', 'counts_as_trench' => true,
                'duct_length_m' => 10, 'fiber_length_m' => 0, 'fiber_count' => 0,
                'microduct_count' => 0, 'status' => 'planned',
                'path' => [[44.4 + $number / 1000, 18.5], [44.401 + $number / 1000, 18.5]],
                'import_batch' => $batch,
            ]);
        }

        $service = app(SurveyPointImportService::class);
        $this->assertCount(2, $service->importedBatches($project));
        $removed = $service->clearImportedBatch($project, $batchB);

        $this->assertSame(1, $removed['points']);
        $this->assertFalse(SurveyPoint::where('project_id', $project->id)->where('import_batch', $batchB)->exists());
        $this->assertFalse(NetworkRoute::where('project_id', $project->id)->where('import_batch', $batchB)->exists());
        $this->assertTrue(SurveyPoint::where('project_id', $project->id)->where('import_batch', $batchA)->exists());
        $this->assertTrue(NetworkRoute::where('project_id', $project->id)->where('import_batch', $batchA)->exists());
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

    public function test_transit_colour_stays_unassigned_while_serial_colour_is_split_by_zo_tags(): void
    {
        $project = Project::factory()->create();
        $service = app(SurveyPointImportService::class);
        $contents = implode("\n", [
            '1 6539000.000 4926000.000 250.000 Rov; 14/10 Zelena Tranzit; 14/10 Plava ZO-1',
            '2 6539005.000 4926000.000 250.000 Rov; 14/10 Zelena Tranzit; 14/10 Plava ZO-1',
            '3 6539005.500 4926000.500 250.000 ZO-1',
            '4 6539010.000 4926000.000 250.000 Rov; 14/10 Zelena Tranzit; 14/10 Plava ZO-2',
            '5 6539015.000 4926000.000 250.000 Rov; 14/10 Zelena Tranzit; 14/10 Plava ZO-2',
            '6 6539015.500 4926000.500 250.000 ZO-2',
        ]);

        $preview = $service->preview($project, $contents, 'serijska-plava.txt');
        $green = collect($preview['ducts'])->firstWhere('color', 'Zelena');
        $blueTags = collect($preview['ducts'])->where('color', 'Plava')->pluck('zo_tag')->sort()->values()->all();

        $this->assertNotNull($green);
        $this->assertNull($green['matched_cabinet_id']);
        $this->assertSame('none', $green['match_confidence']);
        $this->assertSame(['1', '2'], $blueTags);
        $this->assertNotContains('ambiguous', collect($preview['ducts'])->pluck('match_confidence')->all());

        $points = $service->parse($contents);
        $network = $service->buildNetwork($points);
        $zo1 = collect($points)->firstWhere('code', 'ZO-1');
        $zo1Coordinate = [round((float) $zo1['lat'], 7), round((float) $zo1['lng'], 7)];
        $blueThroughZo1 = collect($network['ducts'])
            ->where('color', 'Plava')
            ->filter(fn (array $duct) => in_array($zo1Coordinate, $duct['path'], true));

        $this->assertCount(2, $blueThroughZo1, 'Plava prema ZO-1 mora uci u ormar, a naredna Plava krenuti iz njega.');
        $greenPath = collect($network['ducts'])->firstWhere('color', 'Zelena')['path'];
        $this->assertNotContains($zo1Coordinate, $greenPath, 'Tranzitna Zelena ne smije skretati u ormar.');
    }

    public function test_approximate_terminal_readings_for_one_house_create_only_one_drop_route(): void
    {
        $project = Project::factory()->create();
        $contents = implode("\n", [
            '1 6549699.731 4923604.537 234.000 ZO-1',
            '2 6549700.500 4923604.537 234.000 Rov + mc 10/8 Crvena x1 -ZO-1',
            '3 6549704.000 4923604.537 234.000 Kuca 10/8 Crvena x1 -ZO-1',
            '4 6549705.800 4923604.537 234.000 Kuca 10/8 Crvena x1 -ZO-1',
        ]);

        app(SurveyPointImportService::class)->confirm($project, $contents, 'dupli-zavrsetak.txt');

        $this->assertSame(1, House::where('project_id', $project->id)->count());
        $this->assertSame(1, NetworkRoute::where('project_id', $project->id)->where('route_type', 'drop')->count());
    }

    public function test_rainci_gornji_osm_fixture_is_complete_and_routable(): void
    {
        $project = Project::factory()->create();
        $contents = file_get_contents(base_path('docs/test-rainci-gornji-osm.txt'));
        $service = app(SurveyPointImportService::class);
        $points = $service->parse($contents);
        $preview = $service->preview($project, $contents, 'test-rainci-gornji-osm.txt');

        $this->assertCount(1500, $points);
        $this->assertCount(1500, collect($points)->pluck('point_no')->unique());
        $this->assertSame(1, collect($points)->where('kind', 'odf')->count());
        $this->assertSame(10, collect($points)->where('kind', 'cabinet')->count());
        $this->assertSame(30, collect($points)->where('kind', 'sling')->count());
        $this->assertSame(1500, $preview['total_points']);
        $this->assertSame('ready', $preview['quality']['status']);
        $this->assertSame(30, $preview['quality']['complete_drop_routes']);
        $this->assertSame(0, $preview['quality']['unreachable_drop_routes']);
        $dropDucts = collect($preview['ducts'])->where('route_type', 'drop')->values();
        $this->assertCount(30, $dropDucts);
        $this->assertSame([], $dropDucts->where('routing_status', '!=', 'complete')->values()->all());
        $this->assertSame([], $dropDucts->filter(fn (array $duct) => ! in_array((int) $duct['zo_tag'], range(1, 10), true))->values()->all());
    }

    public function test_preview_blocks_duplicate_numbers_and_customer_duct_without_zo(): void
    {
        $project = Project::factory()->create();
        $contents = implode("\n", [
            '1 6549699.731 4923604.537 234.000 ZO-1',
            '1 6549700.500 4923604.537 234.000 Rov + mc 10/8 Crvena x1',
            '2 6549704.000 4923604.537 234.000 Kuca 10/8 Crvena x1',
        ]);

        $quality = app(SurveyPointImportService::class)->preview($project, $contents, 'neispravan.txt')['quality'];

        $this->assertSame('blocked', $quality['status']);
        $this->assertSame([1], $quality['duplicate_point_numbers']);
        $this->assertSame([1, 2], $quality['customer_points_without_cabinet']);
    }

    public function test_large_survey_files_parse_and_build_without_data_loss(): void
    {
        $service = app(SurveyPointImportService::class);

        foreach ([1500 => 4.0, 10000 => 12.0] as $pointCount => $maxSeconds) {
            $lines = [];
            for ($index = 1; $index <= $pointCount; $index++) {
                $x = number_format(6549000 + ($index * 2.0), 3, '.', '');
                $y = number_format(4923000 + sin($index / 35) * 4, 3, '.', '');
                $lines[] = "{$index} {$x} {$y} 230.000 Rov";
            }

            $startedAt = microtime(true);
            $points = $service->parse(implode("\n", $lines));
            $network = $service->buildNetwork($points);
            $elapsed = microtime(true) - $startedAt;

            $this->assertCount($pointCount, $points);
            $this->assertSame($pointCount, collect($points)->pluck('point_no')->unique()->count());
            $this->assertCount(1, $network['trenches']);
            $this->assertCount($pointCount, $network['trenches'][0]['path']);
            $this->assertLessThan($maxSeconds, $elapsed, "Obrada {$pointCount} tačaka trajala je {$elapsed} s.");
        }
    }
}
