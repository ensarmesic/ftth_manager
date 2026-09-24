<?php

namespace App\Services;

use App\Models\GisSegment;
use App\Models\LargePlannerInputSnapshot;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LargePlannerInputSnapshotService
{
    public function create(Project $project, User $user, ?string $label = null): LargePlannerInputSnapshot
    {
        return DB::transaction(function () use ($project, $user, $label): LargePlannerInputSnapshot {
            $payload = $this->payload($project);
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $revision = ((int) $project->largePlannerInputSnapshots()->lockForUpdate()->max('revision')) + 1;

            return $project->largePlannerInputSnapshots()->create([
                'user_id' => $user->id,
                'revision' => $revision,
                'label' => $label ?: "Ulazna revizija {$revision}",
                'checksum' => hash('sha256', $encoded),
                'payload' => $payload,
                'house_count' => count($payload['houses']),
                'corridor_count' => count($payload['corridors']),
                'constraint_count' => count($payload['constraints']),
                'zone_count' => count($payload['zones']),
            ]);
        });
    }

    private function payload(Project $project): array
    {
        $settings = $project->largePlannerSetting()->first();

        return [
            'schema_version' => 1,
            'project' => ['id' => $project->id, 'planning_mode' => $project->planning_mode],
            'settings' => $settings?->only([
                'odo_capacity', 'max_drop_length_m', 'fiber_reserve_percent', 'optimization_goal', 'propose_odfs', 'odf_capacity',
            ]),
            'corridors' => GisSegment::query()
                ->where('project_id', $project->id)
                ->where('is_allowed', true)
                ->whereNotNull('planning_corridor_type')
                ->orderBy('id')
                ->get()
                ->map(fn (GisSegment $segment) => [
                    'id' => $segment->id,
                    'name' => $segment->name,
                    'type' => $segment->planning_corridor_type,
                    'path' => $segment->path,
                ])->all(),
            'constraints' => $project->largePlannerConstraints()->orderBy('id')->get()
                ->map(fn ($constraint) => [
                    'id' => $constraint->id,
                    'type' => $constraint->type,
                    'name' => $constraint->name,
                    'geometry' => $constraint->geometry,
                ])->all(),
            'zones' => $project->largePlannerZones()->orderBy('id')->get()
                ->map(fn ($zone) => [
                    'id' => $zone->id,
                    'name' => $zone->name,
                    'status' => $zone->status,
                    'geometry' => $zone->geometry,
                ])->all(),
            'houses' => $project->houses()->orderBy('id')->get()
                ->map(fn ($house) => [
                    'id' => $house->id,
                    'label' => $house->label,
                    'address' => $house->address,
                    'zone_id' => $house->large_planner_zone_id,
                    'latitude' => $house->latitude === null ? null : (float) $house->latitude,
                    'longitude' => $house->longitude === null ? null : (float) $house->longitude,
                ])->all(),
        ];
    }
}
