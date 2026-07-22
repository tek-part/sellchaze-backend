<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeValues;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AttributesApiController extends Controller
{
    private const SUPPORTED_TYPES = ['text', 'number', 'select', 'multiselect', 'color', 'boolean'];

    public function index(Request $request): JsonResponse
    {
        $query = Attribute::query()->with('attribute_values');
        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->string('search')->trim().'%');
        }
        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }
        $perPage = min(max((int) $request->get('per_page', 50), 1), 200);

        return response()->json($query->orderBy('name')->paginate($perPage));
    }

    public function show(int $id): JsonResponse
    {
        $attr = Attribute::with('attribute_values')->findOrFail($id);

        return response()->json(['data' => $this->serialize($attr)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'type' => ['required', 'string', Rule::in(self::SUPPORTED_TYPES)],
            'values' => ['sometimes', 'array'],
            'values.*' => ['string', 'max:191'],
        ]);

        $attr = DB::transaction(function () use ($validated) {
            $attr = Attribute::create([
                'name' => $validated['name'],
                'type' => $validated['type'],
            ]);
            $this->syncValues($attr, $validated['values'] ?? []);

            return $attr;
        });

        return response()->json(['data' => $this->serialize($attr->load('attribute_values'))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $attr = Attribute::findOrFail($id);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'type' => ['sometimes', 'string', Rule::in(self::SUPPORTED_TYPES)],
            'values' => ['sometimes', 'array'],
            'values.*' => ['string', 'max:191'],
        ]);

        DB::transaction(function () use ($attr, $validated) {
            if (array_key_exists('name', $validated)) {
                $attr->name = $validated['name'];
            }
            if (array_key_exists('type', $validated)) {
                $attr->type = $validated['type'];
            }
            $attr->save();
            if (array_key_exists('values', $validated)) {
                $this->syncValues($attr, $validated['values']);
            }
        });

        return response()->json(['data' => $this->serialize($attr->fresh('attribute_values'))]);
    }

    public function destroy(int $id): JsonResponse
    {
        $attr = Attribute::findOrFail($id);
        DB::transaction(function () use ($attr) {
            AttributeValues::where('attribute_id', $attr->id)->delete();
            $attr->delete();
        });

        return response()->json(['message' => 'deleted']);
    }

    private function syncValues(Attribute $attr, array $values): void
    {
        // Replace whole set: simpler and matches Feature 4 intent (supplier curates the list).
        AttributeValues::where('attribute_id', $attr->id)->delete();
        $clean = array_values(array_filter(array_map('trim', $values), fn ($v) => $v !== ''));
        foreach ($clean as $v) {
            AttributeValues::create(['attribute_id' => $attr->id, 'value' => $v]);
        }
    }

    private function serialize(Attribute $attr): array
    {
        return [
            'id' => $attr->id,
            'name' => $attr->name,
            'type' => $attr->type,
            'values' => $attr->attribute_values->map(fn ($v) => [
                'id' => $v->id,
                'value' => $v->value,
            ])->values()->all(),
        ];
    }
}
