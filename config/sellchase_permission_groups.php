<?php

/**
 * Maps UI group keys to permission names (must match Spatie `permissions.name`).
 * Permissions in DB but not listed here appear under group "other".
 */
return [
    'users' => [
        'users-list',
        'users-pending-list',
        'users-create',
        'users-edit',
        'users-delete',
    ],
    'roles' => [
        'roles-list',
        'roles-create',
        'roles-edit',
        'roles-delete',
    ],
    'permissions' => [
        'permissions-list',
        'permissions-create',
        'permissions-edit',
        'permissions-delete',
    ],
    'products' => [
        'products-list',
        'products-create',
        'products-edit',
        'products-delete',
    ],
    'categories' => [
        'categories-list',
        'categories-create',
        'categories-edit',
        'categories-delete',
    ],
    'bundles' => [
        'bundles-list',
        'bundles-create',
        'bundles-edit',
        'bundles-delete',
    ],
    'attributes' => [
        'attributes-list',
        'attributes-create',
        'attributes-edit',
        'attributes-delete',
    ],
    'orders' => [
        'orders-out',
        'orders-in',
        'orders-create',
    ],
    'quotations' => [
        'quotations-in',
        'quotations-out',
    ],
    'deals' => [
        'deals-in',
        'deals-out',
    ],
    'balance' => [
        'balance-in',
        'balance-out',
    ],
    'notifications' => [
        'notifications-orders',
        'notifications-quotations',
    ],
    'invitations' => [
        'invitations-list',
        'invitations-send-request',
        'invitations-delete',
    ],
    'supplier_routings' => [
        'supplier-routings-manage',
    ],
    'settings' => [
        'settings-view',
        'settings-edit',
    ],
    'gateways' => [
        'gateways-list',
        'gateways-create',
        'gateways-edit',
        'gateways-delete',
        'gateways-manage',
    ],
    'suppliers' => [
        'suppliers-list',
    ],
    'deliveries' => [
        'deliveries-list',
        'deliveries-update',
    ],
    'shipping_companies' => [
        'shipping-companies-list',
        'shipping-companies-create',
        'shipping-companies-edit',
        'shipping-companies-delete',
    ],
    'tickets' => [
        'tickets-list',
        'tickets-create',
        'tickets-manage',
    ],
    'suppliers_payments' => [
        'suppliers-payments-list',
        'suppliers-payments-manage',
    ],
    'website' => [
        'website-settings-view',
        'website-settings-edit',
    ],
    'articles' => [
        'articles-list',
        'articles-create',
        'articles-edit',
        'articles-delete',
    ],
    'wavex' => [
        'wavex-access',
    ],
    'activity_logs' => [
        'activity-logs-list',
    ],
    'monitoring' => [
        'monitoring-live-view',
    ],
];
