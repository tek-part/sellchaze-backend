<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationMembershipResource;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Outbox\OutboxRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganizationMembershipController extends Controller
{
    public function index(Request $request, Organization $organization)
    {
        $this->authorize('view', $organization);

        return OrganizationMembershipResource::collection(
            $organization->memberships()->with('user')->orderBy('id')->paginate(50)
        );
    }

    public function store(
        Request $request,
        Organization $organization,
        OutboxRecorder $outbox,
        OrganizationEntitlementService $entitlements,
    ) {
        $this->authorize('manageMembers', $organization);
        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'role' => ['required', Rule::in(['admin', 'manager', 'member'])],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:100'],
            'store_ids' => ['nullable', 'array'],
            'store_ids.*' => ['integer'],
        ]);
        $user = User::query()->where('email', $data['email'])->firstOrFail();
        if (! $organization->memberships()->where('user_id', $user->id)->where('status', 'active')->exists()) {
            $entitlements->ensureQuota(
                $organization,
                'seats',
                $organization->memberships()->where('status', 'active')->count(),
            );
        }
        $storeIds = array_values(array_unique($data['store_ids'] ?? []));
        if ($storeIds && $organization->stores()->whereIn('id', $storeIds)->count() !== count($storeIds)) {
            throw ValidationException::withMessages(['store_ids' => 'Every store must belong to the organization.']);
        }

        $membership = DB::transaction(function () use ($organization, $user, $data, $storeIds, $request, $outbox) {
            $membership = $organization->memberships()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'role' => $data['role'],
                    'status' => 'active',
                    'permissions' => $data['permissions'] ?? null,
                    'store_ids' => $storeIds ?: null,
                    'invited_by_user_id' => $request->user()->id,
                    'joined_at' => now(),
                ]
            );
            $outbox->record('OrganizationMemberAdded', 'organization', $organization->id, [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => $membership->role,
            ]);

            return $membership;
        });

        return (new OrganizationMembershipResource($membership->load('user')))
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, Organization $organization, OrganizationMembership $membership): OrganizationMembershipResource
    {
        $this->authorize('manageMembers', $organization);
        abort_unless((int) $membership->organization_id === (int) $organization->id, 404);
        abort_if($membership->role === 'owner', 422, 'Transfer ownership before changing the owner membership.');
        $data = $request->validate([
            'role' => ['sometimes', Rule::in(['admin', 'manager', 'member'])],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:100'],
            'store_ids' => ['nullable', 'array'],
            'store_ids.*' => ['integer'],
        ]);
        if (array_key_exists('store_ids', $data)) {
            $ids = array_values(array_unique($data['store_ids'] ?? []));
            if ($ids && $organization->stores()->whereIn('id', $ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['store_ids' => 'Every store must belong to the organization.']);
            }
            $data['store_ids'] = $ids ?: null;
        }
        $membership->update($data);

        return new OrganizationMembershipResource($membership->load('user'));
    }

    public function destroy(Request $request, Organization $organization, OrganizationMembership $membership)
    {
        $this->authorize('manageMembers', $organization);
        abort_unless((int) $membership->organization_id === (int) $organization->id, 404);
        abort_if($membership->role === 'owner', 422, 'The owner membership cannot be removed.');
        $membership->delete();

        return response()->noContent();
    }
}
