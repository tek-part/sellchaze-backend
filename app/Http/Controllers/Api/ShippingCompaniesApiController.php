<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShippingCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ShippingCompaniesApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ShippingCompany::query()->orderBy('sort_order')->orderBy('name');

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->trim().'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('code', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            });
        }

        $perPage = min(max((int) $request->get('per_page', 30), 1), 100);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (ShippingCompany $c) => $this->toArray($c))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(ShippingCompany $shippingCompany): JsonResponse
    {
        return response()->json(['data' => $this->toArray($shippingCompany)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', 'unique:shipping_companies,code'],
            'phone' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:2048'],
            'tracking_url_template' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);

        $code = $validated['code'] ?? null;
        if ($code === null || $code === '') {
            $code = $this->uniqueCodeFromName($validated['name']);
        }

        $company = ShippingCompany::create([
            'name' => $validated['name'],
            'code' => $code,
            'phone' => $validated['phone'] ?? null,
            'website' => $validated['website'] ?? null,
            'tracking_url_template' => $validated['tracking_url_template'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json(['data' => $this->toArray($company)], 201);
    }

    public function update(Request $request, ShippingCompany $shippingCompany): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('shipping_companies', 'code')->ignore($shippingCompany->id)],
            'phone' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:2048'],
            'tracking_url_template' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);

        if (array_key_exists('code', $validated) && ($validated['code'] === null || $validated['code'] === '')) {
            $validated['code'] = $this->uniqueCodeFromName($validated['name'] ?? $shippingCompany->name, $shippingCompany->id);
        }

        $shippingCompany->fill($validated);
        $shippingCompany->save();

        return response()->json(['data' => $this->toArray($shippingCompany->fresh())]);
    }

    public function destroy(ShippingCompany $shippingCompany): JsonResponse
    {
        if ($shippingCompany->deliveries()->exists()) {
            return response()->json([
                'message' => __('Cannot delete a shipping company that has delivery records.'),
            ], 422);
        }

        $shippingCompany->delete();

        return response()->json(null, 204);
    }

    private function toArray(ShippingCompany $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'code' => $c->code,
            'phone' => $c->phone,
            'website' => $c->website,
            'tracking_url_template' => $c->tracking_url_template,
            'is_active' => $c->is_active,
            'sort_order' => $c->sort_order,
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }

    private function uniqueCodeFromName(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'company';
        }
        $base = Str::limit($base, 50, '');
        $code = $base;
        $n = 2;
        while (ShippingCompany::query()
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('code', $code)
            ->exists()) {
            $suffix = '-'.$n;
            $code = Str::limit($base, 50 - strlen($suffix), '').$suffix;
            $n++;
        }

        return $code;
    }
}
