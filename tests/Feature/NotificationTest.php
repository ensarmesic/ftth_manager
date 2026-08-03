<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_can_be_scoped_to_the_active_project(): void
    {
        $this->actingAs(User::factory()->create());

        $activeProject = Project::factory()->create();
        $otherProject = Project::factory()->create();

        House::factory()->create(['project_id' => $activeProject->id, 'cabinet_id' => null]);
        House::factory()->count(2)->create(['project_id' => $otherProject->id, 'cabinet_id' => null]);

        $this->getJson(route('api.notifications', ['project' => $activeProject->id]))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0', '1 kuca nema dodijeljeni ODO.');

        $this->getJson(route('api.notifications'))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0', '3 kuca nema dodijeljeni ODO.');
    }
}
