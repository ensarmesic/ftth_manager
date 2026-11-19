<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\ProjectAppendixItem;
use App\Services\ProjectReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke tests for every report, PDF, export and summary route.
 *
 * These render the real Blade views / DomPDF templates against a fully wired
 * project. Their job is to fail loudly on template syntax errors, missing
 * variables and broken relationships before those reach the running app — the
 * class of bug that plain feature tests (which never render these views) miss.
 */
class ReportRenderingTest extends TestCase
{
    use RefreshDatabase;

    private function seedFullProject(): Project
    {
        $project = Project::create([
            'name' => 'Smoke projekt',
            'code' => 'SMOKE',
            'location' => 'Tuzla',
            'investor' => 'Test investitor',
            'status' => 'planning',
        ]);

        $odf = Odf::create([
            'project_id' => $project->id,
            'name' => 'ODF-01',
            'address' => 'Centar',
            'fiber_capacity' => 144,
            'port_count' => 48,
            'latitude' => 44.5343,
            'longitude' => 18.6738,
        ]);

        $route = NetworkRoute::create([
            'project_id' => $project->id,
            'odf_id' => $odf->id,
            'name' => 'Sekundarni krak 1',
            'route_type' => 'distribution',
            'installation_type' => 'underground',
            'duct_length_m' => 120,
            'fiber_length_m' => 132,
            'fiber_count' => 12,
            'microduct_count' => 1,
            'microduct_type' => '14/10',
            'status' => 'planned',
            'path' => [[44.5343, 18.6738], [44.5353, 18.6748]],
        ]);

        $branch = NetworkBranch::create([
            'project_id' => $project->id,
            'odf_id' => $odf->id,
            'route_id' => $route->id,
            'name' => 'Sekundarni krak 1',
            'type' => 'secondary',
            'sort_order' => 1,
        ]);

        $cabinet = Cabinet::create([
            'project_id' => $project->id,
            'odf_id' => $odf->id,
            'branch_id' => $branch->id,
            'name' => 'FTTH 1-1',
            'address' => 'Krak 1',
            'splitter_count' => 3,
            'ports_per_splitter' => 4,
            'latitude' => 44.5350,
            'longitude' => 18.6745,
        ]);

        foreach (range(1, 6) as $index) {
            House::create([
                'project_id' => $project->id,
                'cabinet_id' => $cabinet->id,
                'label' => 'K-00'.$index,
                'address' => 'Ulica '.$index,
                'status' => 'planned',
                'latitude' => 44.5350 + ($index * 0.0001),
                'longitude' => 18.6745 + ($index * 0.0001),
            ]);
        }

        // One unconnected house — exercises the "Nepovezane kuce" branch that
        // carried the fiber-schema-pdf template bug.
        House::create([
            'project_id' => $project->id,
            'label' => 'K-999',
            'address' => 'Bez ODO',
            'status' => 'planned',
            'latitude' => 44.5360,
            'longitude' => 18.6760,
        ]);

        NetworkRoute::create([
            'project_id' => $project->id,
            'name' => 'Glavni rov 1',
            'route_type' => 'trench',
            'installation_type' => 'underground',
            'duct_length_m' => 90,
            'fiber_length_m' => 0,
            'microduct_count' => 0,
            'status' => 'planned',
            'path' => [[44.5343, 18.6738], [44.5353, 18.6748]],
        ]);

        ProjectAppendixItem::create([
            'project_id' => $project->id,
            'type' => 'manhole',
            'quantity' => 2,
            'unit' => 'KOMADA',
            'latitude' => 44.5345,
            'longitude' => 18.6740,
        ]);
        ProjectAppendixItem::create([
            'project_id' => $project->id,
            'type' => 'boring_fi_130',
            'quantity' => 15,
            'unit' => 'metara',
            'latitude' => 44.5346,
            'longitude' => 18.6741,
            'length_m' => 15,
        ]);

        return $project;
    }

    public function test_global_report_and_list_pages_render(): void
    {
        $this->seedFullProject();

        foreach ([
            'reports.index',
            'fiber-schema.index',
            'settings.index',
            'project-check.index',
        ] as $routeName) {
            $this->get(route($routeName))
                ->assertOk();
        }
    }

    public function test_removed_splitters_page_redirects_to_reports(): void
    {
        $this->get('/splitteri')->assertRedirect('/izvjestaji')->assertStatus(301);
        $this->get(route('dashboard'))->assertOk()->assertDontSee('/splitteri', false);
    }

    public function test_reports_overview_lists_projects_with_their_numbers(): void
    {
        $project = $this->seedFullProject();

        $this->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Svi projekti (pregled)')
            ->assertSee('href="'.route('reports.index', ['project' => $project->id]).'"', false)
            ->assertSee('6 / 7 · 86%')
            ->assertSee('6 / 12 · 50%')
            ->assertDontSee('Dijelovi izvještaja');
    }

    public function test_project_report_shows_capacity_routes_materials_and_documents(): void
    {
        $project = $this->seedFullProject();

        $this->get(route('reports.index', ['project' => $project->id]))
            ->assertOk()
            ->assertSee('Izvještaj projekta')
            ->assertSee('Smoke projekt')
            ->assertSee('1 bez ODO-a')
            ->assertSee('6 / 12 · 6 slobodno')
            ->assertSee('FTTH 1-1')
            ->assertSee('Glavni rov')
            ->assertSee('90 m')
            ->assertSee('sa rezervom 10%: 146 m')
            ->assertSee('Mikrocijev 14/10')
            ->assertSee('href="'.route('reports.project-appendix', $project).'"', false)
            ->assertSee('href="'.route('projects.fiber-schema-pdf', $project).'"', false);

        $report = app(ProjectReportService::class)->detail($project->fresh());
        $this->assertSame(50, $report['summary']['utilization']);
        $this->assertSame(1, $report['summary']['unassigned']);
        $this->assertSame(['trench', 'distribution'], array_column($report['routes_by_type'], 'type'));
        $this->assertSame(1, $report['odfs'][0]['cabinets']);
        $this->assertSame(6, $report['odfs'][0]['houses']);
    }

    public function test_per_project_reports_and_exports_render(): void
    {
        $project = $this->seedFullProject();

        // HTML / view routes
        $this->get(route('projects.show', $project))->assertOk();
        $this->get(route('reports.project-appendix', $project))->assertOk();
        $this->get(route('projects.print', $project))->assertOk();
        $this->getJson(route('projects.validation', $project))->assertOk();

        // Export routes
        $this->getJson(route('projects.geojson', $project))->assertOk();
        $this->post(route('projects.dxf', $project))->assertOk();
        $this->get(route('projects.fiber-schema-dxf', $project))
            ->assertOk()
            ->assertHeader('content-type', 'application/dxf')
            ->assertHeader('content-disposition', 'attachment; filename="smoke-fiber-schema.dxf"')
            ->assertSee("SECTION\r\n2\r\nHEADER", false)
            ->assertSee("\$DWGCODEPAGE\r\n3\r\nANSI_1250", false)
            ->assertSee("BLOCK\r\n8\r\n0\r\n2\r\nFO_ODO", false)
            ->assertSee("INSERT\r\n8\r\nFO-ODO", false)
            ->assertSee('FO-VLAKNA', false)
            ->assertSee("ENDSEC\r\n0\r\nEOF", false);
    }

    public function test_fiber_schema_pdf_downloads_for_project(): void
    {
        $project = $this->seedFullProject();

        $this->get(route('projects.fiber-schema-pdf', $project))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_report_pages_render_for_empty_project(): void
    {
        // A brand-new project with no elements must not blow up any report.
        $project = Project::create([
            'name' => 'Prazan',
            'code' => 'PRAZAN',
            'location' => 'Test',
            'status' => 'planning',
        ]);

        $this->get(route('reports.index'))->assertOk();
        $this->get(route('projects.show', $project))->assertOk();
        $this->get(route('reports.project-appendix', $project))->assertOk();
        $this->getJson(route('projects.validation', $project))->assertOk();
        $this->get(route('projects.fiber-schema-pdf', $project))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
