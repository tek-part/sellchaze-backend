<?php

namespace App\Http\Resources;

use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Warehouse */
class WarehouseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'name_en' => $this->name_en,
            'name_ar' => $this->name_ar,
            'is_active' => (bool) $this->is_active,
            'is_default' => (bool) $this->is_default,
            'position' => (int) $this->position,
        ];
    }
}
