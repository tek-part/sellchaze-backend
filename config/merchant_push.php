<?php

return [
    'public_key' => env('MERCHANT_PUSH_VAPID_PUBLIC_KEY', ''),
    'private_key' => env('MERCHANT_PUSH_VAPID_PRIVATE_KEY', ''),
    'subject' => env('MERCHANT_PUSH_VAPID_SUBJECT', env('APP_URL', '')),
];
