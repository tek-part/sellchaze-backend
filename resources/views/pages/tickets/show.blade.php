<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('Ticket') }} #{{ $ticket->id }}</h4>
                            <p class="mb-0 text-muted small">{{ __('Order') }} {{ optional($ticket->order)->code }}</p>
                        </div>
                        <div class="col-auto">
                            <a href="{{ route('tickets.index') }}" class="btn btn-light btn-sm">
                                <i class="las la-arrow-left me-1"></i> {{ __('Back to Tickets') }}
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    @if ($message = Session::get('success'))
                        <div class="alert alert-success alert-dismissible fade show mb-4">{{ $message }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    @endif
                    <div class="row mb-5">
                        <div class="col-md-6">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label fw-semibold text-muted mb-0">{{ __('Type') }}</label>
                                    <div>{{ ucfirst($ticket->type) }}</div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold text-muted mb-0">{{ __('Status') }}</label>
                                    <div>{{ ucfirst(str_replace('_', ' ', $ticket->status)) }}</div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold text-muted mb-0">{{ __('Requested by') }}</label>
                                    <div>{{ optional($ticket->requester)->name ?? '—' }}</div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold text-muted mb-0">{{ __('Assigned to') }}</label>
                                    <div>{{ optional($ticket->assignee)->name ?? '—' }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <form action="{{ route('tickets.update-status', $ticket->id) }}" method="POST">
                                @csrf
                                <label class="form-label fw-semibold">{{ __('Update Status') }}</label>
                                <div class="d-flex gap-2">
                                    <select name="status" class="form-select" style="width:auto">
                                        @foreach (['open','awaiting_supplier','supplier_responded','in_progress','resolved','closed'] as $s)
                                            <option value="{{ $s }}" {{ $ticket->status === $s ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn btn-primary btn-sm"><i class="las la-check me-1"></i> {{ __('Update') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">{{ __('Notes') }}</label>
                        <p class="mb-0 text-body">{{ $ticket->notes }}</p>
                    </div>
                    @can('tickets-manage')
                    <div class="border-top pt-4 mt-4">
                        <h6 class="fw-semibold mb-3">{{ __('Record Action') }}</h6>
                        <form action="{{ route('tickets.add-action', $ticket->id) }}" method="POST" class="mb-4">
                            @csrf
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <select name="action" class="form-select" required>
                                        <option value="return_slip">{{ __('Return slip / waybill') }}</option>
                                        <option value="manual_return">{{ __('Manual return') }}</option>
                                        <option value="warehouse_adjustment">{{ __('Warehouse adjustment') }}</option>
                                        <option value="refund">{{ __('Refund') }}</option>
                                        <option value="other">{{ __('Other') }}</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <input type="text" name="notes" class="form-control" placeholder="{{ __('Notes') }}">
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-primary w-100"><i class="las la-plus me-1"></i> {{ __('Add') }}</button>
                                </div>
                            </div>
                        </form>
                        @if($ticket->actions->isNotEmpty())
                        <div class="mb-4">
                            <h6 class="fw-semibold mb-2">{{ __('Actions') }}</h6>
                            @foreach($ticket->actions as $act)
                            <div class="d-flex mb-2 p-2 bg-light rounded">
                                <span class="badge bg-primary me-2">{{ ucfirst(str_replace('_', ' ', $act->action)) }}</span>
                                <span class="text-muted small">{{ optional($act->performer)->name ?? '—' }} · {{ $act->created_at->format('d M Y H:i') }}</span>
                                @if($act->notes)<span class="ms-2">— {{ $act->notes }}</span>@endif
                            </div>
                            @endforeach
                        </div>
                        @endif
                    </div>
                    @endcan
                    <div class="border-top pt-4 mt-4">
                        <h6 class="fw-semibold mb-3">{{ __('Messages') }}</h6>
                        @foreach ($ticket->messages as $msg)
                            <div class="d-flex mb-3 p-3 bg-light rounded">
                                <div class="me-3">
                                    <img src="{{ user_photo($msg->user_id) }}" alt="" class="rounded-circle" style="width:40px;height:40px;object-fit:cover" onerror="this.src='{{ placeholder_image('avatar') }}'">
                                </div>
                                <div>
                                    <strong>{{ optional($msg->user)->name }}</strong>
                                    <span class="text-muted small ms-2">{{ $msg->created_at->format('Y-m-d H:i') }}</span>
                                    <p class="mb-0 mt-1">{{ $msg->body }}</p>
                                </div>
                            </div>
                        @endforeach
                        <form action="{{ route('tickets.reply', $ticket->id) }}" method="POST" class="mt-4">
                            @csrf
                            <label class="form-label fw-semibold">{{ __('Reply') }}</label>
                            <textarea name="body" class="form-control mb-2" rows="3" placeholder="{{ __('Reply...') }}" required></textarea>
                            <button type="submit" class="btn btn-primary"><i class="las la-paper-plane me-1"></i> {{ __('Send Reply') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-default-layout>
