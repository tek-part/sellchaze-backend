<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Session, Image, Auth, Validator, Redirect, DB;
use App\Models\User;
use App\Models\Profile;
use App\Models\OrderQuotations;
use App\Models\Order;
use App\Models\Transaction;
class BalanceController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:balance-in', ['only' => ['in']]);
        $this->middleware('permission:balance-out', ['only' => ['out', 'transaction']]);
        $this->middleware('permission:balance-in|balance-out', ['only' => ['details', 'transactions']]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function in()
    {
        $supplier_user_id = b2bListingsUserId();
        $balances = OrderQuotations::select(DB::raw('SUM(price) as total_balance'), 'supplier_user_id', 'customer_user_id')
            ->where('supplier_user_id', $supplier_user_id)
            ->where('status', 'accepted')
            ->groupBy('customer_user_id', 'supplier_user_id')
            ->get();

        $userIds = $balances->pluck('customer_user_id')->merge($balances->pluck('supplier_user_id'))->unique()->filter()->values()->all();
        $users = User::with('profile')->whereIn('id', $userIds)->get()->keyBy('id');

        return view('pages.balance.in', compact('balances', 'users'))
            ->with('title', 'All balance in')
            ->with('breadcrumb', 'Balance In');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function out()
    {
        $customer_user_id = b2bListingsUserId();
        $balances = OrderQuotations::select(DB::raw('SUM(price) as total_balance'), 'supplier_user_id', 'customer_user_id')
            ->where('customer_user_id', $customer_user_id)
            ->where('status', 'accepted')
            ->groupBy('supplier_user_id', 'customer_user_id')
            ->get();

        $userIds = $balances->pluck('customer_user_id')->merge($balances->pluck('supplier_user_id'))->unique()->filter()->values()->all();
        $users = User::with('profile')->whereIn('id', $userIds)->get()->keyBy('id');

        return view('pages.balance.out', compact('balances', 'users'))
            ->with('title', 'All balance out')
            ->with('breadcrumb', 'Balance Out');
    }

    /**
     * Show customer balance details.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function details(Request $request)
    {
        if ($request->isMethod('get')) {
            return redirect()->route('dashboard');
        }

        $rules      = array('supplier' => 'required', 'customer' => 'required');
        $validator  = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator);
        }
        
        $supplier           = decrypt($request->supplier);
        $supplier_username  = Profile::where('user_id',$supplier)->first()->username;
        $customer           = decrypt($request->customer);
        $balances           = OrderQuotations::where('supplier_user_id', $supplier)->where('customer_user_id', $customer)->where('status', 'accepted')->get();
        $customer_check     = OrderQuotations::where('supplier_user_id', $supplier)->where('customer_user_id', Auth::user()->id)->where('status', 'accepted')->get();
        

        if(count($customer_check) > 0) {
            $customer_check = true;
        }
        else {
            $customer_check = false;
        }

        $sum_total_balances = 0;

        if(count($balances) > 0) {
            foreach($balances as $balance) {
                $sum_total_balances += $balance->price;
            }
        }

        $order_ids 	        = [];
        $total_transactions = 0;
        $transactions       = Transaction::where('supplier_user_id', $supplier)->where('customer_user_id', Auth::user()->id)->orWhere('supplier_user_id', Auth::user()->id)->get();
        
        if(count($transactions) > 0) {
            foreach($transactions as $transaction) {
                $orders             = unserialize($transaction->orders);
                $total_transactions += $transaction->amount;

                if(!empty($orders)) {
                    foreach($orders as $order) {
                        $order_ids[] = $order['order_id'];
                    }
                }
            }
        }
        
        return view('pages.balance.details', compact('balances', 'sum_total_balances', 'supplier_username', 'order_ids', 'total_transactions', 'customer_check', 'supplier', 'customer'))->with('title' , 'All balance out')->with('breadcrumb' , 'Balance Out');
    }

    /**
     * Retrieve all transactions between supplier and customer.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function transactions(Request $request)
    {
        $rules      = array('supplier' => 'required','customer' => 'required');
        $validator  = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator);
        }

        $supplier           = decrypt($request->supplier);
        $supplier_user      = User::find($supplier);
        $supplier_profile   = Profile::where('user_id', $supplier_user->id)->first();
        $customer           = decrypt($request->customer);
        $customer_user      = User::find($customer);
        $customer_profile   = Profile::where('user_id', $customer_user->id)->first();
        $transactions       = Transaction::where('supplier_user_id', $supplier)->where('customer_user_id', $customer)->get();

        return view('pages.balance.transactions', compact('transactions', 'supplier_user', 'supplier_profile', 'customer_user', 'customer_profile'))->with('title' , 'All Transactions')->with('breadcrumb' , 'Transactions');
        
    }

    /**
     * Store a new transaction.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $username
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function transaction(Request $request, $username)
    {
        $rules = array(
            'orders'            => 'required',
            'transfer_method'   => 'required',
            'photo'             => 'nullable|image|mimes:jpg,png,webp|max:2048'
        );

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json(["code" => 401, "status" => "error", "message" => $validator->errors()->all()]);
        }

        $orders = [];

        if($request->orders) {
            foreach($request->orders as $code => $amount) {
                $order = Order::where('code', $code)->first();

                if(!$order) {
                    return response()->json(["code" => 401, "status" => "error", "message" => "Sorry it seems that order '".$code."' no longer exists."]);
                }

                $orders[] = array('order_id' => $order->id, 'amount' => $amount);
            }
        }
        
        if(empty($username)) {
            return response()->json(["code" => 401, "status" => "error", "message" => "Sorry you have to pass username."]);
        }
        $username = decrypt($username);
        $supplier = Profile::where('username', $username)->first()->user_id;

        if(!$supplier) {
            return response()->json(["code" => 401, "status" => "error", "message" => "Sorry but it seems the supplier account not longer exists please inform system administrator."]);
        }

        $transaction                    = new Transaction;
        $transaction->supplier_user_id  = $supplier;
        $transaction->customer_user_id  = Auth::user()->id;
        $transaction->orders            = serialize($orders);
        $transaction->amount            = $request->amount;
        $transaction->transfer_method   = $request->transfer_method;
        $transaction->notes             = $request->notes;

        if ($request->hasFile('image')) {
            $image              = $request->file('image');
            $input['image']     = date('mdYHis').uniqid().'.'.$image->getClientOriginalExtension();
            $destinationPath    = public_path('/storage/uploads/transactions');

            $image->move($destinationPath, $input['image']);

            $transaction->image = $input['image'];
        }

        $transaction->save();

        return response()->json(["code" => 200, "status" => "success", "message" => "Your transaction was successfully created."]);
    }
}