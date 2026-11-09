<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $projects = Project::query()->orderBy('name')->get();
        $selectedProject = $projects->firstWhere('id', $request->integer('project'))
            ?? $projects->first();

        $tasks = $selectedProject
            ? $selectedProject->tasks()->orderBy('priority')->orderBy('id')->get()
            : collect();

        return view('tasks.index', compact('projects', 'selectedProject', 'tasks'));
    }

    public function store(StoreTaskRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // Serialize inserts within a single project so priorities stay sequential.
        DB::transaction(function () use ($data): void {
            $project = Project::query()
                ->lockForUpdate()
                ->findOrFail($data['project_id']);

            $nextPriority = ((int) $project->tasks()->max('priority')) + 1;

            $project->tasks()->create([
                'name' => $data['name'],
                'priority' => $nextPriority,
            ]);
        });

        return redirect()->route('tasks.index', ['project' => $data['project_id']])
            ->with('success', 'Task created.');
    }

    public function update(UpdateTaskRequest $request, Task $task): RedirectResponse
    {
        $task->update($request->validated());

        return redirect()->route('tasks.index', ['project' => $task->project_id])
            ->with('success', 'Task updated.');
    }

    public function destroy(Task $task): RedirectResponse
    {
        $projectId = $task->project_id;

        DB::transaction(function () use ($task, $projectId): void {
            $project = Project::query()
                ->lockForUpdate()
                ->findOrFail($projectId);

            $task->delete();

            // Remove priority gaps after a deletion.
            $remainingTasks = $project->tasks()
                ->orderBy('priority')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($remainingTasks as $index => $remainingTask) {
                $remainingTask->update(['priority' => $index + 1]);
            }
        });

        return redirect()->route('tasks.index', ['project' => $projectId])
            ->with('success', 'Task deleted.');
    }
}
