<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetworkRegistryTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_has_one_registry_entry_and_each_page_shows_all_tabs(): void
    {
        $project = Project::create(['name' => 'Evidencija', 'code' => 'REG-1', 'location' => 'Banovići', 'status' => 'planning']);

        foreach (['odfs.index', 'cabinets.index', 'houses.index', 'routes.index', 'branches.index'] as $route) {
            $page = $this->get(route($route, ['project' => $project->id]))->assertOk();
            $page->assertSee('class="registry-tabs"', false)
                ->assertSee('Mrežna evidencija');
            foreach (['ODF-ovi', 'ODO ormarići', 'Kuće', 'Trase', 'Krakovi'] as $tab) {
                $page->assertSee($tab);
            }
            // Tabs keep the chosen project.
            $page->assertSee('href="'.route('houses.index', ['project' => $project->id]).'"', false);
        }

        // The sidebar shows one entry instead of the five pages.
        $this->get(route('dashboard'))->assertOk()
            ->assertDontSee('class="registry-tabs"', false)
            ->assertSee('Mrežna evidencija');
    }
}
