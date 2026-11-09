<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReorderTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        return [
            'ids' => ['required', 'array', 'min:1', 'size:'.$project->tasks()->count()],
            'ids.*' => [
                'bail',
                'required',
                'integer',
                'distinct',
                Rule::exists(Task::class, 'id')
                    ->where(fn (Builder $query): Builder => $query->where('project_id', $project->getKey())),
            ],
        ];
    }
}
