<?php

namespace App\Http\Controllers;

use App\Models\MerchantWigpleasureCategorySupplier;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MerchantSupplierRoutingController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:supplier-routings-manage');
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $query = MerchantWigpleasureCategorySupplier::query()->with(['merchant', 'supplier']);

        if ($user->hasRole('Admin')) {
            $routings = $query->orderBy('merchant_id')->orderBy('wigpleasure_category_id')->limit(5000)->get();
            $merchants = User::role('Merchant')->orderBy('name')->get(['id', 'name', 'email']);
        } else {
            $routings = $query->where('merchant_id', $user->id)->orderBy('wigpleasure_category_id')->get();
            $merchants = collect();
        }

        $partnerSuppliers = $user->hasRole('Merchant')
            ? $user->suppliersAsMerchant()->wherePivot('status', 'accepted')->orderBy('name')->get()
            : collect();

        return view('pages.supplier-routings.index', compact('routings', 'merchants', 'partnerSuppliers'))
            ->with('title', __('Store category routing'))
            ->with('breadcrumb', __('Store category routing'));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $merchantId = $user->hasRole('Admin')
            ? (int) $request->input('merchant_id')
            : (int) $user->id;

        $merchant = User::findOrFail($merchantId);
        if (! $merchant->hasRole('Merchant')) {
            abort(422, 'Target user must be a merchant.');
        }

        if (! $user->hasRole('Admin') && (int) $merchantId !== (int) $user->id) {
            abort(403);
        }

        $validated = $request->validate([
            'wigpleasure_category_id' => 'required|integer|min:1',
            'supplier_user_id' => 'required|integer|exists:users,id',
        ]);

        $supplierId = (int) $validated['supplier_user_id'];
        $this->assertSupplierLinkedToMerchant($merchantId, $supplierId);

        MerchantWigpleasureCategorySupplier::firstOrCreate(
            [
                'merchant_id' => $merchantId,
                'wigpleasure_category_id' => (int) $validated['wigpleasure_category_id'],
                'supplier_user_id' => $supplierId,
            ]
        );

        return redirect()->route('supplier-routings.index', [], 303)
            ->with('success', __('Routing saved.'));
    }

    public function destroy(Request $request, MerchantWigpleasureCategorySupplier $routing): RedirectResponse
    {
        $user = $request->user();
        if (! $user->hasRole('Admin') && (int) $routing->merchant_id !== (int) $user->id) {
            abort(403);
        }
        $routing->delete();

        return redirect()->route('supplier-routings.index', [], 303)
            ->with('success', __('Routing removed.'));
    }

    private function assertSupplierLinkedToMerchant(int $merchantId, int $supplierUserId): void
    {
        $ok = User::find($merchantId)
            ?->suppliersAsMerchant()
            ->wherePivot('status', 'accepted')
            ->where('users.id', $supplierUserId)
            ->exists();
        if (! $ok) {
            abort(422, 'Supplier must be an accepted partner of this merchant (Invitations).');
        }
    }
}
