<?php

/**
 * User notification preference keys: in-app (database) and email (mail) channels.
 * Admin UI shows only keys whose `roles` intersect the target user's Spatie roles.
 *
 * @var array<string, array{label_key: string, defaults: array{in_app: bool, email: bool}, roles: list<string>}>
 */
return [
    'keys' => [
        'login_security' => [
            'label_key' => 'notif_pref_login_security',
            'defaults' => [
                'in_app' => true,
                'email' => false,
            ],
            'roles' => ['Admin', 'Staff', 'Manager', 'Merchant'],
        ],
        'delivery_updates' => [
            'label_key' => 'notif_pref_delivery_updates',
            'defaults' => [
                'in_app' => true,
                'email' => true,
            ],
            'roles' => ['Admin', 'Merchant'],
        ],
        'inventory_alerts' => [
            'label_key' => 'notif_pref_inventory_alerts',
            'defaults' => [
                'in_app' => true,
                'email' => true,
            ],
            'roles' => ['Admin', 'Merchant'],
        ],
        // Custom-domain lifecycle: verification, SSL issuance/renewal/expiry,
        // DNS loss and primary changes. Email defaults ON — an expiring
        // certificate is a storefront outage the owner must not miss.
        'domain_alerts' => [
            'label_key' => 'notif_pref_domain_alerts',
            'defaults' => [
                'in_app' => true,
                'email' => true,
            ],
            'roles' => ['Admin', 'Merchant', 'Supplier'],
        ],
        'quotation_activity' => [
            'label_key' => 'notif_pref_quotation_activity',
            'defaults' => [
                'in_app' => true,
                'email' => true,
            ],
            'roles' => ['Admin', 'Merchant'],
        ],
        'ticket_in_app' => [
            'label_key' => 'notif_pref_ticket_updates',
            'defaults' => [
                'in_app' => true,
                'email' => false,
            ],
            'roles' => ['Admin', 'Staff', 'Manager', 'Merchant'],
        ],
        'quotation_approved' => [
            'label_key' => 'notif_pref_quotation_approved',
            'defaults' => [
                'in_app' => true,
                'email' => true,
            ],
            'roles' => ['Supplier'],
        ],
        'order_assigned' => [
            'label_key' => 'notif_pref_order_assigned',
            'defaults' => [
                'in_app' => true,
                'email' => true,
            ],
            'roles' => ['Supplier'],
        ],
    ],
];
