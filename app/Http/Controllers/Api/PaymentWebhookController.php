<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentWebhookEvent;
use App\Models\Store;
use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;
use App\Services\Commerce\StorePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function __construct(private readonly StorePaymentService $payments) {}

    public function fawaterak(Request $request, Store $store): JsonResponse
    {
        return $this->handle($request, $store, 'fawaterak');
    }

    public function handle(Request $request, Store $store, string $gateway): JsonResponse
    {
        abort_unless(in_array($gateway, ['stripe', 'paypal', 'tabby', 'tamara', 'paymob', 'tap', 'fawaterak', 'paytabs', 'fawry'], true), 404);
        $setting = StorePaymentGateway::query()->where('store_id', $store->id)->where('gateway', $gateway)->firstOrFail();
        $event = match ($gateway) {
            'stripe' => $this->verifiedStripeEvent($request, $setting),
            'paypal' => $this->verifiedPayPalEvent($request, $setting),
            'tabby' => $this->verifiedTabbyEvent($request, $setting),
            'tamara' => $this->verifiedTamaraEvent($request, $setting),
            'paymob' => $this->verifiedPaymobEvent($request, $setting),
            'tap' => $this->verifiedTapEvent($request, $setting),
            'fawaterak' => $this->verifiedFawaterakEvent($request, $setting),
            'paytabs' => $this->verifiedPayTabsEvent($request, $setting),
            'fawry' => $this->verifiedFawryEvent($request, $setting),
        };
        $eventId = (string) ($event['_event_id'] ?? data_get($event, 'id'));
        abort_if($eventId === '', 422, 'Missing event ID.');
        $receipt = PaymentWebhookEvent::query()->firstOrCreate(
            ['gateway' => $gateway, 'event_id' => $eventId],
            ['store_id' => $store->id, 'event_type' => data_get($event, 'type') ?? data_get($event, 'event_type')]
        );
        if (! $receipt->wasRecentlyCreated || $receipt->status === 'processed') {
            return response()->json(['received' => true, 'duplicate' => true]);
        }
        switch ($gateway) {
            case 'stripe': $this->processStripe($event); break;
            case 'paypal': $this->processPayPal($event, $setting); break;
            case 'tabby': $this->processTabby($event, $setting); break;
            case 'tamara': $this->processTamara($event, $setting); break;
            case 'paymob': $this->processPaymob($event); break;
            case 'tap': $this->processTap($event); break;
            case 'fawaterak': $this->processFawaterak($event); break;
            case 'paytabs': $this->processPayTabs($event); break;
            case 'fawry': $this->processFawry($event); break;
        }
        $receipt->update(['status' => 'processed', 'processed_at' => now()]);
        return response()->json(['received' => true]);
    }

    public function hyperpayReturn(Request $request, StorePaymentTransaction $transaction)
    {
        abort_unless($transaction->gateway === 'hyperpay', 404);
        $setting = StorePaymentGateway::query()
            ->where('store_id', $transaction->store_id)
            ->where('gateway', 'hyperpay')
            ->firstOrFail();
        $result = $this->payments->verifyHyperpayResult($transaction, $setting, (string) $request->query('resourcePath'));
        $code = (string) data_get($result, 'result.code');

        if (preg_match('/^(000\\.000\\.|000\\.100\\.1|000\\.[36])/', $code) === 1) {
            $this->markPaid($transaction, (string) data_get($result, 'id'));
            $status = 'success';
        } elseif (preg_match('/^(000\\.200\\.|800\\.400\\.5|100\\.400\\.500)/', $code) === 1) {
            $status = 'pending';
        } else {
            $this->markFailed($transaction);
            $status = 'failed';
        }

        return redirect()->away($this->payments->paymentRedirectUrl($transaction, $status));
    }

    public function fawrySession(StorePaymentTransaction $transaction): JsonResponse
    {
        abort_unless($transaction->gateway === 'fawry' && $transaction->status === 'pending', 404);
        $setting = StorePaymentGateway::query()
            ->where('store_id', $transaction->store_id)
            ->where('gateway', 'fawry')
            ->where('enabled', true)
            ->firstOrFail();

        return response()->json($this->payments->fawrySession($transaction, $setting));
    }

    public function fawryReturn(Request $request, StorePaymentTransaction $transaction)
    {
        abort_unless($transaction->gateway === 'fawry', 404);
        $setting = StorePaymentGateway::query()->where('store_id', $transaction->store_id)->where('gateway', 'fawry')->firstOrFail();
        $event = $request->all();
        $merchantRef = (string) ($event['merchantRefNumber'] ?? $event['merchantRefNum'] ?? '');
        abort_unless($merchantRef === (string) $transaction->id, 422, 'Fawry transaction mismatch.');
        $expected = hash('sha256',
            (string) ($event['referenceNumber'] ?? '').$merchantRef.
            $this->money($event['paymentAmount'] ?? 0).$this->money($event['orderAmount'] ?? 0).
            (string) ($event['orderStatus'] ?? '').(string) ($event['paymentMethod'] ?? '').
            $this->optionalMoney($event, 'fawryFees').$this->optionalMoney($event, 'shippingFees').
            (string) ($event['authNumber'] ?? '').(string) ($event['customerMail'] ?? '').
            (string) ($event['customerMobile'] ?? '').(string) data_get($setting->credentials, 'security_key')
        );
        abort_unless(hash_equals($expected, (string) ($event['signature'] ?? '')), 401, 'Invalid Fawry signature.');
        $status = strtoupper((string) ($event['orderStatus'] ?? ''));
        if ($status === 'PAID') {
            $this->markPaid($transaction, (string) ($event['referenceNumber'] ?? ''));
            $redirectStatus = 'success';
        } elseif (in_array($status, ['NEW', 'UNPAID'], true)) {
            $redirectStatus = 'pending';
        } else {
            $this->markFailed($transaction);
            $redirectStatus = 'failed';
        }

        return redirect()->away($this->payments->paymentRedirectUrl($transaction, $redirectStatus));
    }

    private function verifiedStripeEvent(Request $request, StorePaymentGateway $setting): array
    {
        $secret = (string) data_get($setting->credentials, 'webhook_secret');
        abort_if($secret === '', 422, 'Stripe webhook secret is not configured.');
        $parts = collect(explode(',', (string) $request->header('Stripe-Signature')))->mapWithKeys(function (string $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            return [$key => $value];
        });
        $timestamp = (int) $parts->get('t');
        abort_if($timestamp <= 0 || abs(time() - $timestamp) > 300, 401, 'Expired Stripe signature.');
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        abort_unless(hash_equals($expected, (string) $parts->get('v1')), 401, 'Invalid Stripe signature.');
        return $request->json()->all();
    }

    private function verifiedPayPalEvent(Request $request, StorePaymentGateway $setting): array
    {
        $webhookId = (string) data_get($setting->credentials, 'webhook_id');
        abort_if($webhookId === '', 422, 'PayPal webhook ID is not configured.');
        $event = $request->json()->all();
        $verification = $this->payments->paypalRequest($setting)
            ->post($this->payments->paypalBase($setting).'/v1/notifications/verify-webhook-signature', [
                'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'), 'cert_url' => $request->header('PAYPAL-CERT-URL'),
                'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
                'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
                'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
                'webhook_id' => $webhookId, 'webhook_event' => $event,
            ])->throw()->json();
        abort_unless(data_get($verification, 'verification_status') === 'SUCCESS', 401, 'Invalid PayPal signature.');
        return $event;
    }

    private function verifiedTabbyEvent(Request $request, StorePaymentGateway $setting): array
    {
        $paymentId = (string) $request->input('id');
        abort_if($paymentId === '', 422, 'Missing Tabby payment ID.');
        $transaction = StorePaymentTransaction::query()->where('gateway', 'tabby')->where('provider_reference', $paymentId)->firstOrFail();
        $event = $this->payments->retrieveTabby($setting, $paymentId, $transaction->currency);
        $event['_event_id'] = $paymentId.':'.data_get($event, 'status').':'.count(data_get($event, 'captures', []));
        return $event;
    }

    private function verifiedTamaraEvent(Request $request, StorePaymentGateway $setting): array
    {
        $jwt = (string) ($request->bearerToken() ?: $request->query('tamaraToken'));
        $secret = (string) data_get($setting->credentials, 'notification_token');
        abort_if($jwt === '' || $secret === '', 401, 'Tamara notification token is missing.');
        $parts = explode('.', $jwt);
        abort_unless(count($parts) === 3, 401, 'Invalid Tamara token.');
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0].'.'.$parts[1], $secret, true)), '+/', '-_'), '=');
        abort_unless(hash_equals($expected, $parts[2]), 401, 'Invalid Tamara signature.');
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) ?: [];
        abort_if(isset($payload['exp']) && (int) $payload['exp'] < time(), 401, 'Expired Tamara token.');
        $event = $request->json()->all();
        $event['_event_id'] = data_get($event, 'order_id').':'.data_get($event, 'event_type');
        return $event;
    }

    private function verifiedPaymobEvent(Request $request, StorePaymentGateway $setting): array
    {
        $obj = $request->input('obj', $request->json()->all());
        abort_unless(is_array($obj), 422, 'Invalid Paymob callback.');
        $keys = [
            'amount_cents', 'created_at', 'currency', 'error_occured', 'has_parent_transaction', 'id',
            'integration_id', 'is_3d_secure', 'is_auth', 'is_capture', 'is_refunded',
            'is_standalone_payment', 'is_voided', 'order.id', 'owner', 'pending',
            'source_data.pan', 'source_data.sub_type', 'source_data.type', 'success',
        ];
        $value = collect($keys)->map(fn (string $key) => $this->scalar(data_get($obj, $key)))->implode('');
        $expected = hash_hmac('sha512', $value, (string) data_get($setting->credentials, 'hmac_secret'));
        $provided = (string) ($request->query('hmac') ?: $request->input('hmac'));
        abort_unless($provided !== '' && hash_equals($expected, $provided), 401, 'Invalid Paymob signature.');
        $obj['_event_id'] = data_get($obj, 'id').':'.$this->scalar(data_get($obj, 'success')).':'.$this->scalar(data_get($obj, 'pending'));
        return $obj;
    }

    private function verifiedTapEvent(Request $request, StorePaymentGateway $setting): array
    {
        $chargeId = (string) $request->input('id');
        abort_if($chargeId === '', 422, 'Missing Tap charge ID.');
        $event = $this->payments->retrieveTap($setting, $chargeId);
        $event['_event_id'] = $chargeId.':'.data_get($event, 'status');
        return $event;
    }

    private function verifiedFawaterakEvent(Request $request, StorePaymentGateway $setting): array
    {
        $event = $request->json()->all();
        $vendorKey = (string) data_get($setting->credentials, 'vendor_key');
        abort_if($vendorKey === '', 422, 'Fawaterak vendor key is not configured.');
        $message = 'InvoiceId='.data_get($event, 'invoice_id').'&InvoiceKey='.data_get($event, 'invoice_key').'&PaymentMethod='.data_get($event, 'payment_method');
        $expected = hash_hmac('sha256', $message, $vendorKey);
        abort_unless(hash_equals($expected, (string) data_get($event, 'hashKey')), 401, 'Invalid Fawaterak signature.');
        $event['_event_id'] = data_get($event, 'invoice_id').':'.data_get($event, 'invoice_status');
        return $event;
    }

    private function verifiedFawryEvent(Request $request, StorePaymentGateway $setting): array
    {
        $event = $request->json()->all();
        $message = (string) data_get($event, 'fawryRefNumber').(string) data_get($event, 'merchantRefNumber').
            $this->money(data_get($event, 'paymentAmount')).$this->money(data_get($event, 'orderAmount')).
            (string) data_get($event, 'orderStatus').(string) data_get($event, 'paymentMethod').
            (string) data_get($event, 'paymentRefrenceNumber').(string) data_get($setting->credentials, 'security_key');
        $expected = hash('sha256', $message);
        abort_unless(hash_equals($expected, (string) data_get($event, 'messageSignature')), 401, 'Invalid Fawry signature.');
        $event['_event_id'] = (string) (data_get($event, 'requestId') ?: data_get($event, 'fawryRefNumber').':'.data_get($event, 'orderStatus'));

        return $event;
    }

    private function verifiedPayTabsEvent(Request $request, StorePaymentGateway $setting): array
    {
        $serverKey = (string) data_get($setting->credentials, 'server_key');
        $provided = (string) $request->header('Signature');
        abort_if($serverKey === '' || $provided === '', 401, 'PayTabs signature is missing.');
        $expected = hash_hmac('sha256', $request->getContent(), $serverKey);
        abort_unless(hash_equals($expected, $provided), 401, 'Invalid PayTabs signature.');

        $event = $request->json()->all();
        $event['_event_id'] = data_get($event, 'tran_ref').':'.data_get($event, 'payment_result.response_status').':'.data_get($event, 'payment_result.response_code');

        return $event;
    }

    private function processStripe(array $event): void
    {
        $type = (string) data_get($event, 'type');
        $transactionId = data_get($event, 'data.object.metadata.transaction_id') ?? data_get($event, 'data.object.client_reference_id');
        $transaction = StorePaymentTransaction::query()->find($transactionId);
        if ($transaction === null) return;
        if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            $this->markPaid($transaction, (string) (data_get($event, 'data.object.payment_intent') ?? data_get($event, 'data.object.id')));
        } elseif (in_array($type, ['checkout.session.expired', 'checkout.session.async_payment_failed'], true)) {
            $this->markFailed($transaction);
        }
    }

    private function processPayPal(array $event, StorePaymentGateway $setting): void
    {
        $type = (string) data_get($event, 'event_type');
        $providerReference = (string) (data_get($event, 'resource.supplementary_data.related_ids.order_id') ?? data_get($event, 'resource.id'));
        $transaction = StorePaymentTransaction::query()->where('gateway', 'paypal')->where('provider_reference', $providerReference)->first();
        if ($transaction === null) return;
        if ($type === 'CHECKOUT.ORDER.APPROVED') {
            $capture = $this->payments->capturePayPal($setting, $providerReference, $transaction->idempotency_key.'-capture');
            if (data_get($capture, 'status') === 'COMPLETED') $this->markPaid($transaction, $providerReference);
        } elseif ($type === 'PAYMENT.CAPTURE.COMPLETED') {
            $this->markPaid($transaction, (string) data_get($event, 'resource.id'));
        } elseif (in_array($type, ['PAYMENT.CAPTURE.DENIED', 'CHECKOUT.PAYMENT-APPROVAL.REVERSED'], true)) {
            $this->markFailed($transaction);
        }
    }

    private function processTabby(array $event, StorePaymentGateway $setting): void
    {
        $transaction = StorePaymentTransaction::query()->where('gateway', 'tabby')->where('provider_reference', data_get($event, 'id'))->first();
        if ($transaction === null) return;
        $status = strtoupper((string) data_get($event, 'status'));
        if ($status === 'AUTHORIZED') {
            $captured = $this->payments->captureTabby($setting, $transaction);
            if (strtoupper((string) data_get($captured, 'status')) === 'CLOSED') $this->markPaid($transaction, (string) data_get($captured, 'id'));
        } elseif ($status === 'CLOSED') {
            $this->markPaid($transaction, (string) data_get($event, 'id'));
        } elseif (in_array($status, ['REJECTED', 'EXPIRED'], true)) {
            $this->markFailed($transaction);
        }
    }

    private function processTamara(array $event, StorePaymentGateway $setting): void
    {
        $transaction = StorePaymentTransaction::query()->where('gateway', 'tamara')->where('provider_reference', data_get($event, 'order_id'))->first();
        if ($transaction === null) return;
        $type = (string) data_get($event, 'event_type');
        if ($type === 'order_approved') {
            $this->payments->authoriseTamara($setting, (string) data_get($event, 'order_id'));
        } elseif (in_array($type, ['order_authorised', 'order_captured'], true)) {
            $this->markPaid($transaction, (string) (data_get($event, 'data.capture_id') ?: data_get($event, 'order_id')));
        } elseif (in_array($type, ['order_declined', 'order_canceled', 'order_expired'], true)) {
            $this->markFailed($transaction);
        }
    }

    private function processPaymob(array $event): void
    {
        $transaction = StorePaymentTransaction::query()->where('gateway', 'paymob')
            ->where('provider_reference', (string) data_get($event, 'order.id'))->first();
        if ($transaction === null) return;
        $success = filter_var(data_get($event, 'success'), FILTER_VALIDATE_BOOLEAN);
        $pending = filter_var(data_get($event, 'pending'), FILTER_VALIDATE_BOOLEAN);
        $error = filter_var(data_get($event, 'error_occured'), FILTER_VALIDATE_BOOLEAN);
        if ($success && ! $pending && ! $error) $this->markPaid($transaction, (string) data_get($event, 'id'));
        elseif (! $pending) $this->markFailed($transaction);
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) return $value ? 'true' : 'false';
        return $value === null ? '' : (string) $value;
    }

    private function processTap(array $event): void
    {
        $transaction = StorePaymentTransaction::query()->where('gateway', 'tap')->where('provider_reference', data_get($event, 'id'))->first();
        if ($transaction === null) return;
        $status = strtoupper((string) data_get($event, 'status'));
        if ($status === 'CAPTURED') $this->markPaid($transaction, (string) data_get($event, 'id'));
        elseif (in_array($status, ['FAILED', 'DECLINED', 'CANCELLED', 'ABANDONED', 'RESTRICTED', 'VOID'], true)) $this->markFailed($transaction);
    }

    private function processFawaterak(array $event): void
    {
        $transaction = StorePaymentTransaction::query()->where('gateway', 'fawaterak')->where('provider_reference', data_get($event, 'invoice_key'))->first();
        if ($transaction === null) return;
        if (strtolower((string) data_get($event, 'invoice_status')) === 'paid') {
            $this->markPaid($transaction, (string) (data_get($event, 'referenceNumber') ?: data_get($event, 'invoice_id')));
        }
    }

    private function processPayTabs(array $event): void
    {
        $transaction = StorePaymentTransaction::query()
            ->where('gateway', 'paytabs')
            ->where('provider_reference', data_get($event, 'tran_ref'))
            ->first();

        if ($transaction === null && filled(data_get($event, 'cart_id'))) {
            $transaction = StorePaymentTransaction::query()
                ->where('gateway', 'paytabs')
                ->whereKey(data_get($event, 'cart_id'))
                ->first();
        }
        if ($transaction === null) return;

        if (data_get($event, 'payment_result.response_status') === 'A') {
            $this->markPaid($transaction, (string) data_get($event, 'tran_ref'));
        } else {
            $this->markFailed($transaction);
        }
    }

    private function processFawry(array $event): void
    {
        $transaction = StorePaymentTransaction::query()
            ->where('gateway', 'fawry')
            ->whereKey(data_get($event, 'merchantRefNumber'))
            ->first();
        if ($transaction === null) return;

        $status = strtoupper((string) data_get($event, 'orderStatus'));
        if ($status === 'PAID') {
            $this->markPaid($transaction, (string) data_get($event, 'fawryRefNumber'));
        } elseif (in_array($status, ['CANCELED', 'EXPIRED', 'FAILED'], true)) {
            $this->markFailed($transaction);
        }
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function optionalMoney(array $event, string $key): string
    {
        return array_key_exists($key, $event) && $event[$key] !== null && $event[$key] !== '' ? $this->money($event[$key]) : '';
    }

    private function markPaid(StorePaymentTransaction $transaction, string $reference): void
    {
        if ($transaction->status === 'paid') return;
        $transaction->update(['status' => 'paid', 'provider_reference' => $reference, 'paid_at' => now(), 'failed_at' => null]);
        $transaction->order()->update(['payment_status' => 'paid', 'payment_reference' => $reference]);
    }

    private function markFailed(StorePaymentTransaction $transaction): void
    {
        if ($transaction->status === 'paid') return;
        $transaction->update(['status' => 'failed', 'failed_at' => now()]);
        $transaction->order()->update(['payment_status' => 'failed']);
    }
}
