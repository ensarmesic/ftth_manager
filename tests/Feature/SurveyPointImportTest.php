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

        // Zuta/Plava/Zelena from section 1; Zelena+Plava continue through
        // section 2, Crvena starts there.
        $labels = collect($ducts)->pluck('label')->sort()->values()->all();
        $this->assertSame(['MC 14/10 Crvena', 'MC 14/10 Plava', 'MC 14/10 Zelena', 'MC 14/10 Zuta'], $labels);

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
        $this->assertSame(2, NetworkRoute::where('project_id', $project->id)->where('route_type', 'distribution')->count());
        $this->assertSame(1, Cabinet::where('project_id', $project->id)->count());
        $this->assertSame(1, Odf::where('project_id', $project->id)->count());
        $this->assertSame(1, ProjectAppendixItem::where('project_id', $project->id)->where('type', 'manhole')->count());

        $trench = NetworkRoute::where('project_id', $project->id)->where('route_type', 'trench')->first();
        $this->assertStringContainsString('Geodetski snimak', $trench->note);
        $this->assertSame('ZO 3', Cabinet::where('project_id', $project->id)->value('name'));

        // The ZO 3 duct is bound to the ZO 3 cabinet created in the same import.
        $cabinet = Cabinet::where('project_id', $project->id)->first();
        $zoDuct = NetworkRoute::where('project_id', $project->id)->where('name', 'MC 10/8 ZO 3')->first();
        $this->assertNotNull($zoDuct);
        $this->assertSame($cabinet->id, $zoDuct->cabinet_id);
        $this->assertSame('10/8', $zoDuct->microduct_type);

        // The green 14/10 duct exists and carries its colour in the name.
        $this->assertNotNull(NetworkRoute::where('project_id', $project->id)->where('name', 'MC 14/10 Zelena')->first());

        // The slinga produced an unassigned house.
        $house = House::where('project_id', $project->id)->first();
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
}
