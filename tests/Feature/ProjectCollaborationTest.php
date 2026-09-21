<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectCollaborationTest extends TestCase
{
    use RefreshDatabase;

    public function test_designer_can_create_and_complete_assigned_work_item(): void
    {
        $designer = User::factory()->designer()->create();
        $assignee = User::factory()->field()->create();
        $project = Project::factory()->create();

        $this->actingAs($designer)->post(route('projects.work-items.store', $project), [
            'title' => 'Provjeri ODO na terenu', 'description' => 'Fotografisati spojeve', 'assigned_to' => $assignee->id,
            'status' => 'open', 'priority' => 'high', 'due_date' => now()->addDay()->toDateString(),
        ])->assertRedirect()->assertSessionHas('success');

        $task = $project->workItems()->firstOrFail();
        $this->patch(route('projects.work-items.update', [$project, $task]), [
            'title' => $task->title, 'description' => $task->description, 'assigned_to' => $assignee->id,
            'status' => 'done', 'priority' => 'high', 'due_date' => $task->due_date->toDateString(),
        ])->assertRedirect();
        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_comment_attachment_is_private_and_downloadable_through_authorized_route(): void
    {
        Storage::fake('local');
        $designer = User::factory()->designer()->create();
        $project = Project::factory()->create();

        $this->actingAs($designer)->post(route('projects.comments.store', $project), [
            'subject_type' => 'project', 'body' => 'Fotografija izvedenog stanja',
            'attachment' => UploadedFile::fake()->createWithContent('teren.txt', 'terenska biljeska'),
        ])->assertRedirect()->assertSessionHas('success');

        $comment = $project->comments()->firstOrFail();
        Storage::disk('local')->assertExists($comment->attachment_path);
        $this->get(route('projects.comments.download', [$project, $comment]))->assertOk()->assertDownload('teren.txt');
    }

    public function test_viewer_cannot_create_tasks_or_comments(): void
    {
        $project = Project::factory()->create();
        $this->actingAs(User::factory()->viewer()->create());
        $this->post(route('projects.work-items.store', $project), [])->assertForbidden();
        $this->post(route('projects.comments.store', $project), [])->assertForbidden();
    }
}
