<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\EmailLog;
use App\Models\LoginSession;
use App\Models\User;
use App\Models\VerificationRequest;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Admin-only dashboard: KPIs, user growth, store-order volume, roles breakdown,
 * pending queues (registrations + verifications), errors, email stats.
 */
class AdminDashboardApiController extends Controller
{
    public function overview(): JsonResponse
    {
        $since30 = Carbon::now()->subDays(30)->startOfDay();
        $since60 = Carbon::now()->subDays(60)->startOfDay();
        $since7 = Carbon::now()->subDays(7)->startOfDay();

        // ---------- KPIs ----------
        $usersTotal = User::count();
        $usersLast30 = User::where('created_at', '>=', $since30)->count();
        $usersPrev30 = User::whereBetween('created_at', [$since60, $since30])->count();
        $growth = $usersPrev30 > 0
            ? round((($usersLast30 - $usersPrev30) / $usersPrev30) * 100, 1)
            : ($usersLast30 > 0 ? 100 : 0);

        $pendingRegistrations = User::where('pending_approval', true)->count();

        $pendingVerifications = 0;
        if (class_exists(VerificationRequest::class)) {
            $pendingVerifications = VerificationRequest::where('status', 'pending')->count();
        } else {
            $pendingVerifications = 0;
        }

        // Store orders across every store (admin view, so no tenant scope).
        $storeOrders = fn () => DB::table('store_orders')->whereNull('deleted_at');
        $orders30 = $storeOrders()->where('created_at', '>=', $since30)->count();

        $activeSessions = 0;
        if (class_exists(LoginSession::class)) {
            $activeSessions = LoginSession::whereNull('logged_out_at')->count();
        }

        // ---------- User signup chart (30 days) ----------
        $signupRaw = User::where('created_at', '>=', $since30)
            ->select(DB::raw('DATE(created_at) as d'), DB::raw('COUNT(*) as c'))
            ->groupBy('d')->pluck('c', 'd')->all();

        $signupChart = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = Carbon::now()->subDays($i)->toDateString();
            $signupChart[] = ['date' => $d, 'count' => (int) ($signupRaw[$d] ?? 0)];
        }

        // ---------- Orders chart (30 days) ----------
        $ordersRaw = $storeOrders()->where('created_at', '>=', $since30)
            ->select(DB::raw('DATE(created_at) as d'), DB::raw('COUNT(*) as c'))
            ->groupBy('d')->pluck('c', 'd')->all();

        $ordersChart = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = Carbon::now()->subDays($i)->toDateString();
            $ordersChart[] = ['date' => $d, 'count' => (int) ($ordersRaw[$d] ?? 0)];
        }

        // ---------- Roles breakdown ----------
        $rolesBreakdown = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->select('roles.name', DB::raw('COUNT(*) as c'))
            ->groupBy('roles.name')
            ->get()
            ->map(fn ($r) => ['role' => $r->name, 'count' => (int) $r->c])
            ->values();

        // ---------- Recent errors (activity_logs level=error) ----------
        $recentErrors = ActivityLog::where('level', 'error')
            ->latest('created_at')->take(5)
            ->get(['action', 'properties', 'created_at'])
            ->map(fn ($l) => [
                'action' => $l->action,
                'message' => is_array($l->properties) ? ($l->properties['message'] ?? null) : null,
                'created_at' => $l->created_at?->toIso8601String(),
            ]);

        // ---------- Pending queues ----------
        $pendingRegsList = User::where('pending_approval', true)
            ->latest('created_at')->take(5)
            ->get(['id', 'name', 'email', 'created_at'])
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'created_at' => $u->created_at?->toIso8601String(),
            ]);

        $pendingVerifsList = [];
        if (class_exists(VerificationRequest::class)) {
            $pendingVerifsList = VerificationRequest::where('status', 'pending')
                ->with('user:id,name,email')
                ->latest('created_at')->take(5)->get()
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'user_id' => $v->user_id,
                    'user_name' => $v->user->name ?? null,
                    'created_at' => $v->created_at?->toIso8601String(),
                ])->all();
        }

        // ---------- Email stats last 7 days ----------
        $emailSent = EmailLog::where('status', 'sent')->where('created_at', '>=', $since7)->count();
        $emailFailed = EmailLog::where('status', 'failed')->where('created_at', '>=', $since7)->count();
        $emailQueued = EmailLog::where('status', 'queued')->where('created_at', '>=', $since7)->count();

        return response()->json([
            'kpis' => [
                'users_total' => $usersTotal,
                'users_growth_30d_pct' => $growth,
                'users_new_30d' => $usersLast30,
                'pending_registrations' => $pendingRegistrations,
                'pending_verifications' => $pendingVerifications,
                'orders_30d' => $orders30,
                'active_sessions' => $activeSessions,
            ],
            'users_signup_chart' => $signupChart,
            'orders_chart' => $ordersChart,
            'roles_breakdown' => $rolesBreakdown,
            'recent_errors' => $recentErrors,
            'pending_queue' => [
                'registrations' => $pendingRegsList,
                'verifications' => $pendingVerifsList,
            ],
            'email_stats_7d' => [
                'sent' => $emailSent,
                'failed' => $emailFailed,
                'queued' => $emailQueued,
            ],
        ]);
    }
}
