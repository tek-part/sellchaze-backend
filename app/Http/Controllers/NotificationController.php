<?php

namespace App\Http\Controllers;

use Auth;
use App\Models\User;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    function __construct()
    {
        $this->middleware('permission:notifications-orders', ['only' => ['orders']]);
        $this->middleware('permission:notifications-quotations', ['only' => ['quotations']]);
    }

    public function orders()
    {
        $notifications = User::find(Auth::user()->id)->notifications->where('type', 'App\Notifications\OrderCreated')->all();

        return view('pages.notifications.orders',compact('notifications'))->with('title' , 'Orders Notifications')->with('breadcrumb' , 'Orders Notifications');
    }

    public function quotations()
    {
        $notifications = User::find(Auth::user()->id)->notifications->where('type', 'App\Notifications\QuotationCreated')->all();

        return view('pages.notifications.quotations',compact('notifications'))->with('title' , 'Quotations Notifications')->with('breadcrumb' , 'Quotations Notifications');
    }

    public function markNotification(Request $request)
    {
        auth()->user()->unreadNotifications->when($request->input('id'), function ($query) use ($request) {
                                                return $query->where('id', $request->input('id'));
                                            })->markAsRead();
    
        return response()->noContent();
    }
}