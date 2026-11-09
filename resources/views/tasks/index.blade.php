<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Task Manager</title>
    <style>
        :root { font: 16px system-ui, sans-serif; color: #172033; background: #f4f6fa; }
        * { box-sizing: border-box; }
        body { margin: 0; }
        main { max-width: 780px; margin: 48px auto; padding: 0 16px; }
        h1 { margin-bottom: 8px; font-size: 2rem; }
        h2 { font-size: 1.1rem; }
        .muted { color: #65718a; }
        .card { background: #fff; padding: 20px; border-radius: 13px; box-shadow: 0 2px 15px #1528490d; margin-top: 18px; }
        .row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .grow { flex: 1; min-width: 170px; }
        input, select, button { font: inherit; padding: 10px 12px; border-radius: 7px; }
        input, select { border: 1px solid #cad1dd; background: white; min-width: 0; }
        button { border: 0; cursor: pointer; background: #3449bb; color: white; }
        button:hover { filter: brightness(.94); }
        .secondary { background: #eff1f7; color: #26314e; }
        .danger { background: #fff0f0; color: #b22f3a; }
        .message { padding: 12px; background: #e8f7eb; border-radius: 7px; margin-top: 15px; }
        .errors { background: #fff0f0; padding: 12px; border-radius: 7px; margin-top: 15px; color: #902535; }
        ul.tasks { margin: 0; padding: 0; list-style: none; }
        li.task { display: flex; align-items: center; gap: 10px; padding: 13px 0; border-bottom: 1px solid #ecedf1; }
        li.task:last-child { border-bottom: 0; }
        li.dragging { opacity: .35; }
        .handle { cursor: grab; color: #8891a5; user-select: none; font-size: 1.4rem; }
        .task-name { flex: 1; min-width: 0; overflow-wrap: anywhere; }
        .priority { font-size: .85rem; background: #edf2ff; color: #3249af; border-radius: 999px; padding: 4px 9px; }
        details { position: relative; }
        summary { cursor: pointer; color: #3449bb; }
        details form { display: flex; gap: 5px; flex-wrap: wrap; padding-top: 8px; }
        .small { padding: 6px 9px; font-size: .85rem; }
        #order-status { min-height: 1.4em; }
        @media (max-width: 600px) { li.task { flex-wrap: wrap; } .task-name { flex-basis: 60%; } }
    </style>
</head>
<body>
<main>
    <h1>Task Manager</h1>
    <p class="muted">Laravel 11 reference app · Tasks are ordered by priority.</p>

    @if (session('success'))
        <div class="message" role="status">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="errors" role="alert">
            <strong>Please fix the following:</strong>
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <section class="card" aria-labelledby="projects-heading">
        <h2 id="projects-heading">Projects</h2>
        <form class="row" action="{{ route('tasks.index') }}" method="GET">
            <label for="project">View project:</label>
            <select name="project" id="project" class="grow" onchange="this.form.submit()">
                @foreach ($projects as $project)
                    <option value="{{ $project->id }}" @selected($selectedProject?->id === $project->id)>{{ $project->name }}</option>
                @endforeach
            </select>
            <noscript><button type="submit" class="secondary">View</button></noscript>
        </form>
        <form class="row" action="{{ route('projects.store') }}" method="POST" style="margin-top: 14px">
            @csrf
            <input class="grow" type="text" name="name" maxlength="100" placeholder="New project name" required>
            <button type="submit" class="secondary">+ Create Project</button>
        </form>
    </section>

    @if ($selectedProject)
        <section class="card" aria-labelledby="new-task-heading">
            <h2 id="new-task-heading">Add a task to {{ $selectedProject->name }}</h2>
            <form class="row" action="{{ route('tasks.store') }}" method="POST">
                @csrf
                <input type="hidden" name="project_id" value="{{ $selectedProject->id }}">
                <input class="grow" type="text" name="name" maxlength="255" placeholder="Enter task name" value="{{ old('name') }}" required>
                <button type="submit">+ Add Task</button>
            </form>
        </section>

        <section class="card" aria-labelledby="task-list-heading">
            <h2 id="task-list-heading">Tasks</h2>
            <p class="muted">Drag a task to change its priority. The order is saved automatically.</p>
            <p class="muted" id="order-status" role="status" aria-live="polite"></p>
            @if ($tasks->isEmpty())
                <p class="muted">No tasks yet. Add your first task above.</p>
            @endif
            <ul class="tasks" id="task-list">
                @foreach ($tasks as $task)
                    <li class="task" draggable="true" data-task-id="{{ $task->id }}">
                        <span class="handle" aria-hidden="true" title="Drag to reorder">⠿</span>
                        <span class="task-name">{{ $task->name }}</span>
                        <span class="priority" data-priority>Priority #{{ $task->priority }}</span>
                        <details>
                            <summary>Edit</summary>
                            <form action="{{ route('tasks.update', $task) }}" method="POST">
                                @csrf
                                @method('PATCH')
                                <input type="text" name="name" aria-label="Edit task name" value="{{ $task->name }}" maxlength="255" required>
                                <button class="small" type="submit">Save</button>
                            </form>
                        </details>
                        <form action="{{ route('tasks.destroy', $task) }}" method="POST"
                            onsubmit="return confirm('Delete this task?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="danger small">Delete</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</main>
@if ($selectedProject)
<script>
(() => {
    const list = document.getElementById('task-list');
    const status = document.getElementById('order-status');
    const url = @json(route('tasks.reorder', $selectedProject));
    let dragged = null;
    let previousOrder = [];

    const ids = () => Array.from(list.querySelectorAll('[data-task-id]'))
        .map(el => Number(el.dataset.taskId));

    list.addEventListener('dragstart', event => {
        const row = event.target.closest('[data-task-id]');
        if (!row) return;
        previousOrder = ids();
        dragged = row;
        row.classList.add('dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', row.dataset.taskId);
    });

    list.addEventListener('dragover', event => {
        if (!dragged) return;
        event.preventDefault();
        const over = event.target.closest('[data-task-id]');
        if (!over || over === dragged) return;
        const middle = over.getBoundingClientRect().top + over.offsetHeight / 2;
        list.insertBefore(dragged, event.clientY < middle ? over : over.nextSibling);
    });

    list.addEventListener('dragend', async () => {
        if (!dragged) return;
        dragged.classList.remove('dragging');
        dragged = null;

        const newOrder = ids();
        if (JSON.stringify(newOrder) === JSON.stringify(previousOrder)) return;
        status.textContent = 'Saving order...';

        try {
            const response = await fetch(url, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ids: newOrder}),
            });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            list.querySelectorAll('[data-priority]').forEach((badge, i) => {
                badge.textContent = `Priority #${i + 1}`;
            });
            status.textContent = 'Order saved.';
        } catch (error) {
            status.textContent = 'Could not save. Reloading the original order...';
            window.location.reload();
        }
    });
})();
</script>
@endif
</body>
</html>
