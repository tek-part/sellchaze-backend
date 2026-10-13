<?php

namespace App\Notifications;

use App\Models\StoreOrderExport;
use Illuminate\Notifications\Notification;

class StoreOrderExportReady extends Notification
{
    public function __construct(private readonly StoreOrderExport $export) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $base = (int) $notifiable->id === (int) $this->export->store_owner_id || (int) $notifiable->parent_user_id === (int) $this->export->store_owner_id
            ? '/store/orders' : '/stores/'.$this->export->store_id.'/orders';

        return ['type' => 'store_order_export', 'export_id' => $this->export->id, 'store_id' => $this->export->store_id,
            'row_count' => $this->export->row_count, 'url' => $base.'?export='.$this->export->id];
    }
}
