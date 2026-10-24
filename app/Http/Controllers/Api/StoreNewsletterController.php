<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreNewsletterController extends Controller
{
    public function index(Request $request, Store $store): JsonResponse
    {
        $filters = $request->validate([
            'channel' => ['nullable', Rule::in(['email', 'phone'])],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'created_from' => ['nullable', 'date'], 'created_to' => ['nullable', 'date'],
            'sort_by' => ['nullable', Rule::in(['created_at', 'email', 'phone', 'name'])], 'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $email = DB::table('newsletter_subscribers')->where('store_id', $store->id)
            ->selectRaw("id, 'email' as channel, NULL as name, email, NULL as phone, is_active, created_at");
        $phone = DB::table('phone_newsletter_subscribers')->where('store_id', $store->id)
            ->selectRaw("id, 'phone' as channel, name, NULL as email, phone, is_active, created_at");
        $query = DB::query()->fromSub($email->unionAll($phone), 'subscribers');
        if (! empty($filters['channel'])) {
            $query->where('channel', $filters['channel']);
        }
        if (filled($filters['search'] ?? null)) {
            $literal = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%';
            $query->where(fn ($query) => $query->whereRaw("name LIKE ? ESCAPE '!'", [$literal])
                ->orWhereRaw("email LIKE ? ESCAPE '!'", [$literal])
                ->orWhereRaw("phone LIKE ? ESCAPE '!'", [$literal]));
        }
        if (! empty($filters['status'])) {
            $query->where('is_active', $filters['status'] === 'active');
        }
        if (! empty($filters['created_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['created_from'])->startOfDay());
        }
        if (! empty($filters['created_to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['created_to'])->endOfDay());
        }
        $paginator = $query->orderBy($filters['sort_by'] ?? 'created_at', $filters['sort_direction'] ?? 'desc')
            ->orderByDesc('id')->orderBy('channel')->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total(), 'per_page' => $paginator->perPage()],
        ]);
    }
}
