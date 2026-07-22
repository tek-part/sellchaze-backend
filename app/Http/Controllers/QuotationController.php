<?php

namespace App\Http\Controllers;

use App\Actions\Quotations\AcceptQuotationAction;
use App\Events\DashboardStatsUpdated;
use App\Http\Controllers\Concerns\AppliesMailConfig;
use App\Models\Order;
use App\Models\OrderQuotations;
use App\Models\User;
use App\Notifications\QuotationCreated;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Notification;
use Redirect;
use Session;
use Validator;

class QuotationController extends Controller
{
    use AppliesMailConfig;

    public function __construct()
    {
        $this->middleware('permission:quotations-out', ['only' => ['out', 'accept', 'addTracking']]);
        $this->middleware('permission:quotations-in', ['only' => ['in']]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function in(Request $request)
    {
        $user = Auth::user();
        $isAdmin = ((int) $user->id === 1) || (($user->email ?? '') === 'admin@admin.com') || $user->hasRole('Admin');
        $rowsQuery = OrderQuotations::query()
            ->selectRaw('order_id, MAX(id) as max_id')
            ->groupBy('order_id')
            ->orderByDesc(DB::raw('MAX(id)'))
            ->limit(2000);
        if (! $isAdmin) {
            $rowsQuery->where('customer_user_id', b2bListingsUserId());
        }
        $rows = $rowsQuery->get();
        $orderedIds = $rows->pluck('order_id')->values();
        $pos = $orderedIds->flip();
        $orders = Order::query()
            ->with(['product', 'quotations', 'suppliers'])
            ->whereIn('id', $orderedIds)
            ->get()
            ->sortBy(fn ($o) => $pos[$o->id] ?? 99999)
            ->values();

        return view('pages.quotations.in', compact('orders'))->with('title', 'My quotations in')->with('breadcrumb', 'My quotations in');
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function out(Request $request)
    {
        $supplier_user_id = b2bListingsUserId();
        $rows = OrderQuotations::query()
            ->selectRaw('order_id, MAX(id) as max_id')
            ->where('supplier_user_id', $supplier_user_id)
            ->groupBy('order_id')
            ->orderByDesc(DB::raw('MAX(id)'))
            ->limit(2000)
            ->get();
        $idOrder = $rows->pluck('max_id')->values();
        $pos = $idOrder->flip();
        $quotations = OrderQuotations::query()
            ->with(['order', 'customer'])
            ->whereIn('id', $idOrder)
            ->get()
            ->sortBy(fn ($q) => $pos[$q->id] ?? 99999)
            ->values();

        return view('pages.quotations.out', compact('quotations'))->with('title', 'My quotations out')->with('breadcrumb', 'My quotations out');
    }

    /**
     * Supplier accept order request from customer and add new quotation to order.
     *
     * @param  string  $code
     * @return Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function accept(Request $request, $code)
    {
        $rules = [
            'price' => 'required|numeric|min:0',
            'delivery_date' => 'required',
            'currency' => 'nullable|string|max:10',
            'shipping_company' => 'nullable|string|max:100',
            'price_includes_shipping' => 'nullable|boolean',
        ];
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return Redirect::back()->withErrors($validator)->withInput();
        }

        $order = Order::where('code', $code)->first();

        if (! $order || empty($code)) {
            abort(404);
        }

        $OrderQuotation = new OrderQuotations;
        $OrderQuotation->price = $request->price;
        $OrderQuotation->delivery_date = $request->delivery_date;
        $OrderQuotation->notes = $request->notes;
        $OrderQuotation->currency = $request->currency ?? 'EGP';
        $OrderQuotation->shipping_company = $request->shipping_company;
        $OrderQuotation->price_includes_shipping = $request->boolean('price_includes_shipping', true);
        $OrderQuotation->order_id = $order->id;
        $OrderQuotation->supplier_user_id = Auth::user()->id;
        $OrderQuotation->customer_user_id = $order->user_id;

        $OrderQuotation->save();

        event(new DashboardStatsUpdated);

        $supplier = User::find($OrderQuotation->supplier_user_id);
        $customer = User::find($OrderQuotation->customer_user_id);

        $data = [
            'greeting' => 'Hi '.$customer->name.', you received a new quotation!',
            'body' => '<b>'.$supplier->name.'</b> offered a quotation for your order, check your account for more information.',
            'thanks' => 'Thank you',
            'actionText' => 'Quotation Details',
            'actionURL' => route('orders.quotations', $order->code),
            'supplier_id' => $OrderQuotation->supplier_user_id,
            'order_id' => $order->id,
        ];

        $this->applyMailConfigFromSettings();
        try {
            Notification::send($customer, new QuotationCreated($data));
        } catch (\Throwable $e) {
            Log::warning('QuotationCreated notification failed (mail/offline SMTP).', [
                'order_id' => $order->id,
                'customer_user_id' => $customer->id,
                'message' => $e->getMessage(),
            ]);
        }

        Session::flash('success', 'Order quotation was successfully accepted.');

        return redirect()->route('orders.in', [], 303);
    }

    /**
     * Customer confirm order quotation from supplier and change quotation status.
     *
     * @param  string  $quotation_id
     * @return Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function confirm(Request $request, $quotation_id)
    {
        $this->applyMailConfigFromSettings();
        $decryptedId = decrypt($quotation_id);
        $orderQuotation = app(AcceptQuotationAction::class)($decryptedId);

        if (! $orderQuotation) {
            abort(404);
        }

        Session::flash('success', 'Order quotation was successfully confirmed.');

        return redirect()->back(303);
    }

    /**
     * Supplier adds tracking number when shipping.
     */
    public function addTracking(Request $request, $id)
    {
        $request->validate(['tracking_number' => 'required|string|max:255']);
        $quotation = OrderQuotations::where('id', $id)
            ->where('supplier_user_id', Auth::id())
            ->firstOrFail();
        $quotation->update([
            'tracking_number' => $request->tracking_number,
            'shipped_at' => now(),
        ]);
        Session::flash('success', __('Tracking number added successfully.'));

        return redirect()->back(303);
    }
}
