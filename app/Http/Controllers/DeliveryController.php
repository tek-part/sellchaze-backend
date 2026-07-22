<?php

namespace App\Http\Controllers;

use App\Models\OrderDelivery;
use App\Models\Order;
use Illuminate\Http\Request;

class DeliveryController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:deliveries-list', ['only' => ['index']]);
        $this->middleware('permission:deliveries-update', ['only' => ['updateStatus', 'store']]);
    }

    public function index(Request $request)
    {
        $query = OrderDelivery::with('order.product', 'order.user');
        if ($request->filled('company')) {
            $query->where('delivery_company', $request->company);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        $deliveries = $query->orderBy('created_at', 'desc')->limit(2000)->get();
        return view('pages.deliveries.index', compact('deliveries'))
            ->with('title', 'Deliveries')
            ->with('breadcrumb', 'Deliveries');
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|in:pending,in_transit,delivered,failed']);
        $delivery = OrderDelivery::findOrFail($id);
        $delivery->status = $request->status;
        if ($request->status === 'delivered') {
            $delivery->delivered_at = now();
        }
        $delivery->save();
        return redirect()->back()->with('success', 'Status updated.');
    }

    public function store(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'delivery_company' => 'required|string|in:aramex,careem,yahiya,manual',
            'tracking_number' => 'nullable|string|max:255',
            'cod_amount' => 'nullable|numeric|min:0',
        ]);
        OrderDelivery::create([
            'order_id' => $request->order_id,
            'delivery_company' => $request->delivery_company,
            'tracking_number' => $request->tracking_number,
            'cod_amount' => $request->cod_amount,
            'status' => 'pending',
        ]);
        return redirect()->back()->with('success', __('Delivery added successfully.'));
    }
}
