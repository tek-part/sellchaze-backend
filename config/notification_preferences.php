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
            'roles' => ['Admin', 'Merchant'],
        ],
        // Support tickets: new tickets/replies for the support team, replies and resolutions for merchants.
        'ticket_in_app' => [
            'label_key' => 'notif_pref_ticket_updates',
            'defaults' => [
                'in_app' => true,
                'email' => true,
            ],
            'roles' => ['Admin', 'Staff', 'Manager', 'Merchant'],
        ],
    ],
];
