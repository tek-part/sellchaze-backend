<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationConnection;
use App\Services\Outbox\OutboxRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganizationConnectionController extends Controller
{
    public function index(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('view', $organization);

        $status = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'accepted', 'rejected'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ])['status'] ?? null;

        $connections = OrganizationConnection::query()
            ->where(fn ($query) => $query
                ->where('organization_a_id', $organization->id)
                ->orWhere('organization_b_id', $organization->id))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->with(['organizationA:id,name,slug,logo_url,is_verified', 'organizationB:id,name,slug,logo_url,is_verified'])
            ->latest('id')
            ->paginate((int) ($request->query('per_page', 20)));

        $connections->getCollection()->transform(fn (OrganizationConnection $connection) => $this->present($connection, $organization->id));

        return response()->json($connections);
    }

    public function store(Request $request, Organization $organization, OutboxRecorder $outbox): JsonResponse
    {
        $this->authorize('manageConnections', $organization);
        $data = $request->validate([
            'target_organization_id' => ['required', 'integer', 'exists:organizations,id', Rule::notIn([$organization->id])],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);
        $targetId = (int) $data['target_organization_id'];
        [$a, $b] = $organization->id < $targetId
            ? [$organization->id, $targetId]
            : [$targetId, $organization->id];

        $connection = DB::transaction(function () use ($request, $organization, $targetId, $a, $b, $data, $outbox): OrganizationConnection {
            $existing = OrganizationConnection::query()
                ->where('organization_a_id', $a)
                ->where('organization_b_id', $b)
                ->lockForUpdate()
                ->first();

            if ($existing?->status === 'accepted') {
                throw ValidationException::withMessages(['target_organization_id' => 'These organizations are already connected.']);
            }
            if ($existing?->status === 'pending') {
                throw ValidationException::withMessages(['target_organization_id' => 'A connection request is already pending.']);
            }

            $connection = OrganizationConnection::query()->updateOrCreate(
                ['organization_a_id' => $a, 'organization_b_id' => $b],
                [
                    'initiator_organization_id' => $organization->id,
                    'requested_by_user_id' => $request->user()->id,
                    'responded_by_user_id' => null,
                    'status' => 'pending',
                    'message' => $data['message'] ?? null,
                    'responded_at' => null,
                    'accepted_at' => null,
                ],
            );
            $outbox->record('ConnectionRequested', 'organization_connection', (string) $connection->id, [
                'connection_id' => $connection->id,
                'initiator_organization_id' => $organization->id,
                'target_organization_id' => $targetId,
            ]);

            return $connection;
        });

        return response()->json(['data' => $this->present($connection->load(['organizationA', 'organizationB']), $organization->id)], 201);
    }

    public function accept(
        Request $request,
        Organization $organization,
        OrganizationConnection $connection,
        OutboxRecorder $outbox,
    ): JsonResponse {
        return $this->respond($request, $organization, $connection, 'accepted', $outbox);
    }

    public function reject(
        Request $request,
        Organization $organization,
        OrganizationConnection $connection,
        OutboxRecorder $outbox,
    ): JsonResponse {
        return $this->respond($request, $organization, $connection, 'rejected', $outbox);
    }

    private function respond(
        Request $request,
        Organization $organization,
        OrganizationConnection $connection,
        string $status,
        OutboxRecorder $outbox,
    ): JsonResponse {
        $this->authorize('manageConnections', $organization);
        abort_unless($connection->includes($organization->id), 404);
        abort_unless($connection->initiator_organization_id !== $organization->id, 403, 'The initiating organization cannot answer its own request.');

        $connection = DB::transaction(function () use ($request, $connection, $status, $outbox): OrganizationConnection {
            $locked = OrganizationConnection::query()->lockForUpdate()->findOrFail($connection->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['connection' => 'Only a pending connection can be answered.']);
            }

            $locked->update([
                'status' => $status,
                'responded_by_user_id' => $request->user()->id,
                'responded_at' => now(),
                'accepted_at' => $status === 'accepted' ? now() : null,
            ]);
            $outbox->record(
                $status === 'accepted' ? 'ConnectionAccepted' : 'ConnectionRejected',
                'organization_connection',
                (string) $locked->id,
                [
                    'connection_id' => $locked->id,
                    'organization_a_id' => $locked->organization_a_id,
                    'organization_b_id' => $locked->organization_b_id,
                ],
            );

            return $locked;
        });

        return response()->json(['data' => $this->present($connection->load(['organizationA', 'organizationB']), $organization->id)]);
    }

    private function present(OrganizationConnection $connection, int $organizationId): array
    {
        $other = $connection->organization_a_id === $organizationId
            ? $connection->organizationB
            : $connection->organizationA;

        return [
            'id' => $connection->id,
            'status' => $connection->status,
            'direction' => $connection->initiator_organization_id === $organizationId ? 'outgoing' : 'incoming',
            'message' => $connection->message,
            'organization' => $other?->only(['id', 'name', 'slug', 'logo_url', 'is_verified']),
            'accepted_at' => $connection->accepted_at?->toIso8601String(),
            'created_at' => $connection->created_at?->toIso8601String(),
        ];
    }
}
