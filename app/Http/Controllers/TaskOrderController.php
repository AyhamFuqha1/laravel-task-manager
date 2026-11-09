<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReorderTasksRequest;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskOrderController extends Controller
{
    public function __invoke(ReorderTasksRequest $request, Project $project): JsonResponse
    {
        $taskIds = array_map('intval', $request->validated('ids'));

        DB::transaction(function () use ($project, $taskIds): void {
            $lockedProject = Project::query()
                ->lockForUpdate()
                ->findOrFail($project->getKey());

            $tasks = $lockedProject->tasks()
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $currentSorted = $tasks->keys()->map(fn ($id): int => (int) $id)->all();
            $requestedSorted = $taskIds;
            sort($currentSorted);
            sort($requestedSorted);

            if ($currentSorted !== $requestedSorted) {
                throw ValidationException::withMessages([
                    'ids' => 'The provided order must include each task of this project exactly once.',
                ]);
            }

            foreach ($taskIds as $index => $taskId) {
                $tasks->get($taskId)->update(['priority' => $index + 1]);
            }
        });

        return response()->json(['message' => 'Order saved.']);
    }
}
