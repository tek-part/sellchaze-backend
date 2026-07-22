<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\OrderSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SellchaseSyncOrderCommand extends Command
{
    protected $signature = 'sellchase:sync-order
                            {--api-key= : API key for authentication}';

    protected $description = 'Sync order from wigpleasure (local mode, bypasses HTTP)';

    public function handle(OrderSyncService $syncService): int
    {
        $stdin = stream_get_contents(STDIN);
        if (empty(trim($stdin))) {
            $this->error(json_encode(['success' => false, 'message' => 'JSON payload required via stdin']));

            return 1;
        }

        $payload = json_decode($stdin, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error(json_encode(['success' => false, 'message' => 'Invalid JSON: '.json_last_error_msg()]));

            return 1;
        }

        $apiKey = $payload['x_api_key'] ?? $this->option('api-key');
        $trustLocal = (bool) ($payload['x_trust_local'] ?? false);
        $syncUpdate = (bool) ($payload['x_sync_update'] ?? false);
        unset($payload['x_api_key'], $payload['x_trust_local'], $payload['x_sync_update']);

        $apiUser = null;
        if ($trustLocal) {
            // Same-server payload from Wigpleasure Process; merchant resolved in OrderSyncService.
        } elseif (! empty($apiKey)) {
            $apiUser = $this->resolveApiUser($apiKey);
            if ($apiUser === false) {
                $this->error(json_encode(['success' => false, 'message' => 'Invalid API key']));

                return 1;
            }
        }
        // No key: single-store default merchant in OrderSyncService.

        $validator = Validator::make($payload, [
            'wigpleasure_order_id' => 'required|integer',
            'customer' => 'required|array',
            'customer.name' => 'required|string|max:255',
            'customer.email' => 'required|email',
            'customer.phone' => 'nullable|string|max:50',
            'shipping_address' => 'nullable|array',
            'payment' => 'nullable|array',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|integer',
            'products.*.qty' => 'required|integer|min:1',
            'products.*.wigpleasure_category_id' => 'nullable|integer|min:1',
            'products.*.category_id' => 'nullable|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            $this->error(json_encode(['success' => false, 'errors' => $validator->errors()->toArray()]));

            return 1;
        }

        try {
            $result = $syncUpdate
                ? $syncService->updateSyncedOrder($payload, (int) ($payload['wigpleasure_order_id'] ?? 0))
                : $syncService->sync($payload, $apiUser);
            $this->line(json_encode($result));

            return 0;
        } catch (ValidationException $e) {
            $this->error(json_encode([
                'success' => false,
                'errors' => $e->errors(),
            ]));

            return 1;
        } catch (\Throwable $e) {
            $this->error(json_encode([
                'success' => false,
                'message' => 'Failed to create orders: '.$e->getMessage(),
            ]));

            return 1;
        }
    }

    /**
     * Resolve merchant user from key. Returns User or false (invalid).
     * Global keys map to a merchant when allowed (same rules as HTTP API), without mutating users.api_key.
     */
    private function resolveApiUser(string $headerKey): User|bool
    {
        try {
            if (Schema::hasColumn('users', 'api_key')) {
                $user = User::where('api_key', $headerKey)->first();
                if ($user) {
                    return $user->hasRole('Merchant') ? $user : false;
                }
            }
        } catch (\Throwable $e) {
            // Column may not exist or schema cache issue - fall through to global key
        }

        $globalKeys = array_filter(array_map('trim', [
            config('services.api_key'),
            config('services.sellchase_api_key'),
        ]));
        $allowGlobal = filter_var(config('services.sellchase_allow_global_api_key'), FILTER_VALIDATE_BOOLEAN);
        if (! $allowGlobal || empty($globalKeys)) {
            return false;
        }
        foreach ($globalKeys as $gk) {
            if ($gk !== '' && hash_equals($gk, $headerKey)) {
                $configuredId = config('services.sellchase_merchant_user_id');
                if ($configuredId !== null && $configuredId !== '') {
                    $merchant = User::find((int) $configuredId);
                    if ($merchant && $merchant->hasRole('Merchant')) {
                        return $merchant;
                    }
                }
                $merchant = User::whereHas('roles', fn ($q) => $q->where('name', 'Merchant'))
                    ->orderBy('id')
                    ->first();

                return ($merchant instanceof User) ? $merchant : false;
            }
        }

        return false;
    }
}
