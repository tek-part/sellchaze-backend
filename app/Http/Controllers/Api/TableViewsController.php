<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TableView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saved views for dashboard index tables. Views are private to the user; the scope string is an
 * opaque client key (it never grants access to data — the list endpoints authorise every read).
 */
class TableViewsController extends Controller
{
    public const MAX_PER_SCOPE = 20;

    private const SCOPE = ['required', 'string', 'max:120', 'regex:/^[a-z0-9:_\-]+$/'];

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', 'regex:/^[^\x00-\x1f\x7f]+$/u'],
            'state' => ['required', 'array:search,sort,filters,columns'],
            'state.search' => ['nullable', 'string', 'max:200'],
            'state.sort' => ['nullable', 'array:by,direction'],
            'state.sort.by' => ['required_with:state.sort', 'string', 'max:60', 'regex:/^[a-z0-9_.]+$/'],
            'state.sort.direction' => ['required_with:state.sort', 'in:asc,desc'],
            'state.filters' => ['present', 'array', 'max:30'],
            'state.columns' => ['nullable', 'array', 'max:40'],
            'state.columns.*' => ['string', 'max:60', 'regex:/^[a-z0-9_.]+$/'],
        ];
    }

    private static function present(TableView $view): array
    {
        return ['id' => $view->id, 'name' => $view->name, 'state' => $view->state, 'position' => $view->position];
    }

    public function index(Request $request): JsonResponse
    {
        $scope = $request->validate(['scope' => self::SCOPE])['scope'];
        $views = TableView::query()->where('user_id', $request->user()->id)->where('scope', $scope)->orderBy('position')->orderBy('id')->get();

        return response()->json(['data' => $views->map(fn (TableView $view) => self::present($view))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['scope' => self::SCOPE] + $this->rules());
        $this->assertFilterValues($data['state']['filters']);
        $view = DB::transaction(function () use ($request, $data) {
            $existing = TableView::query()->where('user_id', $request->user()->id)->where('scope', $data['scope'])->lockForUpdate()->get(['id', 'position', 'name']);
            if ($existing->count() >= self::MAX_PER_SCOPE) {
                throw ValidationException::withMessages(['name' => 'You can save up to '.self::MAX_PER_SCOPE.' views for this list.']);
            }
            if ($existing->contains(fn ($view) => mb_strtolower($view->name) === mb_strtolower(trim($data['name'])))) {
                throw ValidationException::withMessages(['name' => 'A view with this name already exists.']);
            }

            return TableView::query()->create(['user_id' => $request->user()->id, 'scope' => $data['scope'], 'name' => trim($data['name']),
                'state' => $data['state'], 'position' => ($existing->max('position') ?? -1) + 1]);
        });

        return response()->json(['data' => self::present($view)], 201);
    }

    private function owned(Request $request, int $id): TableView
    {
        return TableView::query()->where('user_id', $request->user()->id)->whereKey($id)->firstOrFail();
    }

    public function update(Request $request, int $view): JsonResponse
    {
        $current = $this->owned($request, $view);
        $rules = $this->rules();
        $data = $request->validate(['name' => ['sometimes', ...array_slice($rules['name'], 1)]]
            + ($request->has('state') ? array_filter($rules, fn ($key) => str_starts_with($key, 'state'), ARRAY_FILTER_USE_KEY) : []));
        if (isset($data['state'])) {
            $this->assertFilterValues($data['state']['filters'] ?? []);
        }
        if (isset($data['name']) && TableView::query()->where('user_id', $current->user_id)->where('scope', $current->scope)->whereKeyNot($current->id)
            ->get(['name'])->contains(fn ($view) => mb_strtolower($view->name) === mb_strtolower(trim($data['name'])))) {
            throw ValidationException::withMessages(['name' => 'A view with this name already exists.']);
        }
        $current->update(array_filter(['name' => isset($data['name']) ? trim($data['name']) : null, 'state' => $data['state'] ?? null], fn ($value) => $value !== null));

        return response()->json(['data' => self::present($current->fresh())]);
    }

    public function destroy(Request $request, int $view): JsonResponse
    {
        $this->owned($request, $view)->delete();

        return response()->json(null, 204);
    }

    /** Filter values are strings, lists of strings or {min,max} ranges — nothing deeper, nothing large. */
    private function assertFilterValues(array $filters): void
    {
        foreach ($filters as $key => $value) {
            $ok = is_string($key) && preg_match('/^[a-z0-9_.]{1,60}$/', $key) && match (true) {
                is_string($value) => mb_strlen($value) <= 200,
                is_array($value) && array_is_list($value) => count($value) <= 50 && collect($value)->every(fn ($item) => is_string($item) && mb_strlen($item) <= 100),
                is_array($value) => array_diff(array_keys($value), ['min', 'max']) === [] && collect($value)->every(fn ($item) => $item === null || (is_string($item) && mb_strlen($item) <= 40)),
                default => false,
            };
            if (! $ok) {
                throw ValidationException::withMessages(['state.filters' => 'Unsupported filter value.']);
            }
        }
    }
}
