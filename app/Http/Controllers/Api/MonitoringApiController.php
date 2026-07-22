<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuthSession;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class MonitoringApiController extends Controller
{
    public function live(Request $request): JsonResponse
    {
        $user = $request->user();
        $canView = $user && ($user->hasRole('Admin') || $user->can('monitoring-live-view'));
        if (! $canView) {
            return response()->json(['message' => 'User does not have the right permissions.'], 403);
        }

        $minutes = min(max((int) $request->get('active_within_minutes', 15), 1), 60);
        $since = now()->subMinutes($minutes);
        $activeNowSince = now()->subMinutes(15);

        $search = trim((string) $request->get('search', ''));
        $roleFilters = $this->normalizeRoleFilterInput($request);
        $bucketFilter = trim((string) $request->get('bucket', ''));

        $sessions = AuthSession::query()
            ->with(['user.roles', 'user.profile'])
            ->whereNull('revoked_at')
            ->where('last_used_at', '>=', $since)
            ->when($search !== '', function ($q) use ($search) {
                $q->whereHas('user', function ($uq) use ($search) {
                    $uq->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when(count($roleFilters) > 0, function ($q) use ($roleFilters) {
                $q->whereHas('user.roles', fn ($rq) => $rq->whereIn('name', $roleFilters));
            })
            ->orderByDesc('last_used_at')
            ->get();

        $latestByUser = $sessions
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->sortByDesc('last_used_at')->first())
            ->filter();

        $rows = $latestByUser->map(function (AuthSession $session) use ($activeNowSince) {
            $user = $session->user;
            if (! $user) {
                return null;
            }
            if (isset($user->is_active) && ! $user->is_active) {
                return null;
            }

            $roles = $user->roles->pluck('name')->values()->all();
            $bucket = $this->monitoringBucket($roles);
            $isSupplier = in_array('Supplier', $roles, true);

            return [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $this->avatarUrl($user),
                'roles' => $roles,
                'monitoring_bucket' => $bucket,
                'role_type' => $isSupplier ? 'supplier' : 'staff',
                'session' => [
                    'id' => $session->id,
                    'device_name' => $session->device_name,
                    'browser' => $this->browserFromUserAgent((string) $session->user_agent),
                    'ip_address' => $session->ip_address,
                    'last_used_at' => $session->last_used_at?->toIso8601String(),
                    'active_now' => (bool) ($session->last_used_at && $session->last_used_at->gte($activeNowSince)),
                    'session_started_at' => $session->created_at?->toIso8601String(),
                    'session_duration_seconds' => $session->created_at ? $session->created_at->diffInSeconds(now()) : null,
                    'country' => $session->country,
                    'region' => $session->region,
                    'city' => $session->city,
                    'timezone' => $session->timezone,
                    'isp' => $session->isp,
                    'lat' => $session->lat,
                    'lon' => $session->lon,
                    'user_agent' => $session->user_agent,
                ],
            ];
        })->filter()->values();

        if ($bucketFilter !== '') {
            $allowedBuckets = ['admin', 'manager', 'staff', 'supplier', 'merchant', 'customer', 'mixed'];
            if (in_array($bucketFilter, $allowedBuckets, true)) {
                $rows = $rows->filter(fn ($r) => ($r['monitoring_bucket'] ?? '') === $bucketFilter)->values();
            }
        }

        $byBucket = [
            'admin' => 0,
            'manager' => 0,
            'staff' => 0,
            'supplier' => 0,
            'merchant' => 0,
            'customer' => 0,
            'mixed' => 0,
        ];
        $byRole = [];
        foreach ($rows as $row) {
            $b = $row['monitoring_bucket'] ?? 'mixed';
            if (isset($byBucket[$b])) {
                $byBucket[$b]++;
            }
            foreach ($row['roles'] ?? [] as $rn) {
                $byRole[$rn] = ($byRole[$rn] ?? 0) + 1;
            }
        }

        $internal = $byBucket['admin'] + $byBucket['manager'] + $byBucket['staff'];
        $activeStaff = $rows->filter(fn ($r) => in_array($r['monitoring_bucket'] ?? '', ['admin', 'manager', 'staff'], true))->values();
        $activeSuppliers = $rows->where('monitoring_bucket', 'supplier')->values();

        return response()->json([
            'active_within_minutes' => $minutes,
            'stats' => [
                'active_total' => $rows->count(),
                'active_staff' => $internal,
                'active_suppliers' => $byBucket['supplier'],
                'active_merchants' => $byBucket['merchant'],
                'active_customers' => $byBucket['customer'],
                'by_bucket' => $byBucket,
                'by_role' => $byRole,
            ],
            'active_staff' => $activeStaff,
            'active_suppliers' => $activeSuppliers,
            'data' => $rows,
            'meta' => [
                'available_roles' => Role::query()->orderBy('name')->pluck('name')->values()->all(),
                'available_buckets' => ['admin', 'manager', 'staff', 'supplier', 'merchant', 'customer', 'mixed'],
            ],
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeRoleFilterInput(Request $request): array
    {
        $raw = $request->input('roles', $request->input('role', []));
        if (is_string($raw)) {
            $raw = array_filter(array_map('trim', explode(',', $raw)));
        }
        if (! is_array($raw)) {
            return [];
        }
        $known = Role::query()->pluck('name')->all();

        return array_values(array_intersect($known, array_map('strval', $raw)));
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function monitoringBucket(array $roles): string
    {
        $set = array_flip($roles);
        if (isset($set['Admin'])) {
            return 'admin';
        }
        if (isset($set['Manager'])) {
            return 'manager';
        }
        if (isset($set['Staff'])) {
            return 'staff';
        }
        if (isset($set['Supplier'])) {
            return 'supplier';
        }
        if (isset($set['Merchant'])) {
            return 'merchant';
        }
        if (isset($set['Customer'])) {
            return 'customer';
        }

        return 'mixed';
    }

    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $canView = $user && ($user->hasRole('Admin') || $user->can('monitoring-live-view'));
        if (! $canView) {
            return response()->json(['message' => 'User does not have the right permissions.'], 403);
        }

        $perPage = min(max((int) $request->get('per_page', 20), 5), 100);
        $query = AuthSession::query()
            ->with(['user.roles'])
            ->orderByDesc('created_at');
        $userId = (int) $request->get('user_id', 0);
        $search = trim((string) $request->get('search', ''));
        $status = (string) $request->get('status', '');
        $roleType = (string) $request->get('role_type', '');
        $dateFrom = (string) $request->get('date_from', '');
        $dateTo = (string) $request->get('date_to', '');

        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('ip_address', 'like', "%{$search}%")
                    ->orWhere('device_name', 'like', "%{$search}%")
                    ->orWhere('user_agent', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }
        if ($dateFrom !== '') {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->whereDate('created_at', '<=', $dateTo);
        }
        if ($roleType === 'supplier') {
            $query->whereHas('user.roles', fn ($q) => $q->where('name', 'Supplier'));
        } elseif ($roleType === 'staff') {
            $query->whereHas('user.roles', fn ($q) => $q->where('name', '!=', 'Supplier'));
        }
        if ($status === 'active') {
            $query->whereNull('revoked_at')
                ->whereNotNull('last_used_at')
                ->where('last_used_at', '>=', now()->subMinutes(15));
        } elseif ($status === 'inactive') {
            $query->where(function ($q) {
                $q->whereNotNull('revoked_at')
                    ->orWhereNull('last_used_at')
                    ->orWhere('last_used_at', '<', now()->subMinutes(15));
            });
        }

        $paginator = $query->paginate($perPage);
        $collection = collect($paginator->items());
        $now = now();

        $rows = $collection->map(function (AuthSession $session) use ($now) {
            $u = $session->user;
            if (! $u) {
                return null;
            }
            $roles = $u->roles->pluck('name')->values()->all();
            $isSupplier = in_array('Supplier', $roles, true);
            $endAt = $this->sessionEndAt($session, $now);
            $duration = $session->created_at ? $session->created_at->diffInSeconds($endAt) : 0;

            return [
                'id' => $session->id,
                'user' => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'roles' => $roles,
                    'role_type' => $isSupplier ? 'supplier' : 'staff',
                ],
                'device_name' => $session->device_name,
                'browser' => $this->browserFromUserAgent((string) $session->user_agent),
                'ip_address' => $session->ip_address,
                'started_at' => $session->created_at?->toIso8601String(),
                'ended_at' => $endAt->toIso8601String(),
                'last_used_at' => $session->last_used_at?->toIso8601String(),
                'duration_seconds' => max(0, (int) $duration),
                'day_name' => $session->created_at ? $session->created_at->englishDayOfWeek : null,
                'is_active' => is_null($session->revoked_at) && $session->last_used_at && $session->last_used_at->gte(now()->subMinutes(15)),
            ];
        })->filter()->values();

        $staffWork = AuthSession::query()
            ->with(['user.roles'])
            ->whereHas('user.roles', function ($q) {
                $q->where('name', '!=', 'Supplier');
            })
            ->get()
            ->groupBy('user_id')
            ->map(function ($sessions, $userId) use ($now) {
                $first = $sessions->first();
                $u = $first?->user;
                if (! $u) {
                    return null;
                }
                $weekSeconds = 0;
                $monthSeconds = 0;
                foreach ($sessions as $s) {
                    $endAt = $this->sessionEndAt($s, $now);
                    $sec = $s->created_at ? $s->created_at->diffInSeconds($endAt) : 0;
                    if ($s->created_at && $s->created_at->gte(now()->startOfWeek())) {
                        $weekSeconds += $sec;
                    }
                    if ($s->created_at && $s->created_at->gte(now()->startOfMonth())) {
                        $monthSeconds += $sec;
                    }
                }

                return [
                    'user_id' => $userId,
                    'name' => $u->name,
                    'week_seconds' => $weekSeconds,
                    'month_seconds' => $monthSeconds,
                ];
            })->filter()->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'staff_work' => $staffWork,
        ]);
    }

    public function bulkDestroySessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $canView = $user && ($user->hasRole('Admin') || $user->can('monitoring-live-view'));
        if (! $canView) {
            return response()->json(['message' => 'User does not have the right permissions.'], 403);
        }

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['string'],
        ]);

        $sessions = AuthSession::query()->whereIn('id', $validated['ids'])->get(['id', 'refresh_jti']);
        foreach ($sessions as $session) {
            if ($session->refresh_jti) {
                cache()->forget('jwt_refresh:'.$session->refresh_jti);
            }
        }
        $deleted = AuthSession::query()->whereIn('id', $sessions->pluck('id'))->delete();

        return response()->json(['message' => 'OK', 'deleted' => $deleted]);
    }

    private function browserFromUserAgent(string $ua): string
    {
        $u = strtolower($ua);
        if (str_contains($u, 'edg/')) {
            return 'Edge';
        }
        if (str_contains($u, 'chrome/')) {
            return 'Chrome';
        }
        if (str_contains($u, 'safari/') && ! str_contains($u, 'chrome/')) {
            return 'Safari';
        }
        if (str_contains($u, 'firefox/')) {
            return 'Firefox';
        }

        return 'Browser';
    }

    private function avatarUrl($user): ?string
    {
        $profile = $user->profile;
        if ($profile && ! empty($profile->photo)) {
            return asset('storage/uploads/users/thumbnails/'.$profile->photo);
        }
        if (! empty($user->avatar)) {
            return $user->avatar;
        }

        return null;
    }

    private function sessionEndAt(AuthSession $session, CarbonInterface $fallback): CarbonInterface
    {
        if ($session->revoked_at) {
            return $session->revoked_at;
        }
        if ($session->last_used_at) {
            return $session->last_used_at;
        }
        if ($session->expires_at) {
            return $session->expires_at->lt($fallback) ? $session->expires_at : $fallback;
        }

        return $fallback;
    }
}
