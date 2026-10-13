<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuthSession;
use App\Models\MerchantPushSubscription;
use App\Models\Store;
use App\Services\Commerce\MerchantPushSettings;
use App\Services\JwtTokenService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MerchantOrderPushController extends Controller
{
    public function __construct(private readonly MerchantPushSettings $settings) {}

    private function context(Request $request): array
    {
        $store = $request->attributes->get('store');
        $user = $request->user();
        abort_unless($store instanceof Store, 404);
        abort_unless($user && (int) $store->owner_user_id === (int) $user->id, 403);
        $sid = JwtTokenService::fromConfig()->accessSessionId($request->bearerToken() ?? '');
        $session = AuthSession::whereKey($sid)->where('user_id', $user->id)->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        abort_unless($session, 422, 'Sign in again to configure this device.');

        return [$store, $user, $session];
    }

    public function index(Request $request): JsonResponse
    {
        [$store,$user,$session] = $this->context($request);
        $data = $request->validate(['endpoint_hash' => ['sometimes', 'string', 'regex:/^[a-f0-9]{64}$/'], 'subscription_id' => ['sometimes', 'uuid']]);
        $query = MerchantPushSubscription::where('store_id', $store->id)->where('user_id', $user->id);
        $subscription = isset($data['endpoint_hash']) ? $query->where('endpoint_hash', $data['endpoint_hash'])->first() : (isset($data['subscription_id']) ? $query->whereKey($data['subscription_id'])->first() : null);
        $ready = $this->settings->ready();

        return response()->json(['data' => ['configured' => $ready, 'public_key' => $ready ? (string) config('merchant_push.public_key') : null,
            'subscription' => $subscription ? ['id' => $subscription->id, 'version' => $subscription->version, 'enabled' => $subscription->enabled && $subscription->auth_session_id === $session->id && $subscription->vapid_fingerprint === $this->settings->fingerprint()] : null]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function update(Request $request): JsonResponse
    {
        [$store,$user,$session] = $this->context($request);
        if ($request->boolean('enabled') === false && $request->has('subscription_id')) {
            $data = $request->validate(['enabled' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1'], 'subscription_id' => ['required', 'uuid']]);
            DB::transaction(function () use ($store, $user, $data) {
                $subscription = MerchantPushSubscription::whereKey($data['subscription_id'])->where('store_id', $store->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                abort_unless($subscription->version === (int) $data['version'], 409, 'Device settings changed. Reload before saving.');
                $subscription->update(['enabled' => false, 'version' => $subscription->version + 1]);
            });

            return $this->index($request);
        }
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:0'],
            'endpoint' => ['required', 'string', 'max:4096', function ($attribute, $value, $fail) {
                if (! is_string($value) || ! $this->settings->endpointAllowed($value)) {
                    $fail('Use a supported browser push service endpoint.');
                }
            }],
            'keys' => ['required_if:enabled,true', 'array:p256dh,auth'],
            'keys.p256dh' => ['required_if:enabled,true', function ($attribute, $value, $fail) {
                if (! $this->settings->validKey($value, 65)) {
                    $fail('Invalid browser public key.');
                }
            }],
            'keys.auth' => ['required_if:enabled,true', function ($attribute, $value, $fail) {
                if (! $this->settings->validKey($value, 16)) {
                    $fail('Invalid browser authentication key.');
                }
            }]]);
        abort_if($data['enabled'] && ! $this->settings->ready(), 503, 'Order notifications are not configured.');
        $hash = hash('sha256', $data['endpoint']);
        DB::transaction(function () use ($store, $user, $session, $data, $hash) {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $subscription = MerchantPushSubscription::where('endpoint_hash', $hash)->lockForUpdate()->first();
            if ($subscription && ((int) $subscription->user_id !== (int) $user->id || (int) $subscription->store_id !== (int) $store->id)) {
                throw new HttpResponseException(response()->json(['message' => 'This browser subscription must be renewed.', 'code' => 'push_subscription_renewal_required'], 409));
            }
            abort_unless((int) ($subscription?->version ?? 0) === (int) $data['version'], 409, 'Device settings changed. Reload before saving.');
            if (! $subscription && ! $data['enabled']) {
                return;
            }
            $values = ['store_id' => $store->id, 'user_id' => $user->id, 'auth_session_id' => $session->id, 'endpoint_hash' => $hash,
                'enabled' => (bool) $data['enabled'], 'version' => (int) $data['version'] + 1, 'vapid_fingerprint' => $this->settings->fingerprint()];
            if ($data['enabled']) {
                $values['subscription'] = ['endpoint' => $data['endpoint'], 'keys' => $data['keys'], 'contentEncoding' => 'aes128gcm'];
            }
            if ($subscription) {
                $subscription->update($values);
            } else {
                MerchantPushSubscription::create($values);
            }
        });
        $request->merge(['endpoint_hash' => $hash]);

        return $this->index($request);
    }
}
