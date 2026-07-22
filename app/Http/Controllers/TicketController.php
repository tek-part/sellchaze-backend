<?php

namespace App\Http\Controllers;

use App\Models\OrderTicket;
use App\Models\Order;
use App\Models\TicketMessage;
use App\Models\TicketAction;
use App\Mail\TicketCreatedMail;
use App\Mail\TicketReplyMail;
use App\Mail\TicketStatusChangedMail;
use App\Http\Controllers\Concerns\AppliesMailConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Validator;

class TicketController extends Controller
{
    use AppliesMailConfig;

    public function __construct()
    {
        $this->middleware('permission:tickets-list', ['only' => ['index', 'show']]);
        $this->middleware('permission:tickets-create', ['only' => ['create', 'store']]);
        $this->middleware('permission:tickets-manage', ['only' => ['reply', 'updateStatus', 'assign', 'addAction']]);
    }

    public function index(Request $request)
    {
        $query = OrderTicket::with('order', 'requester', 'assignee');
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        $tickets = $query->orderBy('created_at', 'desc')->limit(2000)->get();
        return view('pages.tickets.index', compact('tickets'))
            ->with('title', 'Tickets')
            ->with('breadcrumb', 'Tickets');
    }

    public function create($code)
    {
        $order = Order::where('code', $code)->firstOrFail();
        return view('pages.tickets.create', compact('order'))
            ->with('title', 'Create Ticket')
            ->with('breadcrumb', 'Create Ticket');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:orders,id',
            'type' => 'required|in:replacement,return,other',
            'notes' => 'required|string|max:2000',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }
        $order = Order::findOrFail($request->order_id);
        $ticket = OrderTicket::create([
            'order_id' => $order->id,
            'type' => $request->type,
            'status' => 'awaiting_supplier',
            'requested_by' => Auth::id(),
            'notes' => $request->notes,
        ]);
        $this->applyMailConfigFromSettings();
        foreach ($order->suppliers as $os) {
            $supplier = \App\Models\User::find($os->supplier);
            if ($supplier && $supplier->email) {
                Mail::mailer('smtp')->to($supplier->email)->send(new TicketCreatedMail($ticket));
            }
        }
        return redirect()->route('tickets.show', $ticket->id)
            ->with('success', 'Ticket created. Supplier will be notified.');
    }

    public function show($id)
    {
        $ticket = OrderTicket::with('order', 'requester', 'assignee', 'messages.user', 'actions.performer')->findOrFail($id);
        return view('pages.tickets.show', compact('ticket'))
            ->with('title', 'Ticket #' . $ticket->id)
            ->with('breadcrumb', 'Ticket #' . $ticket->id);
    }

    public function reply(Request $request, $id)
    {
        $request->validate(['body' => 'required|string|max:2000']);
        $ticket = OrderTicket::with('order.suppliers.supplier', 'requester', 'assignee')->findOrFail($id);
        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => Auth::id(),
            'body' => $request->body,
        ]);
        $ticket->update(['status' => 'supplier_responded']);
        $this->applyMailConfigFromSettings();
        $replierId = Auth::id();
        $supplierIds = $ticket->order ? $ticket->order->suppliers->pluck('supplier')->filter()->unique() : collect();
        $isSupplierReply = $supplierIds->contains($replierId);
        $emails = collect();
        if ($isSupplierReply) {
            foreach ([$ticket->requester, $ticket->assignee] as $user) {
                if ($user && $user->email) {
                    $emails->push(['email' => $user->email, 'name' => $user->name]);
                }
            }
        } else {
            foreach (optional($ticket->order)->suppliers ?? [] as $os) {
                $supplier = $os->supplier ?? \App\Models\User::find($os->supplier);
                if ($supplier && $supplier->email) {
                    $emails->push(['email' => $supplier->email, 'name' => $supplier->name]);
                }
            }
        }
        $message->load('user');
        foreach ($emails->unique('email') as $r) {
            Mail::mailer('smtp')->to($r['email'])->queue(new TicketReplyMail($ticket->fresh(), $message, $r['name'] ?? $r['email']));
        }
        return redirect()->route('tickets.show', $ticket->id)->with('success', 'Reply sent.');
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|in:open,awaiting_supplier,supplier_responded,in_progress,resolved,closed']);
        $ticket = OrderTicket::with('order.suppliers.supplier', 'requester', 'assignee')->findOrFail($id);
        $oldStatus = $ticket->status;
        $ticket->update(['status' => $request->status]);
        $this->applyMailConfigFromSettings();
        $emails = collect();
        foreach ([$ticket->requester, $ticket->assignee] as $user) {
            if ($user && $user->email) {
                $emails->push(['email' => $user->email, 'name' => $user->name]);
            }
        }
        if ($ticket->order) {
            foreach ($ticket->order->suppliers as $os) {
                $supplier = $os->supplier ?? \App\Models\User::find($os->supplier);
                if ($supplier && $supplier->email) {
                    $emails->push(['email' => $supplier->email, 'name' => $supplier->name]);
                }
            }
        }
        foreach ($emails->unique('email') as $r) {
            Mail::mailer('smtp')->to($r['email'])->queue(new TicketStatusChangedMail($ticket->fresh(), $oldStatus, $r['name'] ?? $r['email']));
        }
        return redirect()->back()->with('success', 'Status updated.');
    }

    public function assign(Request $request, $id)
    {
        $request->validate(['assigned_to' => 'nullable|exists:users,id']);
        $ticket = OrderTicket::findOrFail($id);
        $ticket->update(['assigned_to' => $request->assigned_to]);
        return redirect()->back()->with('success', 'Assignee updated.');
    }

    public function addAction(Request $request, $id)
    {
        $request->validate([
            'action' => 'required|in:return_slip,manual_return,warehouse_adjustment,refund,other',
            'notes' => 'nullable|string|max:2000',
        ]);
        $ticket = OrderTicket::findOrFail($id);
        TicketAction::create([
            'ticket_id' => $ticket->id,
            'action' => $request->action,
            'performed_by' => Auth::id(),
            'notes' => $request->notes,
        ]);
        $ticket->update(['status' => 'in_progress']);
        return redirect()->back()->with('success', __('Action recorded.'));
    }
}
