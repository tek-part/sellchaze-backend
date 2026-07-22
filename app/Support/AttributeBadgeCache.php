<?php

namespace App\Support;

use App\Models\Attribute;
use App\Models\AttributeValues;

/**
 * Request-scoped memoization for the Attribute / AttributeValues lookups performed
 * when serializing order attribute badges (OrderApiResource).
 *
 * A single order-detail collection re-resolves the same catalog attribute and value
 * IDs across every row; without memoization that is an N+1. Bound as `scoped`
 * (one instance per request, reset between requests — Octane-safe), so repeated
 * lookups of the same ID hit the map instead of the database.
 *
 * Each accessor returns EXACTLY what Model::find() returns — the model or null,
 * including caching the null (not-found) result so behaviour is byte-identical to
 * the original per-call ->find().
 */
class AttributeBadgeCache
{
    /** @var array<int|string, Attribute|null> */
    private array $attributes = [];

    /** @var array<int|string, AttributeValues|null> */
    private array $values = [];

    public function attribute(int|string $id): ?Attribute
    {
        if (! array_key_exists($id, $this->attributes)) {
            $this->attributes[$id] = Attribute::query()->find($id);
        }

        return $this->attributes[$id];
    }

    public function value(int|string $id): ?AttributeValues
    {
        if (! array_key_exists($id, $this->values)) {
            $this->values[$id] = AttributeValues::query()->find($id);
        }

        return $this->values[$id];
    }
}
