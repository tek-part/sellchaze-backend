<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\OrderQuotations;
use App\Models\SupplierPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SupplierPaymentController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:suppliers-payments-list', ['only' => ['index', 'transactions']]);
        $this->middleware('permission:suppliers-payments-manage', ['only' => ['store']]);
    }

    public function index(Request $request)
    {
        $suppliers = User::whereHas('quotations', function ($q) {
            $q->where('status', 'accepted');
        })->get();
        $balances = [];
        foreach ($suppliers as $supplier) {
            $totalOrders = OrderQuotations::where('supplier_user_id', $supplier->id)
                ->where('status', 'accepted')
                ->sum('price');
            $totalPayments = SupplierPayment::where('supplier_id', $supplier->id)->sum('amount');
            $balance = $totalOrders - $totalPayments;
            $balances[] = (object) [
                'supplier' => $supplier,
                'total_orders' => $totalOrders,
                'total_payments' => $totalPayments,
                'balance' => $balance,
            ];
        }
        if ($request->filled('supplier_id')) {
            $balances = array_filter($balances, fn ($b) => $b->supplier->id == (int) $request->supplier_id);
        }
        return view('pages.suppliers.payments', compact('balances'))
            ->with('title', __('Supplier Payments'))
            ->with('breadcrumb', __('Supplier Payments'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'supplier_id' => 'required|exists:users,id',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:500',
        ]);
        SupplierPayment::create([
            'supplier_id' => $request->supplier_id,
            'amount' => $request->amount,
            'notes' => $request->notes,
            'recorded_by' => Auth::id(),
        ]);
        return redirect()->back()->with('success', __('Payment recorded.'));
    }

    public function transactions($supplierId)
    {
        $supplier = User::findOrFail($supplierId);
        $payments = SupplierPayment::where('supplier_id', $supplierId)->with('recorder')->orderBy('created_at', 'desc')->get();
        $totalOrders = OrderQuotations::where('supplier_user_id', $supplierId)->where('status', 'accepted')->sum('price');
        $totalPayments = $payments->sum('amount');
        $balance = $totalOrders - $totalPayments;
        return view('pages.suppliers.transactions', compact('supplier', 'payments', 'totalOrders', 'totalPayments', 'balance'))
            ->with('title', __('Payments - ') . $supplier->name)
            ->with('breadcrumb', __('Supplier Payments'));
    }
}
