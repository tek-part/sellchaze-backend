<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\StoreNotificationMail;
use App\Models\OutboxMessage;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Services\Commerce\StoreDigitalWhatsappClient;
use App\Services\Notifications\StoreNotificationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Merchant settings for customer/staff order notifications (email + WhatsApp), preview, test and per-order log. */
class StoreNotificationsController extends Controller
{
    public function __construct(private readonly StoreNotificationSettings $notifications) {}

    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        $user = $request->user();
        abort_unless($store instanceof Store, 404);
        abort_unless($user && ((int) $store->owner_user_id === (int) $user->id || $user->can('store.settings.manage') || $user->can('stores-edit')), 403);

        return $store;
    }

    private function payload(Store $store): array
    {
        $credentials = $store->digital_delivery_credentials ?? [];

        return [
            'data' => $this->notifications->configured($store),
            'catalog' => collect(StoreNotificationSettings::events())->map(fn ($event, $key) => ['key' => $key] + array_intersect_key($event, array_flip(['audience', 'group', 'channels', 'label', 'description'])))->values()->all(),
            'variables' => StoreNotificationSettings::VARIABLES,
            'connection' => ['whatsapp_connected' => ! empty($credentials['access_token']) && ! empty($credentials['instance_id']), 'whatsapp_verified' => $this->notifications->whatsappReady($store)],
            'owner_email' => $store->owner?->email,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->payload($this->store($request)));
    }

    public function update(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $events = StoreNotificationSettings::events();
        $rules = [
            'settings' => ['required', 'array:sender_name,reply_to,staff_recipients,events'],
            'settings.sender_name' => ['nullable', 'string', 'max:100', 'regex:/^[^\x00-\x1f\x7f]*$/u'],
            'settings.reply_to' => ['nullable', 'email:rfc', 'max:190'],
            'settings.staff_recipients' => ['present', 'array', 'max:'.StoreNotificationSettings::MAX_STAFF_RECIPIENTS],
            'settings.staff_recipients.*' => ['required', 'email:rfc', 'max:190', 'distinct:ignore_case'],
            'settings.events' => ['required', 'array:'.implode(',', array_keys($events))],
        ];
        foreach ($events as $key => $event) {
            $whatsapp = in_array('whatsapp', $event['channels'], true);
            $prefix = 'settings.events.'.$key.'.';
            $rules['settings.events.'.$key] = ['required', 'array:email_enabled,email_subject,email_body'.($whatsapp ? ',whatsapp_enabled,whatsapp_body' : '')];
            $rules[$prefix.'email_enabled'] = ['required', 'boolean'];
            $rules[$prefix.'email_subject'] = ['required', 'string', 'max:200', 'regex:/^[^\x00-\x1f\x7f]+$/u'];
            $rules[$prefix.'email_body'] = ['required', 'string', 'max:6000'];
            if ($whatsapp) {
                $rules[$prefix.'whatsapp_enabled'] = ['required', 'boolean'];
                $rules[$prefix.'whatsapp_body'] = ['required', 'string', 'max:3000'];
            }
        }
        $data = $request->validate($rules)['settings'];
        foreach ($data['events'] as $key => $event) {
            foreach (['email_subject', 'email_body', 'whatsapp_body'] as $field) {
                if (isset($event[$field]) && ($unknown = self::unknownVariables($event[$field]))) {
                    throw ValidationException::withMessages(['settings.events.'.$key.'.'.$field => 'Unknown variable: {'.$unknown[0].'}']);
                }
            }
        }
        DB::transaction(function () use ($store, $data) {
            $current = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $enablesWhatsapp = collect($data['events'])->contains(fn ($event) => ! empty($event['whatsapp_enabled']));
            if ($enablesWhatsapp && ! $this->notifications->whatsappReady($current)) {
                throw ValidationException::withMessages(['settings.events' => 'Connect and verify the store\'s WhatsApp before enabling WhatsApp notifications.']);
            }
            $data['staff_recipients'] = array_values(array_map(fn ($email) => strtolower(trim($email)), $data['staff_recipients']));
            $current->update(['notification_settings' => $data]);
        });

        return response()->json($this->payload($store->fresh()));
    }

    /** @return list<string> */
    private static function unknownVariables(string $template): array
    {
        preg_match_all('/\{([^{}]*)\}/u', $template, $tokens);

        return array_values(array_diff($tokens[1], StoreNotificationSettings::VARIABLES));
    }

    /** The latest real order (so previews look like the merchant's store), or a sample one. */
    private function sampleOrder(Store $store): StoreOrder
    {
        $order = StoreOrder::forStore($store->id)->with('items')->latest('id')->first();
        if ($order) {
            return $order;
        }
        $ar = $store->default_locale === 'ar';
        $order = new StoreOrder(['store_id' => $store->id, 'order_number' => '1001', 'currency' => $store->currency ?: 'EGP',
            'customer_name' => $ar ? 'سارة أحمد' : 'Sara Ahmed', 'customer_email' => 'customer@example.com', 'customer_phone' => '201000000000',
            'shipping_address' => ['line' => $ar ? '12 شارع النصر' : '12 El Nasr St', 'city' => $ar ? 'القاهرة' : 'Cairo'],
            'payment_method' => 'cod', 'payment_status' => 'pending', 'status' => 'pending',
            'subtotal' => 900, 'shipping_total' => 50, 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => 950]);
        $order->id = 0;
        $order->setRelation('store', $store);
        $order->setRelation('items', collect([new StoreOrderItem(['name' => $ar ? 'منتج تجريبي' : 'Sample product', 'quantity' => 2, 'unit_price' => 450, 'line_total' => 900])]));

        return $order;
    }

    private function event(Request $request): array
    {
        $events = StoreNotificationSettings::events();
        $data = $request->validate([
            'event' => ['required', 'string', Rule::in(array_keys($events))],
            'channel' => ['required', Rule::in(['email', 'whatsapp'])],
        ]);
        if (! in_array($data['channel'], $events[$data['event']]['channels'], true)) {
            throw ValidationException::withMessages(['channel' => 'This notification is not sent on that channel.']);
        }

        return $data;
    }

    public function preview(Request $request): JsonResponse
    {
        $store = $this->store($request);
        ['event' => $event, 'channel' => $channel] = $this->event($request);
        $template = $request->validate(['template' => ['required', 'array:subject,body'], 'template.subject' => ['nullable', 'string', 'max:200'],
            'template.body' => ['required', 'string', 'max:6000']])['template'];
        $order = $this->sampleOrder($store);
        $staff = StoreNotificationSettings::events()[$event]['audience'] === 'staff';
        $variables = $this->notifications->variables($store, $order, $staff);
        if ($channel === 'whatsapp') {
            return response()->json(['subject' => null, 'html' => null, 'text' => StoreNotificationSettings::render($template['body'], $variables)]);
        }
        $mail = new StoreNotificationMail($store, $order, $event, ['subject' => (string) ($template['subject'] ?? ''), 'body' => $template['body']]);

        return response()->json(['subject' => StoreNotificationSettings::render((string) ($template['subject'] ?? ''), $variables), 'html' => $mail->render(),
            'text' => StoreNotificationSettings::render($template['body'], $variables)]);
    }

    public function test(Request $request, StoreDigitalWhatsappClient $client): JsonResponse
    {
        $store = $this->store($request);
        ['event' => $event, 'channel' => $channel] = $this->event($request);
        $recipient = $request->validate(['recipient' => ['nullable', 'string', 'max:190']])['recipient'] ?? null;
        $config = $this->notifications->configured($store)['events'][$event];
        $order = $this->sampleOrder($store);
        if ($channel === 'email') {
            $recipient = $recipient ?: $store->owner?->email;
            if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['recipient' => 'Enter a valid email address.']);
            }
            try {
                Mail::to($recipient)->send(new StoreNotificationMail($store, $order, $event, ['subject' => $config['email_subject'], 'body' => $config['email_body']], null, true));
            } catch (\Throwable) {
                throw ValidationException::withMessages(['recipient' => 'The test email could not be sent. Try again later.']);
            }

            return response()->json(['status' => 'sent']);
        }
        $phone = StoreDigitalWhatsappClient::phone((string) $recipient);
        if (! $phone) {
            throw ValidationException::withMessages(['recipient' => 'Enter an international phone number, e.g. 201001234567.']);
        }
        if (! $this->notifications->whatsappReady($store)) {
            throw ValidationException::withMessages(['recipient' => 'Connect and verify the store\'s WhatsApp first.']);
        }
        $text = StoreNotificationSettings::render((string) $config['whatsapp_body'], $this->notifications->variables($store, $order));
        $result = $client->send($store->digital_delivery_credentials, $phone, ($store->default_locale === 'ar' ? '[تجربة] ' : '[TEST] ').$text);
        if ($result['state'] !== 'accepted') {
            throw ValidationException::withMessages(['recipient' => 'WhatsApp did not accept the test message. Check the connection.']);
        }

        return response()->json(['status' => 'sent']);
    }

    public function orderLog(Request $request, Store $store, int $order): JsonResponse
    {
        $current = StoreOrder::forStore($store->id)->whereKey($order)->firstOrFail();
        $messages = OutboxMessage::query()->whereIn('event_type', StoreNotificationSettings::MESSAGE_TYPES)->where('aggregate_type', 'store_order')
            ->where('aggregate_id', (string) $current->id)->orderByDesc('created_at')->limit(100)->get();

        return response()->json(['data' => $messages->map(fn (OutboxMessage $message) => [
            'id' => $message->id,
            'event' => $message->payload['event'] ?? null,
            'channel' => $message->payload['channel'] ?? null,
            'recipient' => self::mask((string) ($message->payload['recipient'] ?? '')),
            'state' => $message->failed_at ? 'failed' : ($message->metadata['transport_state'] ?? ($message->published_at ? 'sent' : 'queued')),
            'created_at' => $message->created_at?->toIso8601String(),
            'sent_at' => $message->metadata['sent_at'] ?? null,
        ])->values()]);
    }

    public function resend(Request $request, Store $store, int $order): JsonResponse
    {
        $current = StoreOrder::forStore($store->id)->whereKey($order)->firstOrFail();
        ['event' => $event, 'channel' => $channel] = $this->event($request);
        if (StoreNotificationSettings::events()[$event]['audience'] !== 'customer') {
            throw ValidationException::withMessages(['event' => 'Only customer notifications can be resent.']);
        }
        $queued = DB::transaction(fn () => $this->notifications->schedule($current, $event, [$channel], true));
        if ($queued === 0) {
            throw ValidationException::withMessages(['channel' => $channel === 'email' ? 'This order has no valid customer email.' : 'This order has no valid phone, or WhatsApp is not verified.']);
        }

        return response()->json(['status' => 'queued']);
    }

    public static function mask(string $recipient): string
    {
        if (str_contains($recipient, '@')) {
            [$name, $domain] = explode('@', $recipient, 2);

            return mb_substr($name, 0, 1).'***@'.$domain;
        }

        return strlen($recipient) > 4 ? str_repeat('•', strlen($recipient) - 4).substr($recipient, -4) : $recipient;
    }
}
