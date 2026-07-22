<?php

namespace App\Http\Controllers;

use App\Events\DashboardStatsUpdated;
use App\Http\Controllers\Concerns\AppliesMailConfig;
use App\Models\Category;
use App\Models\MerchantWigpleasureCategorySupplier;
use App\Models\Order;
use App\Models\OrderQuotations;
use App\Models\OrderSuppliers;
use App\Models\Product;
use App\Models\User;
use App\Notifications\OrderCreated;
use File;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Image;
use Notification;
use Session;

class OrderController extends Controller
{
    use AppliesMailConfig;

    public function __construct()
    {
        $this->middleware('permission:orders-out', ['only' => ['out', 'saveWigpleasureRoutingFromOut']]);
        $this->middleware('permission:orders-in', ['only' => ['in']]);
        $this->middleware('permission:orders-create', ['only' => ['create', 'store']]);
        $this->middleware('permission:orders-out|orders-in', ['only' => ['index', 'show', 'edit', 'update', 'destroy', 'bulkDestroy', 'quotations', 'supplierOrders']]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = Order::with('product', 'user', 'suppliers')->latest();
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('supplier')) {
            $query->whereHas('suppliers', fn ($q) => $q->where('supplier', $request->supplier));
        }
        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('orders.code', 'like', '%'.$term.'%')
                    ->orWhere('orders.ref_number', 'like', '%'.$term.'%')
                    ->orWhereHas('product', function ($pq) use ($term) {
                        $pq->where('name', 'like', '%'.$term.'%');
                    });
            });
        }
        $orders = $query->limit(5000)->get();
        $suppliers = User::whereIn('id', OrderSuppliers::distinct()->pluck('supplier'))->orderBy('name')->get();

        return view('pages.orders.index', compact('orders', 'suppliers'))
            ->with('i', 0)
            ->with('title', 'Latest orders')
            ->with('breadcrumb', 'Latest orders')
            ->with('filters', [
                'search' => $request->get('search'),
                'status' => $request->get('status'),
                'supplier' => $request->get('supplier'),
            ]);
    }

    public function supplierOrders($supplierId)
    {
        $supplier = User::findOrFail($supplierId);
        $orders = Order::whereHas('suppliers', fn ($q) => $q->where('supplier', $supplierId))
            ->with('product', 'user')
            ->orderBy('created_at', 'desc')
            ->limit(3000)
            ->get();

        return view('pages.orders.supplier', compact('orders', 'supplier'))
            ->with('title', 'Orders - '.$supplier->name)
            ->with('breadcrumb', 'Supplier Orders');
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function in(Request $request)
    {
        $supplier = b2bListingsUserId();
        $query = Order::whereHas('suppliers', function ($query) use ($supplier) {
            $query->where('supplier', $supplier);
        })->whereDoesntHave('quotations', function ($query) use ($supplier) {
            $query->where('supplier_user_id', $supplier);
        })->whereDoesntHave('quotations', function ($query) {
            $query->where('status', 'accepted');
        })->with(['suppliers', 'product', 'quotations']);

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('orders.code', 'like', '%'.$term.'%')
                    ->orWhere('orders.ref_number', 'like', '%'.$term.'%')
                    ->orWhereHas('product', function ($pq) use ($term) {
                        $pq->where('name', 'like', '%'.$term.'%');
                    });
            });
        }
        if ($request->filled('status')) {
            $query->where('orders.status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('orders.created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('orders.created_at', '<=', $request->date_to);
        }

        $orders = $query->orderBy('orders.id', 'desc')->limit(3000)->get();

        return view('pages.orders.in', compact('orders'))
            ->with('i', 0)
            ->with('title', 'My orders in')
            ->with('breadcrumb', 'My orders in')
            ->with('filters', [
                'search' => $request->get('search'),
                'status' => $request->get('status'),
                'date_from' => $request->get('date_from'),
                'date_to' => $request->get('date_to'),
            ]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function out(Request $request)
    {
        $user = Auth::user();
        $isAdmin = ((int) $user->id === 1) || (($user->email ?? '') === 'admin@admin.com') || $user->hasRole('Admin');
        $query = Order::query()->with(['suppliers', 'product', 'quotations']);
        if (! $isAdmin) {
            $customer = b2bListingsUserId();
            $query->whereHas('suppliers', function ($q) use ($customer) {
                $q->where('customer', $customer)->whereNotNull('supplier');
            });
        } else {
            $query->whereHas('suppliers', fn ($q) => $q->whereNotNull('supplier'));
        }

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('orders.code', 'like', '%'.$term.'%')
                    ->orWhere('orders.ref_number', 'like', '%'.$term.'%')
                    ->orWhereHas('product', function ($pq) use ($term) {
                        $pq->where('name', 'like', '%'.$term.'%');
                    });
            });
        }
        if ($request->filled('status')) {
            $query->where('orders.status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('orders.created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('orders.created_at', '<=', $request->date_to);
        }

        $orders = $query->orderBy('orders.id', 'desc')->limit(3000)->get();

        $partnerSuppliers = collect();
        $showMerchantRoutingOnOut = false;
        if ($user->hasRole('Merchant') && ! $isAdmin) {
            $showMerchantRoutingOnOut = true;
            $partnerSuppliers = $user->suppliersAsMerchant()
                ->wherePivot('status', 'accepted')
                ->orderBy('name')
                ->get();
        }

        return view('pages.orders.out', compact('orders', 'partnerSuppliers', 'showMerchantRoutingOnOut'))
            ->with('i', 0)
            ->with('title', 'My orders out')
            ->with('breadcrumb', 'My orders out')
            ->with('filters', [
                'search' => $request->get('search'),
                'status' => $request->get('status'),
                'date_from' => $request->get('date_from'),
                'date_to' => $request->get('date_to'),
            ]);
    }

    /**
     * Merchant: save Wigpleasure category → supplier mappings and optionally replace order_suppliers for this order.
     */
    public function saveWigpleasureRoutingFromOut(Request $request, string $code)
    {
        $user = Auth::user();
        if (! $user->hasRole('Merchant')) {
            abort(403);
        }

        $validated = $request->validate([
            'wigpleasure_category_id' => 'required|integer|min:1',
            'supplier_ids' => 'required|array|min:1',
            'supplier_ids.*' => 'integer|exists:users,id',
            'apply_to_order' => 'nullable|boolean',
        ]);

        $order = Order::where('code', $code)->with('suppliers')->firstOrFail();
        if (! $order->merchantIsPurchaseCustomer($user)) {
            abort(403);
        }

        $merchantId = (int) $user->id;
        $categoryId = (int) $validated['wigpleasure_category_id'];
        $supplierIds = array_values(array_unique(array_map('intval', $validated['supplier_ids'])));

        foreach ($supplierIds as $supplierId) {
            if (! $this->merchantAcceptsSupplier($merchantId, $supplierId)) {
                return redirect()->back()->withErrors([
                    'supplier_ids' => __('Each selected supplier must be an accepted partner (Invitations).'),
                ]);
            }
        }

        foreach ($supplierIds as $supplierId) {
            MerchantWigpleasureCategorySupplier::firstOrCreate([
                'merchant_id' => $merchantId,
                'wigpleasure_category_id' => $categoryId,
                'supplier_user_id' => $supplierId,
            ]);
        }

        if ($request->boolean('apply_to_order') && Schema::hasTable('order_suppliers')) {
            DB::transaction(function () use ($order, $merchantId, $supplierIds) {
                OrderSuppliers::where('order_id', $order->id)->delete();
                foreach ($supplierIds as $supplierId) {
                    OrderSuppliers::create([
                        'order_id' => $order->id,
                        'customer' => $merchantId,
                        'supplier' => $supplierId,
                    ]);
                }
            });
        }

        $msg = $request->boolean('apply_to_order')
            ? __('Category routing saved and suppliers updated for this order.')
            : __('Category routing saved for future synced orders.');

        return redirect()->route('orders.out', [], 303)->with('success', $msg);
    }

    private function merchantAcceptsSupplier(int $merchantId, int $supplierUserId): bool
    {
        return User::find($merchantId)
            ?->suppliersAsMerchant()
            ->wherePivot('status', 'accepted')
            ->where('users.id', $supplierUserId)
            ->exists() ?? false;
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function create()
    {
        $categories = Category::cachedAll();

        return view('pages.orders.create', compact('categories'))->with('title', 'Add new order')->with('breadcrumb', 'New order');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $request->validate([
            'quantity' => 'required|integer',
            'image' => 'nullable|mimes:jpg,png,webp|max:2048',
            'category' => 'required',
            'product' => 'required',
            'attributes' => 'required',
            'suppliers' => 'nullable|array',
            'suppliers.*' => 'integer|exists:users,id',
        ]);

        $order = new Order;
        $order->code = random_alphanumeric(10);
        $order->quantity = $request->input('quantity');
        // $order->user_id   = Auth::user()->id;
        $order->user_id = b2bListingsUserId();
        $order->product_id = $request->input('product');
        $order->attributes = serialize($request->input('attributes'));
        $order->ref_number = $request->input('ref_number');
        $order->notes = $request->input('notes');

        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $input['image'] = date('mdYHis').uniqid().'.'.$image->getClientOriginalExtension();

            Storage::disk('public')->makeDirectory('uploads/orders/thumbnails');
            Storage::disk('public')->makeDirectory('uploads/orders/original');

            $thumbPath = Storage::disk('public')->path('uploads/orders/thumbnails/'.$input['image']);

            $imgFile = Image::make($image->getRealPath());
            $imgFile->resize(300, 300, function ($constraint) {
                $constraint->aspectRatio();
            })->save($thumbPath);

            $image->move(Storage::disk('public')->path('uploads/orders/original'), $input['image']);

            $order->image = $input['image'];
        }

        $order->save();

        event(new DashboardStatsUpdated);

        if ($request->input('suppliers')) {
            $this->applyMailConfigFromSettings();
            foreach ($request->input('suppliers') as $supplier) {
                $orderSupplier = new OrderSuppliers;
                $orderSupplier->order_id = $order->id;
                $orderSupplier->customer = Auth::user()->id;
                $orderSupplier->supplier = $supplier;

                $user = User::find($supplier);

                $data = [
                    'greeting' => 'Hi '.$user->name.', you received a new order!',
                    'body' => 'A customer made and order and chose you to supply this order',
                    'thanks' => 'Thank you',
                    'actionText' => 'Order Details',
                    'actionURL' => route('orders.show', $order->code),
                    'customer_id' => Auth::user()->id,
                    'order_id' => $order->id,
                ];

                Notification::send($user, new OrderCreated($data));

                $orderSupplier->save();
            }
        }

        Session::flash('success', 'Order was successfully saved.');

        return redirect()->route('orders.edit', [$order->code], 303);
    }

    /**
     * Display the specified resource.
     *
     * @param  string  $code
     * @return Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function show($code)
    {
        $order = Order::where('code', $code)->with(['deliveries', 'quotations', 'product'])->first();

        if (! $order || empty($code)) {
            abort(404);
        }

        $OrderSupplierCheck = false;

        if (OrderSupplierCheck($order->id) == true) {
            $OrderSupplierCheck = true;
        }

        $OrderSupplier = OrderSuppliers::where('order_id', $order->id)->where('supplier', Auth::user()->id)->first();

        if ($OrderSupplier) {
            $OrderSupplier->seen = 1;
            $OrderSupplier->save();
        }

        $orderQuotationCheck = false;
        $orderQuotation = OrderQuotations::where('supplier_user_id', Auth::user()->id)->where('order_id', $order->id)->first();

        if ($orderQuotation) {
            $orderQuotationCheck = true;
        }

        return view('pages.orders.show')->with('title', 'Order Details')
            ->with('breadcrumb', 'Order Details')
            ->with('order', $order)
            ->with('orderQuotation', $orderQuotation)
            ->with('OrderSupplierCheck', $OrderSupplierCheck)
            ->with('orderQuotationCheck', $orderQuotationCheck);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  string  $code  Order code
     * @return Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function edit($code)
    {
        $categories = Category::cachedAll();
        $products = Product::all();
        $order = Order::where('code', $code)->with('product')->first();

        if (! $order) {
            abort(404);
        }

        return view('pages.orders.create', compact('categories', 'products', 'order'))->with('title', 'Edit order')->with('breadcrumb', 'Edit order');
    }

    /**
     * Update the specified resource in storage.
     *
     * @return Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function update(Request $request, Order $order)
    {
        $request->validate([
            'quantity' => 'required|integer|min:1',
            'image' => 'nullable|mimes:jpg,png,webp|max:2048',
            'category' => 'required',
            'product' => 'required',
            'attributes' => 'required',
            'suppliers' => 'nullable|array',
            'suppliers.*' => 'integer|exists:users,id',
        ]);

        $order->quantity = $request->input('quantity');
        $order->product_id = $request->input('product');
        $order->attributes = serialize($request->input('attributes'));
        $order->ref_number = $request->input('ref_number');
        $order->notes = $request->input('notes');

        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $input['image'] = date('mdYHis').uniqid().'.'.$image->getClientOriginalExtension();

            Storage::disk('public')->makeDirectory('uploads/orders/thumbnails');
            Storage::disk('public')->makeDirectory('uploads/orders/original');

            $thumbPath = Storage::disk('public')->path('uploads/orders/thumbnails/'.$input['image']);
            $imgFile = Image::make($image->getRealPath());
            $imgFile->resize(300, 300, fn ($c) => $c->aspectRatio())->save($thumbPath);
            $image->move(Storage::disk('public')->path('uploads/orders/original'), $input['image']);
            $order->image = $input['image'];
        }

        $order->save();

        OrderSuppliers::where('order_id', $order->id)->delete();
        if ($request->filled('suppliers')) {
            $this->applyMailConfigFromSettings();
            foreach ($request->input('suppliers') as $supplierId) {
                OrderSuppliers::create([
                    'order_id' => $order->id,
                    'customer' => Auth::id(),
                    'supplier' => $supplierId,
                ]);
                $user = User::find($supplierId);
                if ($user) {
                    Notification::send($user, new OrderCreated([
                        'greeting' => 'Hi '.$user->name.', an order was updated!',
                        'body' => 'A customer updated an order and chose you to supply it.',
                        'thanks' => 'Thank you',
                        'actionText' => 'Order Details',
                        'actionURL' => route('orders.show', $order->code),
                        'customer_id' => Auth::id(),
                        'order_id' => $order->id,
                    ]));
                }
            }
        }

        Session::flash('success', __('Order updated successfully.'));

        return redirect()->route('orders.show', $order->code, 303);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $code
     * @return Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function destroy($code)
    {
        $order = Order::where('code', $code)->with('quotations')->first();

        if (! $order || empty($code)) {
            abort(404);
        }

        if ($order->image) {
            $image_path = public_path('images/orders/uploads/').$order->image;
            $thumb_path = public_path('images/orders/thumbnails/').$order->image;

            if (File::exists($image_path)) {
                File::delete($image_path);
            }

            if (File::exists($thumb_path)) {
                File::delete($thumb_path);
            }
        }

        $order->delete();

        Session::flash('success', 'Order was successfully deleted.');

        return redirect()->back(303);
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'string|max:64',
        ]);
        $user = Auth::user();
        $isAdmin = ((int) $user->id === 1)
            || (($user->email ?? '') === 'admin@admin.com')
            || $user->hasRole('Admin');
        $customerId = b2bListingsUserId();

        $count = 0;
        foreach ($validated['ids'] as $code) {
            $order = Order::where('code', $code)->with('suppliers')->first();
            if (! $order) {
                continue;
            }
            if (! $isAdmin) {
                $canDelete = $order->suppliers->contains(function ($row) use ($customerId) {
                    return (int) $row->customer === (int) $customerId;
                });
                if (! $canDelete) {
                    continue;
                }
            }
            if ($order->image) {
                $image_path = public_path('images/orders/uploads/').$order->image;
                $thumb_path = public_path('images/orders/thumbnails/').$order->image;
                if (File::exists($image_path)) {
                    File::delete($image_path);
                }
                if (File::exists($thumb_path)) {
                    File::delete($thumb_path);
                }
            }
            $order->delete();
            $count++;
        }
        Session::flash('success', __(':count order(s) deleted.', ['count' => $count]));

        return redirect()->back(303);
    }

    /**
     * Display the specified resource.
     *
     * @param  string  $code
     * @return Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function quotations($code)
    {
        $order = Order::where('code', $code)->with('quotations')->first();

        if (! $order || empty($code)) {
            abort(404);
        }

        return view('pages.orders.quotations')->with('title', 'Order Quotations')->with('breadcrumb', 'Order Quotations')->with('order', $order);
    }
}
