<?php

namespace App\Http\Controllers;

use App\Models\PaymentGateway;
use App\Models\GatewayWallet;
use App\Models\GatewayTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Validator;

class GatewayWalletController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:gateways-list', ['only' => ['index', 'wallet']]);
        $this->middleware('permission:gateways-create', ['only' => ['create', 'store']]);
        $this->middleware('permission:gateways-edit', ['only' => ['edit', 'update']]);
        $this->middleware('permission:gateways-delete', ['only' => ['destroy']]);
        $this->middleware('permission:gateways-manage', ['only' => ['deduct', 'add']]);
    }

    public function index()
    {
        $gateways = PaymentGateway::with('wallet')->get();
        return view('pages.gateways.index', compact('gateways'))
            ->with('title', 'Payment Gateways')
            ->with('breadcrumb', 'Payment Gateways');
    }

    public function create()
    {
        return view('pages.gateways.create')
            ->with('title', __('Add Payment Gateway'))
            ->with('breadcrumb', __('Add Payment Gateway'));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:payment_gateways,slug',
        ]);
        if ($validator->fails()) {
            return redirect()->route('gateways.create')
                ->withErrors($validator)
                ->withInput();
        }
        $name = trim($request->name);
        $slug = $request->filled('slug') ? Str::slug($request->slug) : Str::slug($name);
        if (PaymentGateway::where('slug', $slug)->exists()) {
            return redirect()->route('gateways.create')
                ->withInput()
                ->withErrors(['slug' => __('This slug is already in use.')]);
        }
        DB::transaction(function () use ($name, $slug) {
            $gateway = PaymentGateway::create(['name' => $name, 'slug' => $slug]);
            GatewayWallet::create(['gateway_id' => $gateway->id, 'balance' => 0]);
        });
        return redirect()->route('gateways.index')
            ->with('success', __('Payment gateway added successfully.'));
    }

    public function edit($slug)
    {
        $gateway = PaymentGateway::where('slug', $slug)->firstOrFail();
        return view('pages.gateways.edit', compact('gateway'))
            ->with('title', __('Edit Payment Gateway'))
            ->with('breadcrumb', __('Edit Payment Gateway'));
    }

    public function update(Request $request, $slug)
    {
        $gateway = PaymentGateway::where('slug', $slug)->firstOrFail();
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return redirect()->route('gateways.edit', $slug)
                ->withErrors($validator)
                ->withInput();
        }
        $name = trim($request->name);
        $newSlug = $request->filled('slug') ? Str::slug($request->slug) : Str::slug($name);
        if ($newSlug !== $gateway->slug && PaymentGateway::where('slug', $newSlug)->exists()) {
            return redirect()->route('gateways.edit', $slug)
                ->withInput()
                ->withErrors(['slug' => __('This slug is already in use.')]);
        }
        $gateway->update(['name' => $name, 'slug' => $newSlug]);
        return redirect()->route('gateways.index')
            ->with('success', __('Payment gateway updated successfully.'));
    }

    public function destroy($slug)
    {
        $gateway = PaymentGateway::where('slug', $slug)->firstOrFail();
        $gateway->delete();
        return redirect()->route('gateways.index')
            ->with('success', __('Payment gateway deleted successfully.'));
    }

    public function wallet(Request $request, $slug)
    {
        $gateway = PaymentGateway::where('slug', $slug)->with('wallet')->firstOrFail();
        $wallet = $gateway->wallet;
        if (!$wallet) {
            $wallet = $gateway->wallet()->create(['balance' => 0]);
        }

        $month = $request->query('month');
        $year = $request->query('year');
        $query = GatewayTransaction::where('gateway_id', $gateway->id);

        if ($month && $year) {
            $query->whereYear('created_at', $year)->whereMonth('created_at', $month);
        }

        $transactions = $query->orderBy('created_at', 'desc')->limit(2000)->get();

        $monthlyOrderPayments = GatewayTransaction::where('gateway_id', $gateway->id)
            ->where('type', 'order_payment')
            ->where('amount', '>', 0)
            ->selectRaw('YEAR(created_at) as year, MONTH(created_at) as month, SUM(amount) as total')
            ->groupBy('year', 'month')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->limit(24)
            ->get();

        $selectedMonthTotal = $transactions->where('type', 'order_payment')->where('amount', '>', 0)->sum('amount');
        $selectedMonthFees = abs($transactions->where('type', 'fee')->sum('amount'));
        $selectedMonthDeposits = $transactions->where('type', 'deposit')->sum('amount');

        return view('pages.gateways.wallet', compact(
            'gateway', 'wallet', 'transactions',
            'month', 'year', 'monthlyOrderPayments',
            'selectedMonthTotal', 'selectedMonthFees', 'selectedMonthDeposits'
        ))
            ->with('title', $gateway->name . ' Wallet')
            ->with('breadcrumb', $gateway->name . ' Wallet');
    }

    public function deduct(Request $request, $slug)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }
        $gateway = PaymentGateway::where('slug', $slug)->with('wallet')->firstOrFail();
        $wallet = $gateway->wallet;
        if (!$wallet) {
            $wallet = $gateway->wallet()->create(['balance' => 0]);
        }
        $amount = (float) $request->amount;
        DB::transaction(function () use ($gateway, $wallet, $amount, $request) {
            GatewayTransaction::create([
                'gateway_id' => $gateway->id,
                'type' => 'fee',
                'amount' => -$amount,
                'notes' => $request->notes ?? 'Gateway fee / commission',
            ]);
            $wallet->decrement('balance', $amount);
        });
        return redirect()->route('gateways.wallet', $slug)
            ->with('success', 'Deduction applied successfully.');
    }

    public function add(Request $request, $slug)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }
        $gateway = PaymentGateway::where('slug', $slug)->with('wallet')->firstOrFail();
        $wallet = $gateway->wallet;
        if (!$wallet) {
            $wallet = $gateway->wallet()->create(['balance' => 0]);
        }
        $amount = (float) $request->amount;
        DB::transaction(function () use ($gateway, $wallet, $amount, $request) {
            GatewayTransaction::create([
                'gateway_id' => $gateway->id,
                'type' => 'deposit',
                'amount' => $amount,
                'notes' => $request->notes ?? 'Manual adjustment',
            ]);
            $wallet->increment('balance', $amount);
        });
        return redirect()->route('gateways.wallet', $slug)
            ->with('success', 'Amount added successfully.');
    }
}
