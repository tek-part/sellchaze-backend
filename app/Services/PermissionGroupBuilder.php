<?php

namespace App\Services;

use Spatie\Permission\Models\Permission;

class PermissionGroupBuilder
{
    /**
     * @return array<int, array{key: string, permissions: array<int, array{id: int, name: string}>}>
     */
    public static function buildGroups(): array
    {
        /** @var array<string, array<int, string>> $map */
        $map = config('sellchase_permission_groups', []);
        $byName = Permission::query()->orderBy('name')->get()->keyBy('name');
        $usedNames = collect($map)->flatten()->filter()->unique()->values();

        $groups = [];
        foreach ($map as $key => $names) {
            $items = [];
            foreach ($names as $name) {
                $p = $byName->get($name);
                if ($p) {
                    $items[] = ['id' => $p->id, 'name' => $p->name];
                }
            }
            // Keep all configured sections visible even if currently empty in DB.
            $groups[] = ['key' => $key, 'permissions' => $items];
        }

        $other = $byName->filter(fn ($p) => ! $usedNames->contains($p->name))
            ->values()
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])
            ->all();

        if ($other !== []) {
            $groups[] = ['key' => 'other', 'permissions' => $other];
        }

        return $groups;
    }

    /**
     * @return array<int, string>
     */
    public static function validPermissionNames(): array
    {
        return Permission::query()->orderBy('name')->pluck('name')->all();
    }
}
