<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\GisRestrictedArea;
use App\Models\GisSegment;
use App\Models\House;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MediaskyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_material_calculation_groups_microducts_and_fibers(): void
    {
        $project = Project::create(['name' => 'Test', 'code' => 'TEST', 'location' => 'Test', 'status' => 'planning']);
        NetworkRoute::create(['project_id' => $project->id, 'name' => 'T1', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 100, 'fiber_length_m' => 120, 'fiber_count' => 12, 'microduct_count' => 2, 'microduct_type' => '14/10', 'status' => 'planned']);

        $this->post(route('materials.calculate', $project))->assertRedirect();

        $this->assertDatabaseHas('materials', ['project_id' => $project->id, 'name' => 'Mikrocijev 14/10', 'planned_quantity' => 200]);
        $this->assertDatabaseHas('materials', ['project_id' => $project->id, 'name' => 'Opticki kabl 12 niti', 'planned_quantity' => 120]);
    }

    public function test_dxf_line_import_creates_route(): void
    {
        $project = Project::create(['name' => 'DXF', 'code' => 'DXF', 'location' => 'Test', 'status' => 'planning']);
        $dxf = "0\nSECTION\n2\nENTITIES\n0\nLINE\n10\n18.6498\n20\n44.4493\n11\n18.6508\n21\n44.4503\n0\nENDSEC\n0\nEOF\n";

        $this->post(route('routes.dxf.import'), ['project_id' => $project->id, 'dxf' => UploadedFile::fake()->createWithContent('trasa.dxf', $dxf)])->assertRedirect();

        $this->assertDatabaseHas('routes', ['project_id' => $project->id, 'name' => 'DXF trasa 1', 'microduct_type' => '14/10']);
    }

    public function test_geojson_import_creates_gis_segments_for_auto_routing(): void
    {
        $project = Project::create(['name' => 'GIS', 'code' => 'GIS', 'location' => 'Test', 'status' => 'planning']);
        $geojson = json_encode([
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'properties' => ['name' => 'Test cesta'],
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => [[18.6498, 44.4493], [18.6508, 44.4493], [18.6508, 44.4503]],
                ],
            ]],
        ]);

        $this->post(route('gis.import'), [
            'project_id' => $project->id,
            'segment_type' => 'road',
            'geojson' => UploadedFile::fake()->createWithContent('ceste.geojson', $geojson),
        ])->assertRedirect();

        $this->assertDatabaseHas('gis_segments', ['project_id' => $project->id, 'name' => 'Test cesta', 'segment_type' => 'road']);
        $this->assertSame(1, GisSegment::where('project_id', $project->id)->count());

        $this->getJson(route('map.auto-route', [
            'project_id' => $project->id,
            'from_lat' => 44.4493,
            'from_lng' => 18.6498,
            'to_lat' => 44.4503,
            'to_lng' => 18.6508,
        ]))->assertOk()
            ->assertJsonPath('graph.source', 'gis')
            ->assertJsonCount(3, 'path');
    }

    public function test_geojson_import_creates_restricted_polygon_areas(): void
    {
        $project = Project::create(['name' => 'GIS zone', 'code' => 'GIS-ZONE', 'location' => 'Test', 'status' => 'planning']);
        $geojson = json_encode([
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'properties' => ['name' => 'Privatna parcela'],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[
                        [18.6505, 44.4490],
                        [18.6511, 44.4490],
                        [18.6511, 44.4496],
                        [18.6505, 44.4496],
                        [18.6505, 44.4490],
                    ]],
                ],
            ]],
        ]);

        $this->post(route('gis.import'), [
            'project_id' => $project->id,
            'segment_type' => 'restricted',
            'geojson' => UploadedFile::fake()->createWithContent('parcele.geojson', $geojson),
        ])->assertRedirect();

        $this->assertDatabaseHas('gis_restricted_areas', ['project_id' => $project->id, 'name' => 'Privatna parcela', 'area_type' => 'restricted']);
        $this->assertSame(1, GisRestrictedArea::where('project_id', $project->id)->count());
    }

    public function test_gis_layers_can_be_listed_and_deleted(): void
    {
        $project = Project::create(['name' => 'GIS layers', 'code' => 'GIS-LAYERS', 'location' => 'Test', 'status' => 'planning']);
        GisSegment::create([
            'project_id' => $project->id,
            'name' => 'Cesta 1',
            'segment_type' => 'road',
            'source' => 'test',
            'is_allowed' => true,
            'length_m' => 100,
            'path' => [[44.4493, 18.6498], [44.4503, 18.6508]],
        ]);
        GisRestrictedArea::create([
            'project_id' => $project->id,
            'name' => 'Parcela',
            'source' => 'test',
            'area_type' => 'restricted',
            'polygon' => [[44.4490, 18.6505], [44.4490, 18.6511], [44.4496, 18.6511], [44.4490, 18.6505]],
        ]);

        $this->getJson(route('gis.layers', $project))
            ->assertOk()
            ->assertJsonFragment(['type' => 'road', 'count' => 1, 'length_m' => 100])
            ->assertJsonFragment(['type' => 'restricted_areas', 'count' => 1, 'length_m' => null]);

        $this->deleteJson(route('gis.layers.destroy', [$project, 'road']))
            ->assertOk()
            ->assertJsonPath('deleted', 1);
        $this->assertSame(0, GisSegment::where('project_id', $project->id)->where('segment_type', 'road')->count());

        $this->deleteJson(route('gis.layers.destroy', [$project, 'restricted_areas']))
            ->assertOk()
            ->assertJsonPath('deleted', 1);
        $this->assertSame(0, GisRestrictedArea::where('project_id', $project->id)->count());
    }

    public function test_auto_routing_avoids_restricted_polygon_areas(): void
    {
        $project = Project::create(['name' => 'GIS avoid', 'code' => 'GIS-AVOID', 'location' => 'Test', 'status' => 'planning']);
        GisSegment::create([
            'project_id' => $project->id,
            'name' => 'Direktna cesta',
            'segment_type' => 'road',
            'source' => 'test',
            'is_allowed' => true,
            'length_m' => 0,
            'path' => [[44.4493, 18.6498], [44.4493, 18.6518]],
        ]);
        GisSegment::create([
            'project_id' => $project->id,
            'name' => 'Obilaznica',
            'segment_type' => 'road',
            'source' => 'test',
            'is_allowed' => true,
            'length_m' => 0,
            'path' => [[44.4493, 18.6498], [44.4503, 18.6498], [44.4503, 18.6518], [44.4493, 18.6518]],
        ]);
        GisRestrictedArea::create([
            'project_id' => $project->id,
            'name' => 'Privatna parcela',
            'source' => 'test',
            'area_type' => 'restricted',
            'polygon' => [
                [44.4490, 18.6505],
                [44.4490, 18.6511],
                [44.4496, 18.6511],
                [44.4496, 18.6505],
                [44.4490, 18.6505],
            ],
        ]);

        $response = $this->getJson(route('map.auto-route', [
            'project_id' => $project->id,
            'from_lat' => 44.4493,
            'from_lng' => 18.6498,
            'to_lat' => 44.4493,
            'to_lng' => 18.6518,
        ]))->assertOk()
            ->assertJsonPath('graph.source', 'gis')
            ->assertJsonPath('graph.restricted_areas', 1)
            ->json();

        $this->assertContains([44.4503, 18.6498], $response['path']);
        $this->assertContains([44.4503, 18.6518], $response['path']);
    }

    public function test_missing_drop_routes_use_gis_graph_when_available(): void
    {
        $project = Project::create(['name' => 'GIS drop', 'code' => 'GIS-DROP', 'location' => 'Test', 'status' => 'planning']);
        $cabinet = Cabinet::create([
            'project_id' => $project->id,
            'name' => 'FTTH 1-1-1',
            'address' => 'Cesta',
            'splitter_count' => 1,
            'ports_per_splitter' => 4,
            'latitude' => 44.4493,
            'longitude' => 18.6498,
        ]);
        House::create([
            'project_id' => $project->id,
            'cabinet_id' => $cabinet->id,
            'label' => 'K-001',
            'address' => 'Kuca',
            'status' => 'planned',
            'latitude' => 44.4503,
            'longitude' => 18.6508,
        ]);
        GisSegment::create([
            'project_id' => $project->id,
            'name' => 'Drop cesta',
            'segment_type' => 'road',
            'source' => 'test',
            'is_allowed' => true,
            'length_m' => 0,
            'path' => [[44.4493, 18.6498], [44.4493, 18.6508], [44.4503, 18.6508]],
        ]);

        $this->postJson(route('projects.drop-routes.fill', $project))
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('routes.0.note', 'Automatski rutirano kroz GIS graf.');

        $this->assertDatabaseHas('routes', [
            'project_id' => $project->id,
            'route_type' => 'drop',
            'note' => 'Automatski rutirano kroz GIS graf.',
        ]);
    }

    public function test_gis_plan_preview_routes_houses_from_odf(): void
    {
        $project = Project::create(['name' => 'GIS plan', 'code' => 'GIS-PLAN', 'location' => 'Test', 'status' => 'planning']);
        Odf::create([
            'project_id' => $project->id,
            'name' => 'ODF-1',
            'address' => 'Centar',
            'fiber_capacity' => 144,
            'port_count' => 48,
            'latitude' => 44.4493,
            'longitude' => 18.6498,
        ]);
        House::create([
            'project_id' => $project->id,
            'label' => 'K-001',
            'address' => 'Kuca',
            'status' => 'planned',
            'latitude' => 44.4503,
            'longitude' => 18.6508,
        ]);
        GisSegment::create([
            'project_id' => $project->id,
            'name' => 'Plan cesta',
            'segment_type' => 'road',
            'source' => 'test',
            'is_allowed' => true,
            'length_m' => 0,
            'path' => [[44.4493, 18.6498], [44.4493, 18.6508], [44.4503, 18.6508]],
        ]);

        $this->getJson(route('projects.gis-plan.preview', $project))
            ->assertOk()
            ->assertJsonPath('summary.status', 'ok')
            ->assertJsonPath('summary.routed_houses', 1)
            ->assertJsonPath('summary.graph_source', 'gis')
            ->assertJsonPath('summary.cabinet_count', 1)
            ->assertJsonPath('summary.score', 92)
            ->assertJsonPath('summary.max_drop_m', 0)
            ->assertJsonPath('warnings.0', 'GIS ODO 1 je slabo popunjen (25%).')
            ->assertJsonPath('routes.0.house.label', 'K-001');
    }

    public function test_gis_plan_confirm_creates_unique_network_routes(): void
    {
        $project = Project::create(['name' => 'GIS confirm', 'code' => 'GIS-CONFIRM', 'location' => 'Test', 'status' => 'planning']);
        Odf::create([
            'project_id' => $project->id,
            'name' => 'ODF-1',
            'address' => 'Centar',
            'fiber_capacity' => 144,
            'port_count' => 48,
            'latitude' => 44.4493,
            'longitude' => 18.6498,
        ]);
        foreach ([[1, 44.4503, 18.6508], [2, 44.4506, 18.6508]] as [$idx, $lat, $lng]) {
            House::create([
                'project_id' => $project->id,
                'label' => 'K-00'.$idx,
                'address' => 'Kuca',
                'status' => 'planned',
                'latitude' => $lat,
                'longitude' => $lng,
            ]);
        }
        GisSegment::create([
            'project_id' => $project->id,
            'name' => 'Plan cesta',
            'segment_type' => 'road',
            'source' => 'test',
            'is_allowed' => true,
            'length_m' => 0,
            'path' => [[44.4493, 18.6498], [44.4493, 18.6508], [44.4503, 18.6508], [44.4506, 18.6508]],
        ]);

        $this->postJson(route('projects.gis-plan.confirm', $project))
            ->assertCreated()
            ->assertJsonPath('summary.routed_houses', 2)
            ->assertJsonPath('created_cabinets', 1)
            ->assertJsonPath('created_drop_routes', 2);

        $this->assertSame(3, NetworkRoute::where('project_id', $project->id)->where('route_type', 'distribution')->count());
        $this->assertSame(2, NetworkRoute::where('project_id', $project->id)->where('route_type', 'drop')->count());
        $this->assertSame(1, Cabinet::where('project_id', $project->id)->count());
        $this->assertSame(2, House::where('project_id', $project->id)->whereNotNull('cabinet_id')->count());
        $this->assertSame(3, NetworkBranch::where('project_id', $project->id)->count());
        $this->assertDatabaseHas('routes', ['project_id' => $project->id, 'fiber_count' => 4]);
    }

    public function test_gis_plan_assigns_houses_to_nearest_odf(): void
    {
        $project = Project::create(['name' => 'Multi ODF', 'code' => 'MULTI-ODF', 'location' => 'Test', 'status' => 'planning']);
        $left = Odf::create([
            'project_id' => $project->id,
            'name' => 'ODF-L',
            'address' => 'L',
            'fiber_capacity' => 144,
            'port_count' => 48,
            'latitude' => 44.4493,
            'longitude' => 18.6498,
        ]);
        $right = Odf::create([
            'project_id' => $project->id,
            'name' => 'ODF-R',
            'address' => 'R',
            'fiber_capacity' => 144,
            'port_count' => 48,
            'latitude' => 44.4493,
            'longitude' => 18.6548,
        ]);
        House::create(['project_id' => $project->id, 'label' => 'K-L', 'address' => 'L', 'status' => 'planned', 'latitude' => 44.4493, 'longitude' => 18.6508]);
        House::create(['project_id' => $project->id, 'label' => 'K-R', 'address' => 'R', 'status' => 'planned', 'latitude' => 44.4493, 'longitude' => 18.6538]);
        GisSegment::create([
            'project_id' => $project->id,
            'name' => 'Glavna cesta',
            'segment_type' => 'road',
            'source' => 'test',
            'is_allowed' => true,
            'length_m' => 0,
            'path' => [[44.4493, 18.6498], [44.4493, 18.6508], [44.4493, 18.6538], [44.4493, 18.6548]],
        ]);

        $preview = $this->getJson(route('projects.gis-plan.preview', $project))
            ->assertOk()
            ->assertJsonPath('summary.used_odf_count', 2)
            ->json();

        $this->assertEqualsCanonicalizing(['ODF-L', 'ODF-R'], collect($preview['odfs'])->pluck('name')->all());

        $this->postJson(route('projects.gis-plan.confirm', $project))
            ->assertCreated()
            ->assertJsonPath('created_cabinets', 2);

        $this->assertSame(1, Cabinet::where('project_id', $project->id)->where('odf_id', $left->id)->count());
        $this->assertSame(1, Cabinet::where('project_id', $project->id)->where('odf_id', $right->id)->count());
    }

    public function test_rerunning_gis_plan_confirm_does_not_duplicate_the_network(): void
    {
        $project = Project::create(['name' => 'GIS rerun', 'code' => 'GIS-RERUN', 'location' => 'Test', 'status' => 'planning']);
        Odf::create(['project_id' => $project->id, 'name' => 'ODF-1', 'address' => 'Centar', 'fiber_capacity' => 144, 'port_count' => 48, 'latitude' => 44.4493, 'longitude' => 18.6498]);
        foreach ([[1, 44.4503, 18.6508], [2, 44.4506, 18.6508]] as [$idx, $lat, $lng]) {
            House::create(['project_id' => $project->id, 'label' => 'K-00'.$idx, 'address' => 'Kuca', 'status' => 'planned', 'latitude' => $lat, 'longitude' => $lng]);
        }
        GisSegment::create([
            'project_id' => $project->id,
            'name' => 'Plan cesta',
            'segment_type' => 'road',
            'source' => 'test',
            'is_allowed' => true,
            'length_m' => 0,
            'path' => [[44.4493, 18.6498], [44.4493, 18.6508], [44.4503, 18.6508], [44.4506, 18.6508]],
        ]);

        $this->postJson(route('projects.gis-plan.confirm', $project))->assertCreated();
        $cabinetsAfterFirst = Cabinet::where('project_id', $project->id)->count();
        $routesAfterFirst = NetworkRoute::where('project_id', $project->id)->count();

        // Second run: all houses are already on an ODO, so there is nothing left
        // to plan and the network must not grow.
        $this->postJson(route('projects.gis-plan.confirm', $project))->assertStatus(422);

        $this->assertSame($cabinetsAfterFirst, Cabinet::where('project_id', $project->id)->count());
        $this->assertSame($routesAfterFirst, NetworkRoute::where('project_id', $project->id)->count());
    }

    public function test_gis_plan_warns_when_odf_capacity_is_near_limit(): void
    {
        $project = Project::create(['name' => 'ODF cap', 'code' => 'ODF-CAP', 'location' => 'Test', 'status' => 'planning']);
        Odf::create([
            'project_id' => $project->id,
            'name' => 'ODF-MALI',
            'address' => 'Centar',
            'fiber_capacity' => 1,
            'port_count' => 48,
            'latitude' => 44.4493,
            'longitude' => 18.6498,
        ]);
        House::create(['project_id' => $project->id, 'label' => 'K-001', 'address' => 'Kuca', 'status' => 'planned', 'latitude' => 44.4493, 'longitude' => 18.6508]);
        GisSegment::create([
            'project_id' => $project->id,
            'name' => 'Cesta',
            'segment_type' => 'road',
            'source' => 'test',
            'is_allowed' => true,
            'length_m' => 0,
            'path' => [[44.4493, 18.6498], [44.4493, 18.6508]],
        ]);

        $this->getJson(route('projects.gis-plan.preview', $project))
            ->assertOk()
            ->assertJsonPath('odfs.0.splitter_count', 1)
            ->assertJsonPath('odfs.0.fiber_capacity', 1)
            ->assertJsonPath('odfs.0.utilization_percent', 100)
            ->assertJsonFragment(['ODF-MALI je blizu kapaciteta (100%).']);
    }

    public function test_gis_plan_balances_houses_when_nearest_odf_is_full(): void
    {
        $project = Project::create(['name' => 'ODF balance', 'code' => 'ODF-BALANCE', 'location' => 'Test', 'status' => 'planning']);
        Odf::create([
            'project_id' => $project->id,
            'name' => 'ODF-MALI',
            'address' => 'L',
            'fiber_capacity' => 1,
            'port_count' => 48,
            'latitude' => 44.4493,
            'longitude' => 18.6498,
        ]);
        Odf::create([
            'project_id' => $project->id,
            'name' => 'ODF-REZERVA',
            'address' => 'R',
            'fiber_capacity' => 10,
            'port_count' => 48,
            'latitude' => 44.4493,
            'longitude' => 18.6548,
        ]);
        foreach ([[1, 18.6502], [2, 18.6504], [3, 18.6506], [4, 18.6508], [5, 18.6510]] as [$idx, $lng]) {
            House::create(['project_id' => $project->id, 'label' => 'K-00'.$idx, 'address' => 'Kuca', 'status' => 'planned', 'latitude' => 44.4493, 'longitude' => $lng]);
        }
        GisSegment::create([
            'project_id' => $project->id,
            'name' => 'Cesta',
            'segment_type' => 'road',
            'source' => 'test',
            'is_allowed' => true,
            'length_m' => 0,
            'path' => [[44.4493, 18.6498], [44.4493, 18.6502], [44.4493, 18.6504], [44.4493, 18.6506], [44.4493, 18.6508], [44.4493, 18.6510], [44.4493, 18.6548]],
        ]);

        $preview = $this->getJson(route('projects.gis-plan.preview', $project))
            ->assertOk()
            ->assertJsonFragment(['1 kuca je prebaceno na drugi ODF zbog kapaciteta.'])
            ->json();

        $byName = collect($preview['odfs'])->keyBy('name');
        $this->assertSame(4, $byName['ODF-MALI']['house_count']);
        $this->assertSame(1, $byName['ODF-REZERVA']['house_count']);
    }

    public function test_map_plan_saves_route_path_without_cabinet_link(): void
    {
        $project = Project::create(['name' => 'Mapa', 'code' => 'MAPA', 'location' => 'Test', 'status' => 'planning']);
        $plan = ['routes' => [['name' => 'S-01', 'route_type' => 'distribution', 'duct_length_m' => 42, 'fiber_length_m' => 42, 'path' => [[44.4493, 18.6498], [44.4500, 18.6505]]]]];

        $this->postJson(route('map.plan.store'), ['project_id' => $project->id, 'plan' => json_encode($plan)])->assertOk();

        $this->assertDatabaseHas('routes', ['project_id' => $project->id, 'name' => 'S-01', 'duct_length_m' => 42]);
        $this->get(route('map.dashboard'))->assertOk()->assertSee('S-01')->assertSee('Predlozi FTTH');
        $this->get(route('map.index'))->assertRedirect(route('map.dashboard'));
    }

    public function test_map_plan_saves_trench_route_without_fiber_or_microduct(): void
    {
        $project = Project::create(['name' => 'Rov mapa', 'code' => 'ROV-MAPA', 'location' => 'Test', 'status' => 'planning']);
        $plan = ['routes' => [[
            'name' => 'R-01',
            'route_type' => 'trench',
            'duct_length_m' => 42,
            'fiber_length_m' => 42,
            'microduct_count' => 1,
            'microduct_type' => '14/10',
            'path' => [[44.4493, 18.6498], [44.4500, 18.6505]],
        ]]];

        $this->postJson(route('map.plan.store'), ['project_id' => $project->id, 'plan' => json_encode($plan)])->assertOk();

        $this->assertDatabaseHas('routes', [
            'project_id' => $project->id,
            'name' => 'R-01',
            'route_type' => 'trench',
            'fiber_length_m' => 0,
            'microduct_count' => 0,
            'microduct_type' => null,
        ]);
    }

    public function test_map_plan_generates_odo_and_route_names_when_missing(): void
    {
        $project = Project::create(['name' => 'Auto nazivi', 'code' => 'AUTO-NAZIVI', 'location' => 'Test', 'status' => 'planning']);
        $plan = [
            'cabinets' => [[
                'lat' => 44.451,
                'lng' => 18.651,
                'splitter_count' => 2,
            ]],
            'routes' => [[
                'route_type' => 'distribution',
                'duct_length_m' => 42,
                'path' => [[44.4493, 18.6498], [44.4500, 18.6505]],
            ], [
                'route_type' => 'trench',
                'duct_length_m' => 30,
                'path' => [[44.4500, 18.6505], [44.4510, 18.6515]],
            ]],
        ];

        $this->postJson(route('map.plan.store'), ['project_id' => $project->id, 'plan' => json_encode($plan)])->assertOk();

        $this->assertDatabaseHas('cabinets', ['project_id' => $project->id, 'name' => 'FTTH 1-1-1']);
        $this->assertDatabaseHas('routes', ['project_id' => $project->id, 'name' => 'Sekundarni krak 1', 'route_type' => 'distribution']);
        $this->assertDatabaseHas('routes', ['project_id' => $project->id, 'name' => 'Glavni rov 1', 'route_type' => 'trench', 'microduct_type' => null]);
    }

    public function test_project_geojson_export_contains_points_and_routes(): void
    {
        $project = Project::create(['name' => 'Geo export', 'code' => 'GEO', 'location' => 'Test', 'status' => 'planning']);
        Odf::create(['project_id' => $project->id, 'name' => 'ODF-1', 'address' => 'Centar', 'fiber_capacity' => 144, 'port_count' => 48, 'latitude' => 44.4490, 'longitude' => 18.6490]);
        NetworkRoute::create(['project_id' => $project->id, 'name' => 'Sekundarni krak 1', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 10, 'fiber_length_m' => 10, 'fiber_count' => 12, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned', 'path' => [[44.4490, 18.6490], [44.4500, 18.6500]]]);

        $this->getJson(route('projects.geojson', $project))
            ->assertOk()
            ->assertJsonPath('type', 'FeatureCollection')
            ->assertJsonPath('features.0.geometry.type', 'Point')
            ->assertJsonPath('features.1.geometry.type', 'LineString');
    }

    public function test_project_dxf_export_contains_polyline_and_labels(): void
    {
        $project = Project::create(['name' => 'DXF export', 'code' => 'DXFE', 'location' => 'Test', 'status' => 'planning']);
        Odf::create(['project_id' => $project->id, 'name' => 'ODF-1', 'address' => 'Centar', 'fiber_capacity' => 144, 'port_count' => 48, 'latitude' => 44.4490, 'longitude' => 18.6490]);
        NetworkRoute::create(['project_id' => $project->id, 'name' => 'Sekundarni krak 1', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 10, 'fiber_length_m' => 10, 'fiber_count' => 12, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned', 'path' => [[44.4490, 18.6490], [44.4500, 18.6500]]]);

        $this->post(route('projects.dxf', $project))
            ->assertOk()
            ->assertDownload()
            ->assertHeader('Content-Type', 'application/dxf');
    }

    public function test_project_print_view_renders_summary(): void
    {
        $project = Project::create(['name' => 'Print projekt', 'code' => 'PRINT', 'location' => 'Test', 'status' => 'planning']);
        NetworkRoute::create(['project_id' => $project->id, 'name' => 'Sekundarni krak 1', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 10, 'fiber_length_m' => 10, 'fiber_count' => 12, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned', 'path' => [[44.4490, 18.6490], [44.4500, 18.6500]]]);

        $this->get(route('projects.print', $project))
            ->assertOk()
            ->assertSee('Print projekt')
            ->assertSee('Materijalni obračun')
            ->assertSee('Sekundarni krak 1');
    }

    public function test_map_workspace_renders_cad_and_auto_odo_controls(): void
    {
        $this->get(route('map.dashboard'))
            ->assertOk()
            ->assertSee('Layer Manager')
            ->assertSee('Command: PAN')
            ->assertSee('Potvrdi raspored')
            ->assertSee('Crtanje')
            ->assertSee('Fiber tracing');
    }

    public function test_map_payload_contains_trace_relationships(): void
    {
        $project = Project::create(['name' => 'Trace mapa', 'code' => 'TRACE', 'location' => 'Test', 'status' => 'planning']);
        $odf = Odf::create(['project_id' => $project->id, 'name' => 'ODF-01', 'address' => 'Centar', 'fiber_capacity' => 144, 'port_count' => 48, 'latitude' => 44.45, 'longitude' => 18.65]);
        $cabinet = Cabinet::create(['project_id' => $project->id, 'odf_id' => $odf->id, 'name' => 'FTTH 1-1', 'address' => 'Krak 1', 'splitter_count' => 3, 'ports_per_splitter' => 4, 'latitude' => 44.451, 'longitude' => 18.651]);
        House::create(['project_id' => $project->id, 'cabinet_id' => $cabinet->id, 'label' => 'Kuca 12', 'latitude' => 44.452, 'longitude' => 18.652, 'status' => 'planned']);
        NetworkRoute::create(['project_id' => $project->id, 'odf_id' => $odf->id, 'cabinet_id' => $cabinet->id, 'name' => 'ODF do FTTH', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 20, 'fiber_length_m' => 20, 'fiber_count' => 12, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned', 'path' => [[44.45, 18.65], [44.451, 18.651]]]);

        $this->get(route('map.dashboard'))
            ->assertOk()
            ->assertSee('"odf_id":'.$odf->id, false)
            ->assertSee('"cabinet_id":'.$cabinet->id, false)
            ->assertSee('map-trace-panel');
    }

    public function test_map_trace_uses_network_path_fallback_instead_of_direct_shortest_line(): void
    {
        $this->get(route('map.dashboard'))
            ->assertOk()
            ->assertSee('map-trace-panel')
            ->assertSee('map-trace-output')
            ->assertSee('Fiber tracing');
    }

    public function test_ftth_topology_renders_odf_cabinet_house_and_trace_panel(): void
    {
        $project = Project::create(['name' => 'Topologija', 'code' => 'TOP', 'location' => 'Test', 'status' => 'planning']);
        $odf = Odf::create(['project_id' => $project->id, 'name' => 'ODF-01', 'address' => 'Centar', 'fiber_capacity' => 144, 'port_count' => 48]);
        $secondOdf = Odf::create(['project_id' => $project->id, 'name' => 'ODF-02', 'address' => 'Drugi', 'fiber_capacity' => 144, 'port_count' => 48]);
        $route = NetworkRoute::create(['project_id' => $project->id, 'odf_id' => $odf->id, 'name' => 'S1-1', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 100, 'fiber_length_m' => 100, 'fiber_count' => 12, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned', 'path' => [[44.45, 18.65], [44.451, 18.651]]]);
        $branch = NetworkBranch::create(['project_id' => $project->id, 'odf_id' => $odf->id, 'route_id' => $route->id, 'name' => 'S1-1', 'type' => 'secondary']);
        $cabinet = Cabinet::create(['project_id' => $project->id, 'odf_id' => $odf->id, 'branch_id' => $branch->id, 'name' => 'FTTH 1-1', 'address' => 'Krak 1', 'splitter_count' => 3, 'ports_per_splitter' => 4]);
        $secondRoute = NetworkRoute::create(['project_id' => $project->id, 'odf_id' => $secondOdf->id, 'name' => 'S2-1', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 100, 'fiber_length_m' => 100, 'fiber_count' => 12, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned', 'path' => [[44.452, 18.652], [44.453, 18.653]]]);
        $secondBranch = NetworkBranch::create(['project_id' => $project->id, 'odf_id' => $secondOdf->id, 'route_id' => $secondRoute->id, 'name' => 'S2-1', 'type' => 'secondary']);
        $secondCabinet = Cabinet::create(['project_id' => $project->id, 'odf_id' => $secondOdf->id, 'branch_id' => $secondBranch->id, 'name' => 'FTTH 2-1', 'address' => 'Krak 2', 'splitter_count' => 2, 'ports_per_splitter' => 4]);
        $childCabinet = Cabinet::create(['project_id' => $project->id, 'parent_cabinet_id' => $cabinet->id, 'name' => 'FTTH 1-1.1', 'address' => 'Izvod 1', 'splitter_count' => 1, 'ports_per_splitter' => 4]);
        foreach (range(1, 9) as $index) {
            House::create(['project_id' => $project->id, 'cabinet_id' => $cabinet->id, 'label' => "Kuca {$index}", 'status' => 'planned']);
        }
        House::create(['project_id' => $project->id, 'cabinet_id' => $childCabinet->id, 'label' => 'Kuca 2', 'status' => 'planned']);
        foreach (range(1, 5) as $index) {
            House::create(['project_id' => $project->id, 'cabinet_id' => $secondCabinet->id, 'label' => "Druga kuca {$index}", 'status' => 'planned']);
        }

        $this->get(route('fiber-schema.index'))
            ->assertOk()
            ->assertSee('FTTH Topologija')
            ->assertSee('ODF-01')
            ->assertSee('FTTH 1-1')
            ->assertSee('FTTH 1-1.1')
            ->assertSee('IZ FTTH 1-1')
            ->assertSee('Kuca 1')
            ->assertSee('Kuca 2')
            ->assertSee('F 1-3')
            ->assertSee('F 4')
            ->assertSee('F 5-6')
            ->assertSee('Magistralna optika / raspored iz 144')
            ->assertSee('Color Code')
            ->assertSee('data-color-code="true"', false)
            ->assertSee('6 tuba × 24 niti')
            ->assertSee('ODF portovi')
            ->assertSee('Splice plan')
            ->assertSee('Automatska kontrola fiber plana')
            ->assertSee('Nema dvostruko dodijeljenih vlakana')
            ->assertSee('data-fiber-range="1-3"', false)
            ->assertSee('data-fiber-range="4"', false)
            ->assertSee('Fiber tracing');
    }

    public function test_cabinet_can_be_fed_from_parent_cabinet(): void
    {
        $project = Project::create(['name' => 'Parent ODO', 'code' => 'PODO', 'location' => 'Test', 'status' => 'planning']);
        $odf = Odf::create(['project_id' => $project->id, 'name' => 'ODF-01', 'address' => 'Centar', 'fiber_capacity' => 144, 'port_count' => 48]);
        $parent = Cabinet::create(['project_id' => $project->id, 'odf_id' => $odf->id, 'name' => 'FTTH 2-1', 'address' => 'Krak 2', 'splitter_count' => 3, 'ports_per_splitter' => 4]);

        $this->post(route('cabinets.store'), [
            'project_id' => $project->id,
            'odf_id' => null,
            'parent_cabinet_id' => $parent->id,
            'name' => 'FTTH 2-1.1',
            'address' => 'Izvod',
            'splitter_count' => 1,
            'ports_per_splitter' => 4,
            'latitude' => null,
            'longitude' => null,
        ])->assertRedirect();

        $this->assertDatabaseHas('cabinets', [
            'project_id' => $project->id,
            'parent_cabinet_id' => $parent->id,
            'name' => 'FTTH 2-1.1',
        ]);
    }

    public function test_fiber_schema_allocates_cabinet_branch_after_source_cabinet(): void
    {
        $project = Project::create(['name' => 'Branch child fibers', 'code' => 'BCF', 'location' => 'Test', 'status' => 'planning']);
        $odf = Odf::create(['project_id' => $project->id, 'name' => 'ODF-01', 'address' => 'Centar', 'fiber_capacity' => 144, 'port_count' => 48]);
        $rootRoute = NetworkRoute::create(['project_id' => $project->id, 'odf_id' => $odf->id, 'name' => 'S2', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 100, 'fiber_length_m' => 100, 'fiber_count' => 12, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned', 'path' => [[44.45, 18.65], [44.451, 18.651]]]);
        $rootBranch = NetworkBranch::create(['project_id' => $project->id, 'odf_id' => $odf->id, 'route_id' => $rootRoute->id, 'name' => 'Sekundarni krak 2', 'type' => 'secondary']);
        $parent = Cabinet::create(['project_id' => $project->id, 'odf_id' => $odf->id, 'branch_id' => $rootBranch->id, 'branch_order' => 1, 'name' => 'FTTH 2-8', 'address' => 'Krak', 'splitter_count' => 2, 'ports_per_splitter' => 4]);
        $secondCabinet = Cabinet::create(['project_id' => $project->id, 'odf_id' => $odf->id, 'branch_id' => $rootBranch->id, 'branch_order' => 2, 'name' => 'FTTH 2-9', 'address' => 'Krak', 'splitter_count' => 1, 'ports_per_splitter' => 4]);
        $childRoute = NetworkRoute::create(['project_id' => $project->id, 'odf_id' => $odf->id, 'from_type' => 'cabinet', 'from_id' => $parent->id, 'name' => 'S2-8.1', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 100, 'fiber_length_m' => 100, 'fiber_count' => 12, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned', 'path' => [[44.451, 18.651], [44.452, 18.652]]]);
        $childBranch = NetworkBranch::create(['project_id' => $project->id, 'odf_id' => $odf->id, 'route_id' => $childRoute->id, 'name' => 'Sekundarni krak 2-8.1', 'type' => 'secondary']);
        $childCabinet = Cabinet::create(['project_id' => $project->id, 'odf_id' => $odf->id, 'branch_id' => $childBranch->id, 'branch_order' => 1, 'name' => 'FTTH 2-8-1', 'address' => 'Izvod', 'splitter_count' => 3, 'ports_per_splitter' => 4]);
        foreach (range(1, 5) as $index) {
            House::create(['project_id' => $project->id, 'cabinet_id' => $parent->id, 'label' => "P{$index}", 'status' => 'planned']);
        }
        House::create(['project_id' => $project->id, 'cabinet_id' => $secondCabinet->id, 'label' => 'S1', 'status' => 'planned']);
        foreach (range(1, 9) as $index) {
            House::create(['project_id' => $project->id, 'cabinet_id' => $childCabinet->id, 'label' => "C{$index}", 'status' => 'planned']);
        }

        $this->get(route('fiber-schema.index'))
            ->assertOk()
            ->assertSee('FTTH 2-8')
            ->assertSee('FTTH 2-8-1')
            ->assertSee('F 1-2')
            ->assertSee('F 3')
            ->assertSee('F 4-6');
    }

    public function test_cabinet_can_only_be_assigned_to_secondary_branch(): void
    {
        $project = Project::create(['name' => 'Sekundarni ODO', 'code' => 'SODO', 'location' => 'Test', 'status' => 'planning']);
        $primary = NetworkBranch::create(['project_id' => $project->id, 'name' => 'Glavni krak', 'type' => 'primary']);
        $secondary = NetworkBranch::create(['project_id' => $project->id, 'parent_branch_id' => $primary->id, 'name' => 'Sekundarni krak', 'type' => 'secondary']);

        $payload = [
            'project_id' => $project->id,
            'branch_id' => $primary->id,
            'branch_order' => 1,
            'name' => 'FTTH S-1',
            'address' => 'Krak S',
            'splitter_count' => 1,
            'ports_per_splitter' => 4,
            'latitude' => null,
            'longitude' => null,
        ];

        $this->from(route('cabinets.index'))->post(route('cabinets.store'), $payload)
            ->assertRedirect(route('cabinets.index'))
            ->assertSessionHasErrors('branch_id');

        $this->post(route('cabinets.store'), array_merge($payload, ['branch_id' => $secondary->id]))
            ->assertRedirect();

        $this->assertDatabaseHas('cabinets', [
            'project_id' => $project->id,
            'branch_id' => $secondary->id,
            'name' => 'FTTH S-1',
        ]);
    }

    public function test_route_can_start_from_existing_cabinet(): void
    {
        $project = Project::create(['name' => 'ODO start', 'code' => 'OST', 'location' => 'Test', 'status' => 'planning']);
        $odf = Odf::create(['project_id' => $project->id, 'name' => 'ODF-01', 'address' => 'Centar', 'fiber_capacity' => 144, 'port_count' => 48]);
        $parent = Cabinet::create(['project_id' => $project->id, 'odf_id' => $odf->id, 'name' => 'FTTH 2-1', 'address' => 'Krak 2', 'splitter_count' => 3, 'ports_per_splitter' => 4, 'latitude' => 44.451, 'longitude' => 18.651]);
        $child = Cabinet::create(['project_id' => $project->id, 'parent_cabinet_id' => $parent->id, 'name' => 'FTTH 2-1.1', 'address' => 'Izvod', 'splitter_count' => 1, 'ports_per_splitter' => 4, 'latitude' => 44.452, 'longitude' => 18.652]);

        $this->postJson(route('map.plan.store'), [
            'project_id' => $project->id,
            'plan' => json_encode(['routes' => [[
                'name' => 'FTTH 2-1 do 2-1.1',
                'route_type' => 'distribution',
                'from_type' => 'cabinet',
                'from_id' => $parent->id,
                'cabinet_id' => $child->id,
                'duct_length_m' => 22,
                'fiber_length_m' => 22,
                'path' => [[44.451, 18.651], [44.452, 18.652]],
            ]]]),
        ])->assertOk();

        $this->assertDatabaseHas('routes', [
            'project_id' => $project->id,
            'name' => 'FTTH 2-1 do 2-1.1',
            'from_type' => 'cabinet',
            'from_id' => $parent->id,
            'cabinet_id' => $child->id,
        ]);
    }

    public function test_auto_odo_preview_works_after_map_draft_plan_is_saved(): void
    {
        $project = Project::create(['name' => 'Draft Auto ODO', 'code' => 'DAO', 'location' => 'Test', 'status' => 'planning']);
        $plan = [
            'odfs' => [['name' => 'ODF-01', 'lat' => 44.4510, 'lng' => 18.6500]],
            'houses' => [
                ['label' => 'K-001', 'lat' => 44.4501, 'lng' => 18.6501],
                ['label' => 'K-002', 'lat' => 44.4503, 'lng' => 18.6501],
            ],
            'routes' => [[
                'name' => 'Krak 1',
                'route_type' => 'distribution',
                'duct_length_m' => 120,
                'fiber_length_m' => 120,
                'path' => [[44.4500, 18.6500], [44.4510, 18.6500]],
            ]],
        ];

        $this->postJson(route('map.plan.store'), ['project_id' => $project->id, 'plan' => json_encode($plan)])->assertOk();

        $this->postJson(route('projects.odo-plan.preview', $project))
            ->assertOk()
            ->assertJsonPath('summary.houses_with_coordinates', 2)
            ->assertJsonPath('branches.0.house_count', 2);
    }

    public function test_auto_odo_preview_only_plans_unconnected_houses(): void
    {
        $project = Project::create(['name' => 'Incremental', 'code' => 'INC', 'location' => 'Test', 'status' => 'planning']);
        $odf = Odf::create(['project_id' => $project->id, 'name' => 'ODF-01', 'address' => 'Centar', 'fiber_capacity' => 144, 'port_count' => 48, 'latitude' => 44.45, 'longitude' => 18.65]);
        $parent = Cabinet::create(['project_id' => $project->id, 'odf_id' => $odf->id, 'name' => 'FTTH 1-2', 'address' => 'Krak', 'splitter_count' => 3, 'ports_per_splitter' => 4, 'latitude' => 44.451, 'longitude' => 18.651]);
        Cabinet::create(['project_id' => $project->id, 'parent_cabinet_id' => $parent->id, 'name' => 'FTTH-1-2.1', 'address' => 'Child', 'splitter_count' => 1, 'ports_per_splitter' => 4, 'latitude' => 44.452, 'longitude' => 18.652]);
        House::create(['project_id' => $project->id, 'cabinet_id' => $parent->id, 'label' => 'Stara', 'latitude' => 44.4511, 'longitude' => 18.6511, 'status' => 'planned']);
        House::create(['project_id' => $project->id, 'label' => 'Nova', 'latitude' => 44.453, 'longitude' => 18.653, 'status' => 'planned']);

        $this->postJson(route('projects.odo-plan.preview', $project))
            ->assertOk()
            ->assertJsonPath('summary.houses_with_coordinates', 1)
            ->assertJsonPath('cabinets.0.houses.0.label', 'Nova');

        $this->assertDatabaseHas('cabinets', ['id' => $parent->id, 'name' => 'FTTH 1-2']);
        $this->assertDatabaseHas('cabinets', ['parent_cabinet_id' => $parent->id, 'name' => 'FTTH-1-2.1']);
    }

    public function test_auto_odo_fills_existing_cabinet_before_proposing_new_one(): void
    {
        $project = Project::create(['name' => 'Fill existing', 'code' => 'FILL', 'location' => 'Test', 'status' => 'planning']);
        $odf = Odf::create(['project_id' => $project->id, 'name' => 'ODF-01', 'address' => 'Centar', 'fiber_capacity' => 144, 'port_count' => 48, 'latitude' => 44.45, 'longitude' => 18.65]);
        $route = NetworkRoute::create(['project_id' => $project->id, 'odf_id' => $odf->id, 'name' => 'Krak 1', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 300, 'fiber_length_m' => 300, 'fiber_count' => 24, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned', 'path' => [[44.45, 18.65], [44.453, 18.653]]]);
        $branch = NetworkBranch::create(['project_id' => $project->id, 'odf_id' => $odf->id, 'route_id' => $route->id, 'name' => 'Krak 1', 'type' => 'secondary']);
        $cabinet = Cabinet::create(['project_id' => $project->id, 'odf_id' => $odf->id, 'branch_id' => $branch->id, 'name' => 'FTTH 1-1', 'address' => 'Krak', 'splitter_count' => 2, 'ports_per_splitter' => 4, 'latitude' => 44.4505, 'longitude' => 18.6505]);
        foreach (range(1, 8) as $index) {
            House::create(['project_id' => $project->id, 'cabinet_id' => $cabinet->id, 'label' => "Stara-{$index}", 'latitude' => 44.4505, 'longitude' => 18.6505, 'status' => 'planned']);
        }
        foreach (range(1, 15) as $index) {
            House::create(['project_id' => $project->id, 'label' => "Nova-{$index}", 'latitude' => 44.4505 + ($index * .00001), 'longitude' => 18.6505 + ($index * .00001), 'status' => 'planned']);
        }

        $response = $this->postJson(route('projects.odo-plan.preview', $project), ['max_house_to_odo_m' => 120])->assertOk();
        $this->assertSame(2, count($response->json('cabinets')));
        $this->assertSame($cabinet->id, $response->json('cabinets.0.existing_cabinet_id'));
        $this->assertSame(12, $response->json('cabinets.0.house_count'));
        $this->assertSame(11, $response->json('cabinets.1.house_count'));
    }

    public function test_confirmed_cabinet_suggestion_links_house_and_creates_drop_route(): void
    {
        $project = Project::create(['name' => 'Auto plan', 'code' => 'AUTO', 'location' => 'Test', 'status' => 'planning']);
        $house = House::create(['project_id' => $project->id, 'label' => 'K-001', 'latitude' => 44.4493, 'longitude' => 18.6498, 'status' => 'planned']);

        $this->postJson(route('map.suggestions.store'), [
            'project_id' => $project->id,
            'cabinets' => [[
                'name' => 'ODO-001',
                'latitude' => 44.4495,
                'longitude' => 18.6500,
                'splitter_count' => 1,
                'houses' => [[
                    'latitude' => 44.4493,
                    'longitude' => 18.6498,
                    'path' => [[44.4495, 18.6500], [44.4494, 18.6499], [44.4493, 18.6498]],
                ]],
            ]],
        ])->assertOk()->assertJson(['created' => 1, 'linked_houses' => 1, 'created_routes' => 1]);

        $this->assertNotNull($house->fresh()->cabinet_id);
        $this->assertDatabaseHas('routes', ['project_id' => $project->id, 'route_type' => 'drop', 'fiber_count' => 4, 'microduct_type' => '10/8']);
        $this->assertCount(3, NetworkRoute::where('route_type', 'drop')->firstOrFail()->path);
    }

    public function test_confirming_same_cabinet_suggestion_twice_does_not_duplicate_cabinets(): void
    {
        $project = Project::create(['name' => 'Auto plan repeat', 'code' => 'AUTOR', 'location' => 'Test', 'status' => 'planning']);
        $house = House::create(['project_id' => $project->id, 'label' => 'K-001', 'latitude' => 44.4493, 'longitude' => 18.6498, 'status' => 'planned']);
        $payload = [
            'project_id' => $project->id,
            'cabinets' => [[
                'name' => 'ODO-001',
                'latitude' => 44.4495,
                'longitude' => 18.6500,
                'splitter_count' => 1,
                'houses' => [['id' => $house->id, 'latitude' => 44.4493, 'longitude' => 18.6498]],
            ]],
        ];

        $this->postJson(route('map.suggestions.store'), $payload)->assertOk()->assertJson(['created' => 1, 'linked_houses' => 1]);
        $this->postJson(route('map.suggestions.store'), $payload)->assertOk()->assertJson(['created' => 0, 'linked_houses' => 0]);

        $this->assertSame(1, Cabinet::where('project_id', $project->id)->count());
        $this->assertSame($house->fresh()->cabinet_id, Cabinet::where('project_id', $project->id)->firstOrFail()->id);
    }

    public function test_route_drawn_on_map_is_measured_by_server_and_returned_as_json(): void
    {
        $project = Project::create(['name' => 'Crtanje', 'code' => 'CRTANJE', 'location' => 'Test', 'status' => 'planning']);
        $path = [[44.4493, 18.6498], [44.4503, 18.6508]];

        $response = $this->postJson(route('routes.store'), [
            'project_id' => $project->id,
            'name' => 'Trasa sa mape',
            'route_type' => 'distribution',
            'installation_type' => 'underground',
            'duct_length_m' => 1,
            'fiber_length_m' => 1,
            'fiber_count' => 12,
            'microduct_count' => 1,
            'microduct_type' => '14/10',
            'status' => 'planned',
            'path' => json_encode($path),
        ]);

        $response->assertCreated()->assertJsonPath('route.name', 'Trasa sa mape');
        $this->assertGreaterThan(100, $response->json('route.length'));
        $this->assertDatabaseHas('routes', ['project_id' => $project->id, 'name' => 'Trasa sa mape', 'fiber_count' => 12]);
    }

    public function test_deleting_cabinet_releases_houses_and_removes_only_its_drop_routes(): void
    {
        $project = Project::create(['name' => 'Replan', 'code' => 'REPLAN', 'location' => 'Test', 'status' => 'planning']);
        $cabinet = Cabinet::create(['project_id' => $project->id, 'name' => 'ODO-001', 'address' => 'Test', 'splitter_count' => 2, 'ports_per_splitter' => 4]);
        $house = House::create(['project_id' => $project->id, 'cabinet_id' => $cabinet->id, 'label' => 'K-001', 'latitude' => 44.4493, 'longitude' => 18.6498, 'status' => 'planned']);
        NetworkRoute::create(['project_id' => $project->id, 'cabinet_id' => $cabinet->id, 'name' => 'Drop ODO-001-1', 'route_type' => 'drop', 'installation_type' => 'underground', 'duct_length_m' => 10, 'fiber_length_m' => 10, 'fiber_count' => 4, 'microduct_count' => 1, 'microduct_type' => '10/8', 'status' => 'planned']);
        $distribution = NetworkRoute::create(['project_id' => $project->id, 'cabinet_id' => $cabinet->id, 'name' => 'Glavna', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 20, 'fiber_length_m' => 20, 'fiber_count' => 12, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned']);

        $this->deleteJson(route('cabinets.delete', $cabinet))->assertOk();

        $this->assertNull($house->fresh()->cabinet_id);
        $this->assertDatabaseMissing('routes', ['name' => 'Drop ODO-001-1']);
        $this->assertDatabaseHas('routes', ['id' => $distribution->id, 'name' => 'Glavna']);
    }

    public function test_route_geometry_edit_recalculates_length(): void
    {
        $project = Project::create(['name' => 'Edit', 'code' => 'EDIT', 'location' => 'Test', 'status' => 'planning']);
        $route = NetworkRoute::create(['project_id' => $project->id, 'name' => 'T1', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 1, 'fiber_length_m' => 1, 'fiber_count' => 12, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned', 'path' => [[44.4493, 18.6498], [44.4494, 18.6499]]]);

        $response = $this->patchJson(route('routes.geometry.update', $route), [
            'path' => [[44.4493, 18.6498], [44.4503, 18.6508]],
        ]);

        $response->assertOk()->assertJsonPath('route.id', $route->id);
        $this->assertGreaterThan(100, $route->fresh()->duct_length_m);
        $this->assertSame($route->fresh()->duct_length_m, $route->fresh()->fiber_length_m);
    }

    public function test_route_metadata_can_be_updated_without_changing_geometry(): void
    {
        $project = Project::create(['name' => 'Meta', 'code' => 'META', 'location' => 'Test', 'status' => 'planning']);
        $cabinet = Cabinet::create(['project_id' => $project->id, 'name' => 'FTTH-01', 'address' => 'Krak', 'splitter_count' => 3, 'ports_per_splitter' => 4]);
        $house = House::create(['project_id' => $project->id, 'cabinet_id' => $cabinet->id, 'label' => 'K-001', 'status' => 'planned']);
        $route = NetworkRoute::create(['project_id' => $project->id, 'name' => 'Stari naziv', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 25, 'fiber_length_m' => 25, 'fiber_count' => 12, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned', 'path' => [[44.4493, 18.6498], [44.4494, 18.6499]]]);

        $this->patchJson(route('routes.update', $route), [
            'name' => 'Novi naziv',
            'route_type' => 'drop',
            'microduct_type' => '10/8',
            'fiber_count' => 4,
            'from_type' => 'cabinet',
            'from_id' => $cabinet->id,
            'to_type' => 'house',
            'to_id' => $house->id,
            'cabinet_id' => $cabinet->id,
        ])->assertOk()->assertJsonPath('route.name', 'Novi naziv');

        $this->assertDatabaseHas('routes', ['id' => $route->id, 'name' => 'Novi naziv', 'route_type' => 'drop', 'fiber_count' => 4, 'to_type' => 'house', 'to_id' => $house->id]);
        $this->assertSame([[44.4493, 18.6498], [44.4494, 18.6499]], $route->fresh()->path);
    }

    public function test_route_cannot_link_assets_from_another_project(): void
    {
        $project = Project::create(['name' => 'Prvi', 'code' => 'PRVI', 'location' => 'Test', 'status' => 'planning']);
        $otherProject = Project::create(['name' => 'Drugi', 'code' => 'DRUGI', 'location' => 'Test', 'status' => 'planning']);
        $otherOdfId = DB::table('odfs')->insertGetId(['project_id' => $otherProject->id, 'name' => 'ODF-2', 'address' => 'Test', 'fiber_capacity' => 144, 'port_count' => 48]);

        $response = $this->postJson(route('routes.store'), [
            'project_id' => $project->id,
            'odf_id' => $otherOdfId,
            'name' => 'Pogresna veza',
            'route_type' => 'distribution',
            'installation_type' => 'underground',
            'duct_length_m' => 10,
            'fiber_length_m' => 10,
            'fiber_count' => 12,
            'microduct_count' => 1,
            'microduct_type' => '14/10',
            'status' => 'planned',
        ]);

        $response->assertUnprocessable();

        $this->assertDatabaseMissing('routes', ['name' => 'Pogresna veza']);
    }

    public function test_odf_can_be_connected_to_cabinet_with_backbone_route(): void
    {
        $project = Project::create(['name' => 'Connect', 'code' => 'CONNECT', 'location' => 'Test', 'status' => 'planning']);
        $odf = Odf::create(['project_id' => $project->id, 'name' => 'ODF-01', 'address' => 'Centar', 'fiber_capacity' => 144, 'port_count' => 48, 'latitude' => 44.45, 'longitude' => 18.65]);
        $cabinet = Cabinet::create(['project_id' => $project->id, 'odf_id' => $odf->id, 'name' => 'FTTH-01', 'address' => 'Krak', 'splitter_count' => 3, 'ports_per_splitter' => 4, 'latitude' => 44.451, 'longitude' => 18.651]);

        $this->postJson(route('routes.store'), [
            'project_id' => $project->id,
            'odf_id' => $odf->id,
            'cabinet_id' => $cabinet->id,
            'from_type' => 'odf',
            'from_id' => $odf->id,
            'to_type' => 'cabinet',
            'to_id' => $cabinet->id,
            'name' => 'ODF-01 - FTTH-01',
            'route_type' => 'backbone',
            'installation_type' => 'underground',
            'duct_length_m' => 0,
            'fiber_length_m' => 0,
            'fiber_count' => 24,
            'microduct_count' => 1,
            'microduct_type' => '14/10',
            'status' => 'planned',
            'path' => json_encode([[44.45, 18.65], [44.451, 18.651]]),
        ])->assertCreated();

        $this->assertDatabaseHas('routes', [
            'project_id' => $project->id,
            'odf_id' => $odf->id,
            'cabinet_id' => $cabinet->id,
            'from_type' => 'odf',
            'from_id' => $odf->id,
            'to_type' => 'cabinet',
            'to_id' => $cabinet->id,
            'route_type' => 'backbone',
            'fiber_count' => 24,
        ]);
    }

    public function test_cabinet_can_connect_houses_and_create_drop_routes(): void
    {
        $project = Project::create(['name' => 'Drop connect', 'code' => 'DROP', 'location' => 'Test', 'status' => 'planning']);
        $cabinet = Cabinet::create(['project_id' => $project->id, 'name' => 'FTTH-01', 'address' => 'Krak', 'splitter_count' => 3, 'ports_per_splitter' => 4, 'latitude' => 44.45, 'longitude' => 18.65]);
        $house = House::create(['project_id' => $project->id, 'label' => 'K-001', 'latitude' => 44.451, 'longitude' => 18.651, 'status' => 'planned']);

        $this->postJson(route('cabinets.houses.connect', $cabinet), ['house_ids' => [$house->id]])
            ->assertOk()
            ->assertJsonCount(1, 'routes');

        $this->assertDatabaseHas('houses', ['id' => $house->id, 'cabinet_id' => $cabinet->id]);
        $this->assertDatabaseHas('routes', ['cabinet_id' => $cabinet->id, 'to_type' => 'house', 'to_id' => $house->id, 'route_type' => 'drop', 'fiber_count' => 4]);
    }

    public function test_project_can_fill_missing_drop_routes_from_existing_branch_geometry(): void
    {
        $project = Project::create(['name' => 'Drop fill', 'code' => 'FILL', 'location' => 'Test', 'status' => 'planning']);
        $cabinet = Cabinet::create(['project_id' => $project->id, 'name' => 'FTTH-01', 'address' => 'Krak', 'splitter_count' => 3, 'ports_per_splitter' => 4, 'latitude' => 44.45, 'longitude' => 18.65]);
        $house = House::create(['project_id' => $project->id, 'cabinet_id' => $cabinet->id, 'label' => 'K-001', 'latitude' => 44.452, 'longitude' => 18.652, 'status' => 'planned']);
        NetworkRoute::create(['project_id' => $project->id, 'name' => 'Sekundarni krak 1.1', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 300, 'fiber_length_m' => 300, 'fiber_count' => 12, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned', 'path' => [[44.45, 18.65], [44.451, 18.651], [44.452, 18.652]]]);

        $this->postJson(route('projects.drop-routes.fill', $project))
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonCount(1, 'routes')
            ->assertJsonPath('routes.0.to_type', 'house');

        $drop = NetworkRoute::where('route_type', 'drop')->firstOrFail();
        $this->assertSame($house->id, $drop->to_id);
        $this->assertGreaterThanOrEqual(3, count($drop->path));
    }

    public function test_two_routes_with_near_endpoints_can_be_joined(): void
    {
        $project = Project::create(['name' => 'Join', 'code' => 'JOIN', 'location' => 'Test', 'status' => 'planning']);
        $first = NetworkRoute::create(['project_id' => $project->id, 'name' => 'A', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 10, 'fiber_length_m' => 10, 'fiber_count' => 12, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned', 'path' => [[44.45, 18.65], [44.451, 18.651]]]);
        $second = NetworkRoute::create(['project_id' => $project->id, 'name' => 'B', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 10, 'fiber_length_m' => 10, 'fiber_count' => 12, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned', 'path' => [[44.451, 18.651], [44.452, 18.652]]]);

        $this->postJson(route('routes.join', [$first, $second]))
            ->assertOk()
            ->assertJsonPath('deleted_route_id', $second->id)
            ->assertJsonCount(3, 'route.path');

        $this->assertDatabaseMissing('routes', ['id' => $second->id]);
        $this->assertCount(3, $first->fresh()->path);
    }

    public function test_plan_save_rolls_back_when_an_item_is_invalid(): void
    {
        $project = Project::create(['name' => 'Rollback', 'code' => 'ROLLBACK', 'location' => 'Test', 'status' => 'planning']);
        $plan = [
            'odfs' => [['name' => 'ODF-1', 'lat' => 44.4493, 'lng' => 18.6498]],
            'cabinets' => [['name' => 'ODO-1', 'lat' => 44.4495]],
        ];

        $this->postJson(route('map.plan.store'), ['project_id' => $project->id, 'plan' => json_encode($plan)])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('odfs', ['project_id' => $project->id, 'name' => 'ODF-1']);
    }

    public function test_deleting_route_removes_its_branch_and_releases_cabinets(): void
    {
        $project = Project::create(['name' => 'Delete route', 'code' => 'DEL', 'location' => 'Test', 'status' => 'planning']);
        $route = NetworkRoute::create(['project_id' => $project->id, 'name' => 'Krak', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 10, 'fiber_length_m' => 10, 'fiber_count' => 12, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned', 'path' => [[44.45, 18.65], [44.451, 18.651]]]);
        $branch = NetworkBranch::create(['project_id' => $project->id, 'route_id' => $route->id, 'name' => 'Krak', 'type' => 'secondary']);
        $cabinet = Cabinet::create(['project_id' => $project->id, 'branch_id' => $branch->id, 'name' => 'FTTH-1', 'address' => 'Test', 'splitter_count' => 3, 'ports_per_splitter' => 4]);

        $this->deleteJson(route('routes.delete', $route))->assertOk();

        $this->assertDatabaseMissing('network_branches', ['id' => $branch->id]);
        $this->assertNull($cabinet->fresh()->branch_id);
    }

    public function test_join_routes_merges_branch_cabinets_into_primary_branch(): void
    {
        $project = Project::create(['name' => 'Join branches', 'code' => 'JOIN-B', 'location' => 'Test', 'status' => 'planning']);
        $first = NetworkRoute::create(['project_id' => $project->id, 'name' => 'A', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 10, 'fiber_length_m' => 10, 'fiber_count' => 12, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned', 'path' => [[44.45, 18.65], [44.451, 18.651]]]);
        $second = NetworkRoute::create(['project_id' => $project->id, 'name' => 'B', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 10, 'fiber_length_m' => 10, 'fiber_count' => 12, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned', 'path' => [[44.451, 18.651], [44.452, 18.652]]]);
        $firstBranch = NetworkBranch::create(['project_id' => $project->id, 'route_id' => $first->id, 'name' => 'A', 'type' => 'secondary']);
        $secondBranch = NetworkBranch::create(['project_id' => $project->id, 'route_id' => $second->id, 'name' => 'B', 'type' => 'secondary']);
        $cabinet = Cabinet::create(['project_id' => $project->id, 'branch_id' => $secondBranch->id, 'name' => 'FTTH-B', 'address' => 'Test', 'splitter_count' => 3, 'ports_per_splitter' => 4]);

        $this->postJson(route('routes.join', [$first, $second]))->assertOk();

        $this->assertDatabaseMissing('network_branches', ['id' => $secondBranch->id]);
        $this->assertSame($firstBranch->id, $cabinet->fresh()->branch_id);
    }

    public function test_cabinet_parent_cycle_is_rejected(): void
    {
        $project = Project::create(['name' => 'Cycle', 'code' => 'CYCLE', 'location' => 'Test', 'status' => 'planning']);
        $parent = Cabinet::create(['project_id' => $project->id, 'name' => 'Parent', 'address' => 'Test', 'splitter_count' => 3, 'ports_per_splitter' => 4]);
        $child = Cabinet::create(['project_id' => $project->id, 'parent_cabinet_id' => $parent->id, 'name' => 'Child', 'address' => 'Test', 'splitter_count' => 3, 'ports_per_splitter' => 4]);

        $this->patch(route('cabinets.update', $parent), [
            'project_id' => $project->id,
            'parent_cabinet_id' => $child->id,
            'name' => $parent->name,
            'address' => $parent->address,
            'splitter_count' => 3,
            'ports_per_splitter' => 4,
        ])->assertSessionHasErrors('parent_cabinet_id');

        $this->assertNull($parent->fresh()->parent_cabinet_id);
    }

    public function test_branch_parent_cycle_is_rejected(): void
    {
        $project = Project::create(['name' => 'Branch cycle', 'code' => 'BCYCLE', 'location' => 'Test', 'status' => 'planning']);
        $parent = NetworkBranch::create(['project_id' => $project->id, 'name' => 'Parent', 'type' => 'primary']);
        $child = NetworkBranch::create(['project_id' => $project->id, 'parent_branch_id' => $parent->id, 'name' => 'Child', 'type' => 'secondary']);

        $this->patchJson(route('branches.update', $parent), [
            'project_id' => $project->id,
            'parent_branch_id' => $child->id,
            'name' => $parent->name,
            'type' => 'primary',
            'sort_order' => 0,
        ])->assertUnprocessable();

        $this->assertNull($parent->fresh()->parent_branch_id);
    }

    public function test_resaving_plan_reuses_odf_at_same_position_instead_of_duplicating(): void
    {
        $project = Project::create(['name' => 'Re-save', 'code' => 'RESAVE', 'location' => 'Test', 'status' => 'planning']);
        $plan = ['odfs' => [['name' => 'ODF-01', 'lat' => 44.4034196, 'lng' => 18.5256805]]];

        $this->postJson(route('map.plan.store'), ['project_id' => $project->id, 'plan' => json_encode($plan)])->assertOk();
        $this->postJson(route('map.plan.store'), ['project_id' => $project->id, 'plan' => json_encode($plan)])->assertOk();
        $this->postJson(route('map.plan.store'), ['project_id' => $project->id, 'plan' => json_encode($plan)])->assertOk();

        $this->assertSame(1, Odf::where('project_id', $project->id)->count());
    }

    public function test_resaving_plan_does_not_duplicate_routes_with_same_geometry(): void
    {
        $project = Project::create(['name' => 'Re-save trase', 'code' => 'RESAVE2', 'location' => 'Test', 'status' => 'planning']);
        // The cable ("distribution") and the trench ("trench") deliberately share
        // the SAME path — a cable laid inside a trench — and must both persist.
        $sharedPath = [[44.4493, 18.6498], [44.4500, 18.6505]];
        $plan = ['routes' => [
            ['name' => 'Sekundarni krak I-3', 'route_type' => 'distribution', 'duct_length_m' => 42, 'path' => $sharedPath],
            ['name' => 'Glavni rov 1', 'route_type' => 'trench', 'duct_length_m' => 30, 'path' => $sharedPath],
        ]];

        $this->postJson(route('map.plan.store'), ['project_id' => $project->id, 'plan' => json_encode($plan)])->assertOk();
        $this->postJson(route('map.plan.store'), ['project_id' => $project->id, 'plan' => json_encode($plan)])->assertOk();
        $this->postJson(route('map.plan.store'), ['project_id' => $project->id, 'plan' => json_encode($plan)])->assertOk();

        // Cable + trench on the same path both persist, but re-saves add nothing.
        $this->assertSame(2, NetworkRoute::where('project_id', $project->id)->count());
        $this->assertSame(1, NetworkRoute::where('project_id', $project->id)->where('route_type', 'distribution')->count());
        $this->assertSame(1, NetworkRoute::where('project_id', $project->id)->where('route_type', 'trench')->count());
        $this->assertSame(0, NetworkRoute::where('project_id', $project->id)->where('name', 'like', '%-2')->count());
        $this->assertSame(1, NetworkBranch::where('project_id', $project->id)->count());

        // The same cable drawn in the REVERSED direction is still a duplicate.
        $reversedPlan = ['routes' => [
            ['name' => 'Sekundarni krak I-3 obrnuto', 'route_type' => 'distribution', 'duct_length_m' => 42, 'path' => array_reverse($sharedPath)],
        ]];
        $this->postJson(route('map.plan.store'), ['project_id' => $project->id, 'plan' => json_encode($reversedPlan)])->assertOk();
        $this->assertSame(1, NetworkRoute::where('project_id', $project->id)->where('route_type', 'distribution')->count());
    }
}
