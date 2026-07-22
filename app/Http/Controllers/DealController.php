<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Session, Image, Validator, Redirect;
use App\Models\OrderQuotations;

class DealController extends Controller
{
    function __construct()
    {
        $this->middleware('permission:deals-out', ['only' => ['out']]);
        $this->middleware('permission:deals-in', ['only' => ['in']]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function in(Request $request)
    {
        $supplier_user_id = b2bListingsUserId();
        $quotations = OrderQuotations::with(['order', 'customer'])
            ->where('supplier_user_id', $supplier_user_id)
            ->where('status', 'accepted')
            ->orderByDesc('id')
            ->limit(3000)
            ->get();

        return view('pages.deals.in', compact('quotations'))->with('title', 'My deals in')->with('breadcrumb', 'My deals in');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function out(Request $request)
    {
        $user = Auth::user();
        $isAdmin = ((int) $user->id === 1) || (($user->email ?? '') === 'admin@admin.com') || $user->hasRole('Admin');
        $query = OrderQuotations::with(['order', 'supplierUser'])
            ->where('status', 'accepted')
            ->orderByDesc('id')
            ->limit(3000);
        if (!$isAdmin) {
            $query->where('customer_user_id', b2bListingsUserId());
        }
        $quotations = $query->get();

        return view('pages.deals.out', compact('quotations'))->with('title', 'My deals out')->with('breadcrumb', 'My deals out');
    }
}