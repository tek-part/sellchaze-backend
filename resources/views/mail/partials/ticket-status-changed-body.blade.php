<h2 style="margin:0 0 12px;font-size:18px;color:#0f172a;">{{ __('Ticket #:id - Status Updated', ['id' => $ticket_id]) }}</h2>
<p style="margin:0 0 8px;">{{ __('Order') }}: {{ $order_code }}</p>
<p style="margin:0 0 8px;"><strong>{{ __('Type') }}:</strong> {{ $ticket_type }}</p>
<p style="margin:0 0 12px;"><strong>{{ __('Status changed from') }}:</strong> {{ $old_status }} → {{ $new_status }}</p>
<p style="margin:0;">{{ __('Please review the ticket for any required action.') }}</p>
