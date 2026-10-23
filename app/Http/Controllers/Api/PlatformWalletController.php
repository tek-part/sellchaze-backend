<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformWalletPayment;
use App\Models\User;
use App\Services\PlatformWallet;
use App\Services\PlatformWalletFunding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PlatformWalletController extends Controller
{
    private function owner(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->hasAnyRole(['Merchant']), 403);

        return $user;
    }

    public function wallet(Request $request, PlatformWallet $wallet): JsonResponse
    {
        $account = DB::table('platform_wallet_accounts')->where('user_id', $this->owner($request)->id)->first();
        $balance = (int) ($account->balance_milli ?? 0);
        $pending = PlatformWalletPayment::where('user_id', $this->owner($request)->id)->whereIn('status', ['creating', 'uncertain', 'pending'])->latest()->first();

        return response()->json(['currency' => 'USD', 'balance_milli' => max(0, $balance), 'amount_due_milli' => max(0, -$balance), 'fees_enabled' => $wallet->feesEnabled(), 'fee_milli' => (int) config('platform_wallet.fee_milli'), 'debt_order_limit' => (int) config('platform_wallet.debt_order_limit'), 'orders_locked' => $wallet->ordersLocked($this->owner($request)), 'funding_provider' => 'fawaterak', 'funding_provider_configured' => app(PlatformWalletFunding::class)->configured(), 'funding_presets_milli' => PlatformWalletFunding::PRESETS, 'funding_test_mode' => (bool) config('platform_wallet.fawaterak.test_mode'), 'pending_payment' => $pending ? app(PlatformWalletFunding::class)->present($pending) : null]);
    }

    public function fund(Request $request, PlatformWalletFunding $funding): JsonResponse
    {
        $user = $this->owner($request);
        $data = $request->validate(['amount_milli' => ['required', 'integer', 'in:5000,10000,20000,50000,100000,200000,600000'], 'request_key' => ['required', 'uuid']]);

        return response()->json($funding->present($funding->start($user, $data['amount_milli'], strtolower($data['request_key']))));
    }

    public function refreshPayment(Request $request, string $payment, PlatformWalletFunding $funding): JsonResponse
    {
        $attempt = PlatformWalletPayment::where('user_id', $this->owner($request)->id)->whereKey($payment)->firstOrFail();

        return response()->json($funding->present($funding->refresh($attempt)));
    }

    public function fawaterak(Request $request, PlatformWalletFunding $funding): JsonResponse
    {
        $data = $request->validate(['invoice_id' => ['required', 'integer', 'min:1'], 'invoice_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,100}$/'], 'payment_method' => ['required', 'string', 'max:100'], 'hashKey' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'pay_load' => ['nullable', 'array'], 'pay_load.wallet_payment_id' => ['nullable', 'uuid']]);
        $funding->callback($data);

        return response()->json(['received' => true]);
    }

    public function entries(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'in:25,50,100'], 'sort' => ['nullable', 'in:type,amount,created_at'], 'direction' => ['nullable', 'in:asc,desc']]);
        $account = DB::table('platform_wallet_accounts')->where('user_id', $this->owner($request)->id)->first();
        $field = ['type' => 'type', 'amount' => 'amount_milli', 'created_at' => 'created_at'][$data['sort'] ?? 'created_at'];
        $entries = DB::table('platform_wallet_entries')->where('account_id', $account->id ?? 0);
        $events = (clone $entries)->selectRaw("id * 2 as id, id as entry_id, type, amount_milli, balance_after_milli, created_at, 'event' as row_kind");
        $balances = (clone $entries)->selectRaw("id * 2 + 1 as id, id as entry_id, type, NULL as amount_milli, balance_after_milli, created_at, 'balance' as row_kind");
        $rows = DB::query()->fromSub($events->unionAll($balances), 'statement')->orderBy($field, $data['direction'] ?? 'desc')->orderByDesc('entry_id')->orderBy('id')->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => collect($rows->items())->map(fn ($row) => ['id' => $row->id, 'entry_id' => $row->entry_id, 'row_kind' => $row->row_kind, 'type' => $row->type, 'amount_milli' => $row->amount_milli === null ? null : (int) $row->amount_milli, 'balance_after_milli' => (int) $row->balance_after_milli, 'created_at' => Carbon::parse($row->created_at)->toIso8601String()])->values(), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'entries_total' => $entries->count()]]);
    }

    public function referral(Request $request, PlatformWallet $wallet): JsonResponse
    {
        $user = $this->owner($request);
        $profile = DB::table('platform_referral_profiles')->where('user_id', $user->id)->first();
        $base = rtrim((string) config('sellchase.frontend_url'), '/');
        $parts = parse_url($base);
        abort_unless(filter_var($base, FILTER_VALIDATE_URL) && $parts && in_array($parts['scheme'] ?? '', ['http', 'https'], true) && isset($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']), 503, 'Platform public URL needs configuration.');

        return response()->json(['currency' => 'USD', 'commission_milli' => $profile ? (int) DB::table('platform_commission_entries')->where('profile_id', $profile->id)->sum('amount_milli') : 0, 'commission_basis_points' => $wallet->rate(), 'commissions_enabled' => (bool) config('platform_wallet.commissions_enabled'), 'referred_stores' => $profile ? $wallet->referredStores($profile->id) : 0, 'minimum_referred_stores' => (int) config('platform_wallet.minimum_referred_stores'), 'link' => $profile ? $base.'/register?view='.$profile->code : null]);
    }

    public function createReferral(Request $request, PlatformWallet $wallet): JsonResponse
    {
        $wallet->profile($this->owner($request));

        return $this->referral($request, $wallet);
    }

    /** A manual reconciliation endpoint, not a customer charge or gateway webhook. */
    public function reconcile(Request $request, PlatformWallet $wallet): JsonResponse
    {
        abort_unless($request->user()->hasRole('Admin'), 403);
        $data = $request->validate(['currency' => ['required', 'in:USD'], 'user_id' => ['required', 'integer', 'exists:users,id'], 'operation' => ['required', 'in:funding,refund,payout'], 'amount_milli' => ['required', 'integer', 'min:1', 'max:600000'], 'reference' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9_.:-]+\z/'], 'confirmed' => ['required', 'accepted'], 'funding_id' => ['required_if:operation,refund', 'nullable', 'integer', 'min:1']]);
        $user = User::query()->findOrFail($data['user_id']);
        abort_unless($user->hasAnyRole(['Merchant']), 422);
        $entry = match ($data['operation']) {
            'funding' => $wallet->funding($user, $data['amount_milli'], $data['reference'], $request->user()),
            'refund' => $wallet->refund($user, $data['funding_id'], $data['amount_milli'], $data['reference'], $request->user()),
            'payout' => $wallet->payout($user, $data['amount_milli'], $data['reference'], $request->user()),
        };

        return response()->json(['id' => $entry->id, 'type' => $entry->type, 'amount_milli' => (int) $entry->amount_milli]);
    }
}
