<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\FiberSplice;
use App\Models\House;
use App\Models\Material;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_backup_can_be_downloaded_and_restored_with_relationships(): void
    {
        $project = Project::create([
            'name' => 'Originalni projekat',
            'code' => 'BACKUP-1',
            'location' => 'Tuzla',
            'investor' => 'Investitor',
            'status' => 'active',
        ]);
        $odf = Odf::create(['project_id' => $project->id, 'name' => 'ODF 1', 'address' => 'Centar']);
        $cabinet = Cabinet::create(['project_id' => $project->id, 'odf_id' => $odf->id, 'name' => 'ODO 1', 'address' => 'Ulica 1']);
        $house = House::create(['project_id' => $project->id, 'cabinet_id' => $cabinet->id, 'label' => 'K-001', 'address' => 'Ulica 2']);
        $route = NetworkRoute::create([
            'project_id' => $project->id,
            'odf_id' => $odf->id,
            'cabinet_id' => $cabinet->id,
            'from_type' => 'cabinet',
            'from_id' => $cabinet->id,
            'to_type' => 'house',
            'to_id' => $house->id,
            'name' => 'Trasa 1',
            'trench_group' => 'ROV-A',
            'counts_as_trench' => true,
            'trench_length_m' => 95,
            'path' => [[44.5, 18.6], [44.6, 18.7]],
        ]);
        $branch = NetworkBranch::create([
            'project_id' => $project->id, 'odf_id' => $odf->id, 'route_id' => $route->id,
            'name' => 'Krak 1', 'code' => '1', 'type' => 'primary',
        ]);
        $cabinet->update(['branch_id' => $branch->id]);
        $house->update(['branch_id' => $branch->id]);
        $project->update([
            'fiber_budget_limit_db' => 31,
            'fiber_schema_layout' => [
                'odf-'.$odf->id => ['x' => 100, 'y' => 120],
                'cab-'.$cabinet->id => ['x' => 300, 'y' => 220],
            ],
        ]);
        FiberSplice::create([
            'project_id' => $project->id, 'cabinet_id' => $cabinet->id, 'fiber_number' => 1,
            'tray' => 2, 'position' => 3, 'incoming_label' => 'ODF-F1', 'outgoing_label' => 'ODO-F1',
            'loss_db' => .11, 'note' => 'Terensko varenje',
        ]);
        Material::create([
            'project_id' => $project->id, 'name' => 'Kabl 24F', 'unit' => 'm',
            'planned_quantity' => 120, 'used_quantity' => 25, 'unit_price' => 1.5,
        ]);
        DB::table('project_appendix_items')->insert([
            'project_id' => $project->id, 'type' => 'šaht', 'quantity' => 2, 'unit' => 'KOMADA',
        ]);
        DB::table('gis_segments')->insert([
            'project_id' => $project->id, 'name' => 'Cesta A', 'source' => 'geojson',
            'segment_type' => 'road', 'is_allowed' => true, 'length_m' => 100,
            'path' => json_encode([[44.5, 18.6], [44.6, 18.7]]),
        ]);
        DB::table('map_drafts')->insert([
            'project_id' => $project->id, 'payload' => json_encode(['zoom' => 14]),
        ]);

        $backupResponse = $this->get(route('projects.backup', $project));
        $backupResponse->assertOk()->assertDownload();
        $this->assertStringContainsString('backup-1-backup-', $backupResponse->headers->get('content-disposition'));

        $backup = $backupResponse->json();
        $this->assertSame('K-001', $backup['data']['houses'][0]['label']);
        $this->assertSame(1, $backup['summary']['houses']);
        $this->assertSame(1, $backup['summary']['gis_segments']);
        $this->assertSame(1, $backup['summary']['fiber_splices']);
        $this->assertContains('survey_point_photos', $backup['excluded_files']);

        $response = $this->post(route('projects.restore'), [
            'backup' => UploadedFile::fake()->createWithContent('backup.json', json_encode($backup)),
            'project_name' => 'Vraćeni projekat',
        ]);

        $response->assertRedirect(route('projects.index'));
        $restored = Project::where('name', 'Vraćeni projekat')->firstOrFail();

        $this->assertNotSame($project->code, $restored->code);
        $this->assertDatabaseHas('odfs', ['project_id' => $restored->id, 'name' => 'ODF 1']);
        $this->assertDatabaseHas('cabinets', ['project_id' => $restored->id, 'name' => 'ODO 1']);
        $this->assertDatabaseHas('houses', ['project_id' => $restored->id, 'label' => 'K-001']);
        $this->assertDatabaseHas('routes', ['project_id' => $restored->id, 'name' => 'Trasa 1']);
        $this->assertDatabaseHas('network_branches', ['project_id' => $restored->id, 'name' => 'Krak 1']);
        $this->assertDatabaseHas('materials', ['project_id' => $restored->id, 'name' => 'Kabl 24F']);
        $this->assertDatabaseHas('project_appendix_items', ['project_id' => $restored->id, 'type' => 'šaht']);
        $this->assertDatabaseHas('gis_segments', ['project_id' => $restored->id, 'name' => 'Cesta A']);
        $this->assertDatabaseHas('map_drafts', ['project_id' => $restored->id]);
        $this->assertDatabaseHas('fiber_splices', [
            'project_id' => $restored->id, 'fiber_number' => 1, 'tray' => 2, 'position' => 3,
            'incoming_label' => 'ODF-F1', 'outgoing_label' => 'ODO-F1', 'note' => 'Terensko varenje',
        ]);

        $restoredCabinet = Cabinet::where('project_id', $restored->id)->firstOrFail();
        $restoredHouse = House::where('project_id', $restored->id)->firstOrFail();
        $restoredRoute = NetworkRoute::where('project_id', $restored->id)->firstOrFail();
        $restoredBranch = NetworkBranch::where('project_id', $restored->id)->firstOrFail();
        $this->assertSame($restoredBranch->id, $restoredCabinet->branch_id);
        $this->assertSame($restoredBranch->id, $restoredHouse->branch_id);
        $this->assertSame($restoredCabinet->id, $restoredRoute->from_id);
        $this->assertSame($restoredHouse->id, $restoredRoute->to_id);
        $this->assertSame($restoredRoute->id, $restoredBranch->route_id);
        $this->assertSame('ROV-A', $restoredRoute->trench_group);
        $this->assertTrue($restoredRoute->counts_as_trench);
        $this->assertSame(95, $restoredRoute->trench_length_m);
        $this->assertSame(31.0, $restored->fiber_budget_limit_db);
        $this->assertSame(['x' => 100, 'y' => 120], $restored->fiber_schema_layout['odf-'.$restored->odfs()->sole()->id]);
        $this->assertSame(['x' => 300, 'y' => 220], $restored->fiber_schema_layout['cab-'.$restoredCabinet->id]);
        $this->assertSame($restoredCabinet->id, $restored->fiberSplices()->sole()->cabinet_id);
    }

    public function test_restore_rejects_an_unrelated_json_file(): void
    {
        $response = $this->from(route('projects.index'))->post(route('projects.restore'), [
            'backup' => UploadedFile::fake()->createWithContent('other.json', '{"hello":"world"}'),
        ]);

        $response->assertRedirect(route('projects.index'));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_restore_file_validation_uses_its_own_error_bag(): void
    {
        $this->from(route('projects.index'))->post(route('projects.restore'))
            ->assertRedirect(route('projects.index'))
            ->assertSessionHasErrors('backup', null, 'restoreBackup');
    }

    public function test_restore_reports_malformed_json_without_exposing_internal_error(): void
    {
        $this->from(route('projects.index'))->post(route('projects.restore'), [
            'backup' => UploadedFile::fake()->createWithContent('broken.json', '{broken'),
        ])->assertRedirect(route('projects.index'))
            ->assertSessionHas('error', 'Backup datoteka nije ispravan JSON dokument.');
    }

    public function test_failed_restore_rolls_back_the_new_project_and_all_rows(): void
    {
        $backup = [
            'format' => 'ftth-manager-project-backup',
            'version' => 1,
            'project' => ['name' => 'Rollback', 'code' => 'ROLLBACK', 'location' => 'Test'],
            'data' => [
                'odfs' => [['id' => 1, 'name' => 'ODF bez obavezne adrese']],
            ],
        ];

        $this->from(route('projects.index'))->post(route('projects.restore'), [
            'backup' => UploadedFile::fake()->createWithContent('invalid-data.json', json_encode($backup)),
        ])->assertRedirect(route('projects.index'))
            ->assertSessionHas('error', 'Backup nije moguće vratiti. Provjeri datoteku i pokušaj ponovo.');

        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('odfs', 0);
    }

    public function test_restore_rejects_backup_whose_manifest_does_not_match_data(): void
    {
        $backup = [
            'format' => 'ftth-manager-project-backup',
            'version' => 1,
            'project' => ['name' => 'Promijenjen', 'code' => 'TAMPERED', 'location' => 'Test'],
            'data' => ['houses' => []],
            'summary' => ['houses' => 2],
        ];

        $this->from(route('projects.index'))->post(route('projects.restore'), [
            'backup' => UploadedFile::fake()->createWithContent('tampered.json', json_encode($backup)),
        ])->assertRedirect(route('projects.index'))
            ->assertSessionHas('error', 'Datoteka nije podržani FTTH Manager backup.');

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_restore_clears_relationships_whose_parent_is_missing_from_backup(): void
    {
        $backup = [
            'format' => 'ftth-manager-project-backup',
            'version' => 1,
            'project' => ['name' => 'Nepotpun backup', 'code' => 'PARTIAL', 'location' => 'Test', 'status' => 'planning'],
            'data' => [
                'odfs' => [],
                'cabinets' => [[
                    'id' => 20, 'name' => 'ODO', 'address' => 'Test', 'latitude' => null,
                    'longitude' => null, 'splitter_count' => 1, 'odf_id' => 999,
                ]],
                'houses' => [[
                    'id' => 30, 'label' => 'K-1', 'address' => null, 'latitude' => null,
                    'longitude' => null, 'status' => 'planned', 'cabinet_id' => 999,
                ]],
                'routes' => [],
            ],
        ];

        $this->post(route('projects.restore'), [
            'backup' => UploadedFile::fake()->createWithContent('partial.json', json_encode($backup)),
        ])->assertRedirect(route('projects.index'));

        $restored = Project::where('code', 'PARTIAL')->firstOrFail();
        $this->assertDatabaseHas('cabinets', ['project_id' => $restored->id, 'odf_id' => null]);
        $this->assertDatabaseHas('houses', ['project_id' => $restored->id, 'cabinet_id' => null]);
    }

    public function test_restore_strips_internal_columns_from_untrusted_rows(): void
    {
        $backup = [
            'format' => 'ftth-manager-project-backup', 'version' => 1,
            'project' => ['name' => 'Siguran restore', 'code' => 'SAFE', 'location' => 'Test'],
            'data' => ['map_drafts' => [[
                'id' => 999999, 'project_id' => 999999, 'created_at' => '2000-01-01 00:00:00',
                'payload' => json_encode(['zoom' => 12]), 'nepostojeca_kolona' => 'napad',
            ]]],
        ];

        $this->post(route('projects.restore'), [
            'backup' => UploadedFile::fake()->createWithContent('safe.json', json_encode($backup)),
        ])->assertRedirect(route('projects.index'));

        $project = Project::where('code', 'SAFE')->firstOrFail();
        $draft = DB::table('map_drafts')->where('project_id', $project->id)->first();
        $this->assertNotNull($draft);
        $this->assertNotSame(999999, $draft->id);
        $this->assertSame($project->id, $draft->project_id);
    }

    public function test_restore_rejects_non_array_table_rows_before_writing(): void
    {
        $backup = [
            'format' => 'ftth-manager-project-backup', 'version' => 1,
            'project' => ['name' => 'Los red', 'code' => 'BAD-ROW', 'location' => 'Test'],
            'data' => ['houses' => ['nije-red']],
        ];

        $this->from(route('projects.index'))->post(route('projects.restore'), [
            'backup' => UploadedFile::fake()->createWithContent('bad-row.json', json_encode($backup)),
        ])->assertRedirect(route('projects.index'))->assertSessionHas('error');

        $this->assertDatabaseCount('projects', 0);
    }
}
