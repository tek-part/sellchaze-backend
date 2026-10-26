<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StorePaymentTransaction;
use App\Services\Commerce\GiftCards;
use App\Services\Commerce\StoreOrderService;
use App\Services\Notifications\StoreNotificationSettings;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Console\Command;
use Throwable;

/**
 * A gift card is redeemed when the order is placed; when the rest goes to an online gateway that the
 * shopper never completes, the card value would stay locked in a pending order. After a safe window
 * such orders are cancelled through the normal lifecycle (source `system`), which restores the cards
 * once. Cash on delivery, bank transfer and transfer-receipt orders are never touched.
 */
class ReleaseAbandonedGiftCardOrders extends Command
{
    protected $signature = 'gift-cards:release-abandoned {--limit=100}';

    protected $description = 'Cancel unpaid online-payment orders that hold gift card value past the configured window.';

    public function handle(StoreOrderService $orders, CurrentStore $current): int
    {
        $cutoff = now()->subMinutes((int) config('sellchase.gift_cards.abandoned_payment_minutes', 120));
        $rows = StoreOrder::query()->withoutGlobalScopes()->where('status', 'pending')->whereIn('payment_status', ['pending', 'failed'])
            ->where('gift_card_total', '>', 0)
            ->whereNotIn('payment_method', [...StoreNotificationSettings::OFFLINE_METHODS, GiftCards::METHOD])
            ->where('placed_at', '<', $cutoff)->orderBy('id')->limit(max(1, min(500, (int) $this->option('limit'))))->get();
        $released = 0;
        foreach ($rows as $order) {
            // A payment the provider already confirmed (or is confirming) is never abandoned.
            if (StorePaymentTransaction::query()->withoutGlobalScopes()->where('store_id', $order->store_id)->where('store_order_id', $order->id)
                ->whereIn('status', ['paid', 'processing'])->exists()) {
                continue;
            }
            $store = Store::query()->find($order->store_id);
            if ($store === null) {
                continue;
            }
            $previous = $current->get();
            try {
                $current->set($store);
                $orders->transition($order, 'cancelled', null, 'Online payment was not completed; gift card value released.', 'system');
                $released++;
            } catch (Throwable) {
                // A concurrent payment or status change wins; try again on the next run.
                $this->warn('Unable to release order '.$order->id.'.');
            } finally {
                $current->set($previous);
            }
        }
        $this->info("Released {$released} order(s).");

        return self::SUCCESS;
    }
}
