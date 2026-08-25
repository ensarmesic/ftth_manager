<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\User;
use App\Services\FiberPlanService;
use App\Services\ProjectBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LargeProjectRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_large_project_dashboard_and_overview_render_without_errors(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $odf = Odf::factory()->create(['project_id' => $project->id, 'fiber_capacity' => 288]);
        $feeder = NetworkRoute::factory()->create([
            'project_id' => $project->id, 'odf_id' => $odf->id, 'route_type' => 'distribution',
            'name' => 'LAZY-PAYLOAD-ROUTE-SENTINEL', 'fiber_length_m' => 5000, 'fiber_count' => 288,
        ]);
        $branch = NetworkBranch::factory()->create([
            'project_id' => $project->id, 'odf_id' => $odf->id, 'route_id' => $feeder->id,
        ]);
        $cabinets = Cabinet::factory()->count(200)->create([
            'project_id' => $project->id, 'odf_id' => $odf->id, 'branch_id' => $branch->id,
        ]);
        foreach ($cabinets as $index => $cabinet) {
            House::factory()->count($index < 100 ? 2 : 1)->create([
                'project_id' => $project->id, 'cabinet_id' => $cabinet->id,
            ]);
        }
        NetworkRoute::factory()->count(120)->create(['project_id' => $project->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $dashboardQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(15, $dashboardQueries, "Dashboard je izvršio {$dashboardQueries} SQL upita.");

        DB::flushQueryLog();
        DB::enableQueryLog();
        $mapStarted = hrtime(true);
        $mapResponse = $this->get(route('map.dashboard', ['project' => $project->id]))->assertOk();
        $mapDurationMs = (hrtime(true) - $mapStarted) / 1_000_000;
        $mapQueries = count(DB::getQueryLog());
        $mapPayloadBytes = strlen($mapResponse->getContent());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(30, $mapQueries, "Mapa velikog projekta je izvršila {$mapQueries} SQL upita.");
        $this->assertLessThan(5000, $mapDurationMs, 'Mapa velikog projekta traje duže od 5 sekundi.');
        $this->assertLessThan(5_000_000, $mapPayloadBytes, 'Početni HTML jednog velikog projekta prelazi 5 MB.');
        $mapResponse->assertSee('LAZY-PAYLOAD-ROUTE-SENTINEL')->assertDontSee('Učitavam crtež odabranog projekta');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $pickerResponse = $this->get(route('map.dashboard'))->assertOk();
        $pickerQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $pickerResponse->assertDontSee('LAZY-PAYLOAD-ROUTE-SENTINEL');
        $this->assertLessThanOrEqual(8, $pickerQueries, "Izbor projekta je izvršio {$pickerQueries} SQL upita.");

        DB::flushQueryLog();
        DB::enableQueryLog();
        $overviewStarted = hrtime(true);
        $this->get(route('projects.show', $project))->assertOk()->assertSee($project->name);
        $overviewDurationMs = (hrtime(true) - $overviewStarted) / 1_000_000;
        $overviewQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(30, $overviewQueries, "Pregled velikog projekta je izvršio {$overviewQueries} SQL upita.");
        $this->assertLessThan(5000, $overviewDurationMs, 'Pregled velikog projekta traje duže od 5 sekundi.');
        $this->get(route('projects.print', $project))->assertOk()->assertSee('Spremnost projekta');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $fiberPageStarted = hrtime(true);
        $this->get(route('fiber-schema.index', ['project' => $project->id]))->assertOk()->assertSee($project->name);
        $fiberPageDurationMs = (hrtime(true) - $fiberPageStarted) / 1_000_000;
        $fiberPageQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(35, $fiberPageQueries, "Fiber prikaz velikog projekta je izvršio {$fiberPageQueries} SQL upita.");
        $this->assertLessThan(5000, $fiberPageDurationMs, 'Fiber prikaz velikog projekta traje duže od 5 sekundi.');

        $project->unsetRelations();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fiberPlan = app(FiberPlanService::class)->build($project);
        $fiberQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(200, $fiberPlan['allocations']);
        $this->assertSame(200, $fiberPlan['usedFibers']);
        $this->assertLessThanOrEqual(10, $fiberQueries, "Fiber plan velikog projekta je izvršio {$fiberQueries} SQL upita.");

        $project->refresh()->load(['odfs', 'branches', 'cabinets', 'houses', 'routes', 'materials', 'fiberSplices']);
        $backup = app(ProjectBackupService::class)->backup($project);
        $this->assertSame(200, $backup['summary']['cabinets']);
        $this->assertSame(300, $backup['summary']['houses']);
        $this->assertSame(121, $backup['summary']['routes']);
    }

    public function test_map_data_api_remains_bounded_with_five_thousand_houses(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $cabinet = Cabinet::factory()->create(['project_id' => $project->id]);
        $now = now();

        foreach (array_chunk(range(1, 5000), 500) as $numbers) {
            DB::table('houses')->insert(array_map(fn (int $number): array => [
                'project_id' => $project->id,
                'cabinet_id' => $cabinet->id,
                'label' => 'LOAD-'.$number,
                'address' => null,
                'latitude' => 44.4 + (($number % 100) * 0.00001),
                'longitude' => 18.6 + ((intdiv($number, 100) % 100) * 0.00001),
                'status' => 'planned',
                'created_at' => $now,
                'updated_at' => $now,
            ], $numbers));
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $startedAt = hrtime(true);
        $response = $this->actingAs($user)
            ->getJson(route('api.projects.map-data', $project))
            ->assertOk();
        $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(5000, $response->json('houses'));
        $this->assertLessThanOrEqual(30, $queryCount, "Map API je izvršio {$queryCount} SQL upita.");
        $this->assertLessThan(10_000, $durationMs, 'Map API za 5.000 kuća traje duže od 10 sekundi.');
        $this->assertLessThan(15_000_000, strlen($response->getContent()), 'Map API payload za 5.000 kuća prelazi 15 MB.');
    }
}
