<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_tasks_assigns_incremental_priority(): void
    {
        $project = Project::create(['name' => 'Testing']);

        $this->post(route('tasks.store'), ['project_id' => $project->id, 'name' => 'First'])
            ->assertRedirect();
        $this->post(route('tasks.store'), ['project_id' => $project->id, 'name' => 'Second'])
            ->assertRedirect();

        $this->assertDatabaseHas('tasks', ['name' => 'First', 'priority' => 1]);
        $this->assertDatabaseHas('tasks', ['name' => 'Second', 'priority' => 2]);
    }

    public function test_task_name_is_required(): void
    {
        $project = Project::create(['name' => 'Testing']);

        $this->post(route('tasks.store'), ['project_id' => $project->id, 'name' => ''])
            ->assertSessionHasErrors('name');
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_tasks_can_be_renamed(): void
    {
        $project = Project::create(['name' => 'Testing']);
        $task = Task::create(['project_id' => $project->id, 'name' => 'Old', 'priority' => 1]);

        $this->patch(route('tasks.update', $task), ['name' => 'New'])->assertRedirect();
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'name' => 'New']);
    }

    public function test_deleting_a_task_closes_priority_gaps(): void
    {
        $project = Project::create(['name' => 'Testing']);
        $one = Task::create(['project_id' => $project->id, 'name' => 'A', 'priority' => 1]);
        $two = Task::create(['project_id' => $project->id, 'name' => 'B', 'priority' => 2]);
        $three = Task::create(['project_id' => $project->id, 'name' => 'C', 'priority' => 3]);

        $this->delete(route('tasks.destroy', $two))->assertRedirect();
        $this->assertDatabaseMissing('tasks', ['id' => $two->id]);
        $this->assertSame(1, $one->fresh()->priority);
        $this->assertSame(2, $three->fresh()->priority);
    }

    public function test_reordering_persists_priority_in_database(): void
    {
        $project = Project::create(['name' => 'Testing']);
        $a = Task::create(['project_id' => $project->id, 'name' => 'A', 'priority' => 1]);
        $b = Task::create(['project_id' => $project->id, 'name' => 'B', 'priority' => 2]);
        $c = Task::create(['project_id' => $project->id, 'name' => 'C', 'priority' => 3]);

        $this->putJson(route('tasks.reorder', $project), ['ids' => [$c->id, $a->id, $b->id]])
            ->assertOk()
            ->assertExactJson(['message' => 'Order saved.']);

        $this->assertSame(1, $c->fresh()->priority);
        $this->assertSame(2, $a->fresh()->priority);
        $this->assertSame(3, $b->fresh()->priority);
    }

    public function test_reorder_rejects_duplicate_ids(): void
    {
        [$project, $a, $b] = $this->projectWithTwoTasks();

        $this->putJson(route('tasks.reorder', $project), ['ids' => [$a->id, $a->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids.1');

        $this->assertTaskPrioritiesAreUnchanged($a, $b);
    }

    public function test_reorder_rejects_unknown_ids(): void
    {
        [$project, $a, $b] = $this->projectWithTwoTasks();

        $this->putJson(route('tasks.reorder', $project), ['ids' => [$a->id, 999999]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids.1');

        $this->assertTaskPrioritiesAreUnchanged($a, $b);
    }

    public function test_reorder_rejects_missing_ids(): void
    {
        [$project, $a, $b] = $this->projectWithTwoTasks();

        $this->putJson(route('tasks.reorder', $project), ['ids' => [$a->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids');

        $this->assertTaskPrioritiesAreUnchanged($a, $b);
    }

    public function test_reorder_rejects_an_empty_order(): void
    {
        [$project, $a, $b] = $this->projectWithTwoTasks();

        $this->putJson(route('tasks.reorder', $project), ['ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids');

        $this->assertTaskPrioritiesAreUnchanged($a, $b);
    }

    public function test_reorder_rejects_cross_project_ids(): void
    {
        [$project, $a, $b] = $this->projectWithTwoTasks();
        $otherProject = Project::create(['name' => 'Other']);
        $otherTask = Task::create([
            'project_id' => $otherProject->id,
            'name' => 'Other task',
            'priority' => 1,
        ]);

        $this->putJson(route('tasks.reorder', $project), ['ids' => [$a->id, $otherTask->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids.1');

        $this->assertTaskPrioritiesAreUnchanged($a, $b);
        $this->assertSame(1, $otherTask->fresh()->priority);
    }

    public function test_project_names_are_required_and_unique(): void
    {
        Project::create(['name' => 'Existing']);

        $this->post(route('projects.store'), ['name' => ''])
            ->assertSessionHasErrors('name');
        $this->post(route('projects.store'), ['name' => 'Existing'])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('projects', 1);
    }

    public function test_projects_can_be_created(): void
    {
        $response = $this->post(route('projects.store'), ['name' => 'New project']);

        $project = Project::query()->sole();

        $response
            ->assertRedirect(route('tasks.index', ['project' => $project->id]))
            ->assertSessionHas('success', 'Project created.');
        $this->assertSame('New project', $project->name);
    }

    public function test_project_filter_only_shows_its_tasks(): void
    {
        $one = Project::create(['name' => 'One']);
        $two = Project::create(['name' => 'Two']);
        Task::create(['project_id' => $one->id, 'name' => 'Alpha private task', 'priority' => 1]);
        Task::create(['project_id' => $two->id, 'name' => 'Beta private task', 'priority' => 1]);

        $this->get(route('tasks.index', ['project' => $one->id]))
            ->assertOk()
            ->assertSee('Alpha private task')
            ->assertDontSee('Beta private task');
    }

    /**
     * @return array{Project, Task, Task}
     */
    private function projectWithTwoTasks(): array
    {
        $project = Project::create(['name' => 'Testing']);
        $a = Task::create(['project_id' => $project->id, 'name' => 'A', 'priority' => 1]);
        $b = Task::create(['project_id' => $project->id, 'name' => 'B', 'priority' => 2]);

        return [$project, $a, $b];
    }

    private function assertTaskPrioritiesAreUnchanged(Task $first, Task $second): void
    {
        $this->assertSame(1, $first->fresh()->priority);
        $this->assertSame(2, $second->fresh()->priority);
    }
}
