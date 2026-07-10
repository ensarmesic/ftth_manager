<?php

namespace Database\Seeders;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\Material;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $project = Project::firstOrCreate(
            ['code' => 'FTTH-SARAJEVO-01'],
            [
                'name' => 'FTTH Sarajevo Centar',
                'location' => 'Sarajevo - Centar',
                'status' => 'active',
                'start_date' => now()->toDateString(),
                'deadline' => now()->addMonths(3)->toDateString(),
                'description' => 'Pilot projekat za evidenciju FTTH mreze.',
            ]
        );

        $odf = Odf::updateOrCreate(
            ['project_id' => $project->id, 'name' => 'ODF Centar 01'],
            ['address' => 'Telekom cvor Centar', 'fiber_capacity' => 144, 'latitude' => 43.856258, 'longitude' => 18.413076]
        );

        $cabinetA = Cabinet::updateOrCreate(
            ['project_id' => $project->id, 'name' => 'ZO-001'],
            ['odf_id' => $odf->id, 'address' => 'Ulica Branilaca 12', 'splitter_count' => 3, 'ports_per_splitter' => 4, 'latitude' => 43.858050, 'longitude' => 18.416250]
        );

        $cabinetB = Cabinet::updateOrCreate(
            ['project_id' => $project->id, 'name' => 'ZO-002'],
            ['odf_id' => $odf->id, 'address' => 'Ulica Zmaja od Bosne 44', 'splitter_count' => 3, 'ports_per_splitter' => 4, 'latitude' => 43.855100, 'longitude' => 18.405700]
        );

        // Most houses are wired to a cabinet so the demo shows realistic ODO
        // utilisation; the last one stays unconnected to exercise the
        // "Nepovezane kuce" / Auto ODO planning path.
        foreach ([
            ['K-001', 'Branilaca 14', 43.858320, 18.416520, $cabinetA->id],
            ['K-002', 'Branilaca 16', 43.858180, 18.416780, $cabinetA->id],
            ['K-003', 'Branilaca 18', 43.857920, 18.416560, $cabinetA->id],
            ['K-004', 'Branilaca 20', 43.857720, 18.416180, $cabinetA->id],
            ['K-005', 'Branilaca 22', 43.857510, 18.415950, $cabinetA->id],
            ['K-006', 'Branilaca 24', 43.857240, 18.415650, $cabinetA->id],
            ['K-007', 'Zmaja od Bosne 46', 43.855300, 18.406100, $cabinetB->id],
            ['K-008', 'Zmaja od Bosne 48', 43.855020, 18.405420, $cabinetB->id],
            ['K-009', 'Zmaja od Bosne 50', 43.854810, 18.405920, null],
        ] as [$label, $address, $lat, $lng, $cabinetId]) {
            House::updateOrCreate(
                ['project_id' => $project->id, 'label' => $label],
                [
                    'cabinet_id' => $cabinetId,
                    'address' => $address,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'status' => 'planned',
                ]
            );
        }

        NetworkRoute::updateOrCreate(
            ['project_id' => $project->id, 'name' => 'ODF Centar 01 - ZO-001'],
            ['odf_id' => $odf->id, 'cabinet_id' => $cabinetA->id, 'route_type' => 'distribution', 'duct_length_m' => 420, 'fiber_length_m' => 460, 'microduct_count' => 2, 'status' => 'in_progress', 'path' => [[43.856258, 18.413076], [43.857120, 18.414400], [43.858050, 18.416250]]]
        );

        NetworkRoute::updateOrCreate(
            ['project_id' => $project->id, 'name' => 'ODF Centar 01 - ZO-002'],
            ['odf_id' => $odf->id, 'cabinet_id' => $cabinetB->id, 'route_type' => 'distribution', 'duct_length_m' => 610, 'fiber_length_m' => 650, 'microduct_count' => 2, 'status' => 'planned', 'path' => [[43.856258, 18.413076], [43.855850, 18.410500], [43.855100, 18.405700]]]
        );

        foreach ([
            ['Mikrocijev 12/8', 'm', 1200, 250, 1.25],
            ['Opticki kabl 24F', 'm', 1300, 300, 2.4],
            ['Splitter 1:4', 'kom', 6, 2, 18],
            ['Zeleni ormaric', 'kom', 2, 1, 190],
        ] as [$name, $unit, $planned, $used, $price]) {
            Material::firstOrCreate(
                ['project_id' => $project->id, 'name' => $name],
                ['unit' => $unit, 'planned_quantity' => $planned, 'used_quantity' => $used, 'unit_price' => $price]
            );
        }
    }
}
