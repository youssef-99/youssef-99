<?php

return [
    'version' => env('ERP_VERSION', '1.0.0'),

    // Only one active session per user (a second login shows "This user is already logged in").
    'single_session' => env('ERP_SINGLE_SESSION', true),

    // Allow sales/transfers to take stock below zero.
    'allow_negative_stock' => env('ERP_ALLOW_NEGATIVE_STOCK', true),

    'locales' => ['ar' => 'العربية', 'en' => 'English'],

    'currency' => 'SAR',

    'default_vat_rate' => 15,

    'zatca' => [
        'enabled' => env('ZATCA_ENABLED', true),
    ],
];
