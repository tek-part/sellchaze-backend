<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TasksApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = Task::query()->with(['assignee:id,name,email', 'assigner:id,name,email']);

        if ($user->hasRole('Admin')) {
            // all
        } elseif ($user->isEmployee()) {
            $q->where('assigned_to_user_id', $user->id);
        } else {
            // Owner: sees tasks they created, or tasks assigned to their employees.
            $employeeIds = User::query()->where('parent_user_id', $user->id)->pluck('id')->all();
            $q->where(function ($w) use ($user, $employeeIds) {
                $w->where('assigned_by_user_id', $user->id)
                  ->orWhereIn('assigned_to_user_id', $employeeIds ?: [0]);
            });
        }

        $rows = $q->orderByDesc('id')->limit(500)->get();

        return response()->json([
            'data' => $rows->map(fn (Task $t) => $this->serialize($t))->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'assigned_to_user_id' => ['required', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(Task::STATUSES)],
            'due_date' => ['nullable', 'date'],
        ]);

        $assignee = User::query()->findOrFail($validated['assigned_to_user_id']);
        if (! $user->hasRole('Admin')) {
            if ((int) $assignee->parent_user_id !== (int) $user->id) {
                abort(403, 'You can only assign tasks to your own employees.');
            }
        }

        $task = Task::create([
            'assigned_to_user_id' => $assignee->id,
            'assigned_by_user_id' => $user->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'pending',
            'due_date' => $validated['due_date'] ?? null,
        ]);

        return response()->json(['data' => $this->serialize($task->fresh(['assignee', 'assigner']))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $task = Task::query()->findOrFail($id);
        $this->authorizeTask($user, $task);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:191'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', Rule::in(Task::STATUSES)],
            'due_date' => ['sometimes', 'nullable', 'date'],
        ]);

        // Employee can only change status on own task; owner/admin can change everything.
        if ($user->isEmployee() && (int) $task->assigned_to_user_id === (int) $user->id) {
            $task->status = $validated['status'] ?? $task->status;
        } else {
            $task->fill($validated);
        }
        $task->save();

        return response()->json(['data' => $this->serialize($task->fresh(['assignee', 'assigner']))]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $task = Task::query()->findOrFail($id);
        if (! $user->hasRole('Admin') && (int) $task->assigned_by_user_id !== (int) $user->id) {
            abort(403, 'Only the creator can delete this task.');
        }
        $task->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function authorizeTask(User $user, Task $task): void
    {
        if ($user->hasRole('Admin')) return;
        if ((int) $task->assigned_by_user_id === (int) $user->id) return;
        if ((int) $task->assigned_to_user_id === (int) $user->id) return;
        abort(403);
    }

    private function serialize(Task $t): array
    {
        return [
            'id' => $t->id,
            'title' => $t->title,
            'description' => $t->description,
            'status' => $t->status,
            'due_date' => $t->due_date?->toDateString(),
            'assigned_to' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name, 'email' => $t->assignee->email] : null,
            'assigned_by' => $t->assigner ? ['id' => $t->assigner->id, 'name' => $t->assigner->name, 'email' => $t->assigner->email] : null,
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }
}
