<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationFollow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationFollowController extends Controller
{
    public function store(Request $request, Organization $organization): JsonResponse
    {
        $follow = OrganizationFollow::query()->firstOrCreate([
            'organization_id' => $organization->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['following' => true, 'changed' => $follow->wasRecentlyCreated]);
    }

    public function destroy(Request $request, Organization $organization): JsonResponse
    {
        OrganizationFollow::query()->where('organization_id', $organization->id)
            ->where('user_id', $request->user()->id)->delete();

        return response()->json(['following' => false]);
    }
}
