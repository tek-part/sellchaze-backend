<h2 style="margin:0 0 12px;font-size:18px;color:#0f172a;">{{ __('Ticket #:id - Order :code', ['id' => $ticket_id, 'code' => $order_code]) }}</h2>
<p style="margin:0 0 8px;"><strong>{{ __('Type') }}:</strong> {{ $ticket_type }}</p>
<p style="margin:0 0 8px;"><strong>{{ __('Notes') }}:</strong></p>
<div style="padding:16px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;">{!! nl2br(e($ticket_notes)) !!}</div>
<p style="margin:16px 0 0;">{{ __('Please review and take the required action.') }}</p>
