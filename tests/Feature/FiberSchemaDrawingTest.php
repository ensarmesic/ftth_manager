<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Services\FiberPlanService;
use App\Services\FiberSchema\FiberSchemaDrawing;
use App\Services\FiberSchema\FiberSchemaDxf;
use App\Services\FiberSchema\FiberSchemaSvg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiberSchemaDrawingTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Odf $odf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::create(['name' => 'Šema Čelić', 'code' => 'SEMA', 'location' => 'Banovići', 'status' => 'planning']);
        $this->odf = Odf::create(['project_id' => $this->project->id, 'name' => 'ODF-01', 'address' => 'Centar', 'fiber_capacity' => 144, 'port_count' => 48, 'latitude' => 44.40, 'longitude' => 18.52]);
    }

    public function test_every_cabinet_is_drawn_whatever_way_it_is_connected(): void
    {
        $branch = $this->branch('Sekundarni krak 1');
        $first = $this->cabinet('ZO-1', $branch, 1, 44.401, 18.521);
        $second = $this->cabinet('ZO-2', $branch, 2, 44.402, 18.522);
        $child = $this->branch('Sekundarni krak 1.2.1', $second, $branch);
        $onChild = $this->cabinet('ZO-2.1', $child, 1, 44.403, 18.523);
        $fed = Cabinet::create(['project_id' => $this->project->id, 'parent_cabinet_id' => $first->id, 'name' => 'ZO-1.A', 'address' => 'Izvod', 'splitter_count' => 1, 'ports_per_splitter' => 4]);
        $loose = $this->cabinet('ZO-bez-kraka', null, 1, 44.404, 18.524);
        $orphan = Cabinet::create(['project_id' => $this->project->id, 'name' => 'ZO-bez-ODF', 'address' => 'Nepoznato', 'splitter_count' => 1, 'ports_per_splitter' => 4]);
        House::create(['project_id' => $this->project->id, 'cabinet_id' => $onChild->id, 'label' => 'K1', 'status' => 'planned']);

        $drawing = app(FiberSchemaDrawing::class)->build($this->project);

        $this->assertSame(6, $drawing['stats']['cabinets']);
        $this->assertSame(6, $drawing['stats']['drawn_cabinets']);
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id, $onChild->id, $fed->id, $loose->id, $orphan->id],
            array_keys($drawing['cabinets']),
        );
        // A cable leaving a cabinet hangs below that cabinet; a fed cabinet below its parent.
        $this->assertGreaterThan($drawing['cabinets'][$second->id]['y'], $drawing['cabinets'][$onChild->id]['y']);
        $this->assertGreaterThan($drawing['cabinets'][$second->id]['x'], $drawing['cabinets'][$onChild->id]['x']);
        $this->assertGreaterThan($drawing['cabinets'][$first->id]['y'], $drawing['cabinets'][$fed->id]['y']);
        $labels = collect($drawing['items'])->where('t', 'text')->pluck('s');
        $this->assertTrue($labels->contains('napaja se iz ZO-1'));
        $this->assertTrue($labels->contains('ODO BEZ ODF VEZE'));
        $this->assertSame('nofiber', $drawing['cabinets'][$orphan->id]['state']);
        // The fiber table lists every cabinet once.
        $this->assertCount(6, $drawing['schedule']);
    }

    public function test_cable_from_a_regenerated_cabinet_hangs_under_the_nearest_parent_cabinet(): void
    {
        $branch = $this->branch('Sekundarni krak 1');
        $this->cabinet('ZO-1', $branch, 1, 44.4010, 18.5210);
        $near = $this->cabinet('ZO-2', $branch, 2, 44.4020, 18.5220);
        // The route still points at a cabinet id that no longer exists.
        $route = NetworkRoute::create($this->routeData('Odvojak', ['from_type' => 'cabinet', 'from_id' => 999999, 'path' => [[44.40201, 18.52201], [44.403, 18.523]]]));
        $child = NetworkBranch::create(['project_id' => $this->project->id, 'odf_id' => $this->odf->id, 'parent_branch_id' => $branch->id, 'route_id' => $route->id, 'name' => 'Sekundarni krak 1.2.1', 'type' => 'secondary']);
        $onChild = $this->cabinet('ZO-2.1', $child, 1, 44.403, 18.523);

        $drawing = app(FiberSchemaDrawing::class)->build($this->project);

        $this->assertGreaterThan($drawing['cabinets'][$near->id]['x'], $drawing['cabinets'][$onChild->id]['x']);
        $this->assertGreaterThan($drawing['cabinets'][$near->id]['y'], $drawing['cabinets'][$onChild->id]['y']);
        // The optical path length now includes the parent cable too.
        $plan = app(FiberPlanService::class)->build($this->project->fresh());
        $connection = $plan['connections']->firstWhere('cabinet_id', $onChild->id);
        $this->assertEqualsWithDelta(0.5, $connection['route_km'], 0.001);
    }

    public function test_dxf_is_a_complete_r12_drawing_with_blocks_layers_and_code_page(): void
    {
        $branch = $this->branch('Sekundarni krak 1');
        $cabinet = $this->cabinet('ZO-Čelić', $branch, 1, 44.401, 18.521);
        House::create(['project_id' => $this->project->id, 'cabinet_id' => $cabinet->id, 'label' => 'Kuća 1', 'status' => 'planned']);

        $drawing = app(FiberSchemaDrawing::class)->build($this->project);
        $dxf = app(FiberSchemaDxf::class)->render($drawing);
        $svg = app(FiberSchemaSvg::class)->render($drawing);

        foreach (['HEADER', 'TABLES', 'BLOCKS', 'ENTITIES'] as $section) {
            $this->assertStringContainsString("SECTION\r\n2\r\n{$section}", $dxf);
        }
        $this->assertSame(substr_count($dxf, "SECTION\r\n"), substr_count($dxf, "ENDSEC\r\n"));
        $this->assertStringEndsWith("0\r\nEOF\r\n", $dxf);
        $this->assertStringContainsString("\$DWGCODEPAGE\r\n3\r\nANSI_1250", $dxf);
        foreach (array_keys(FiberSchemaDrawing::LAYERS) as $layer) {
            $this->assertStringContainsString("LAYER\r\n2\r\n{$layer}\r\n", $dxf);
        }
        $this->assertSame(1, substr_count($dxf, "INSERT\r\n8\r\nFO-ODO"));
        // Text is stored in Windows-1250, so č and ć survive the trip to AutoCAD.
        $this->assertStringContainsString(iconv('UTF-8', 'CP1250', 'ZO-Čelić'), $dxf);
        $this->assertStringContainsString('PLAN ID '.$drawing['plan']['signature'], $dxf);
        // Screen and DXF come from one drawing.
        $this->assertStringContainsString('data-cabinet-id="'.$cabinet->id.'"', $svg);
        $this->assertStringContainsString('viewBox="0 0 '.(int) $drawing['sheet']['width'].' '.(int) $drawing['sheet']['height'].'"', $svg);
    }

    public function test_sheet_is_an_iso_a_size_that_fits_the_drawing(): void
    {
        $branch = $this->branch('Sekundarni krak 1');
        $this->cabinet('ZO-1', $branch, 1, 44.401, 18.521);

        $sheet = app(FiberSchemaDrawing::class)->build($this->project)['sheet'];

        $this->assertSame('A3', $sheet['name']);
        $this->assertSame([420.0, 297.0], [$sheet['width'], $sheet['height']]);
    }

    private function branch(string $name, ?Cabinet $from = null, ?NetworkBranch $parent = null): NetworkBranch
    {
        $route = NetworkRoute::create($this->routeData($name, $from ? ['from_type' => 'cabinet', 'from_id' => $from->id] : ['from_type' => 'odf', 'from_id' => $this->odf->id]));

        return NetworkBranch::create(['project_id' => $this->project->id, 'odf_id' => $this->odf->id, 'parent_branch_id' => $parent?->id, 'route_id' => $route->id, 'name' => $name, 'type' => 'secondary']);
    }

    private function routeData(string $name, array $extra): array
    {
        return $extra + [
            'project_id' => $this->project->id, 'odf_id' => $this->odf->id, 'name' => $name, 'route_type' => 'distribution',
            'installation_type' => 'underground', 'duct_length_m' => 250, 'fiber_length_m' => 250, 'fiber_count' => 12,
            'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned', 'path' => [[44.40, 18.52], [44.401, 18.521]],
        ];
    }

    private function cabinet(string $name, ?NetworkBranch $branch, int $order, float $lat, float $lng): Cabinet
    {
        return Cabinet::create([
            'project_id' => $this->project->id, 'odf_id' => $this->odf->id, 'branch_id' => $branch?->id, 'branch_order' => $order,
            'name' => $name, 'address' => 'Ulica '.$name, 'splitter_count' => 3, 'ports_per_splitter' => 4, 'latitude' => $lat, 'longitude' => $lng,
        ]);
    }
}
