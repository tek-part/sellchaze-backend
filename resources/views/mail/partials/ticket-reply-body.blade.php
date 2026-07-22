<h2 style="margin:0 0 12px;font-size:18px;color:#0f172a;">{{ __('Ticket #:id - New Reply', ['id' => $ticket_id]) }}</h2>
<p style="margin:0 0 8px;">{{ __('Order') }}: {{ $order_code }}</p>
<p style="margin:0 0 8px;"><strong>{{ __('Type') }}:</strong> {{ $ticket_type }}</p>
<p style="margin:0 0 8px;"><strong>{{ __('New message') }}:</strong></p>
<div style="padding:16px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;">{!! nl2br(e($message_body)) !!}</div>
<p style="margin:12px 0 0;font-size:13px;color:#64748b;">{{ __('From') }}: {{ $sender_name }} — {{ $message_created_at }}</p>
<p style="margin:16px 0 0;">{{ __('Please review and reply if needed.') }}</p>
