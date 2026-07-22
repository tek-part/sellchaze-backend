<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Rbac\UserScope;
use App\Services\Stores\DomainMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform-wide custom-domain metrics. Admin only — these aggregate across every
 * tenant, so they are never exposed to a store owner.
 */
class DomainMetricsApiController extends Controller
{
    public function __construct(private readonly DomainMetricsService $metrics) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        return response()->json(['data' => $this->metrics->collect()]);
    }

    /** Prometheus scrape endpoint (text exposition format). */
    public function prometheus(Request $request): Response
    {
        $this->assertAdmin($request);

        return response(
            $this->metrics->toPrometheus(),
            200,
            ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8'],
        );
    }

    private function assertAdmin(Request $request): void
    {
        $user = $request->user();

        abort_unless($user !== null && UserScope::isAdmin($user), 403);
    }
}
