<?php

return [
    'version' => env('ERP_VERSION', '1.0.0'),

    // Only one active session per user (a second login shows "This user is already logged in").
    'single_session' => env('ERP_SINGLE_SESSION', true),

    // Allow sales/transfers to take stock below zero.
    'allow_negative_stock' => env('ERP_ALLOW_NEGATIVE_STOCK', true),

    // Password for the "admin" user created by SystemSeeder on a fresh install (required in production;
    // deploy/activate-release.sh passes it from /etc/erp-web/initial-admin-password). Local installs default to "password".
    'initial_admin_password' => env('ERP_ADMIN_PASSWORD'),

    'locales' => ['ar' => 'العربية', 'en' => 'English'],

    'currency' => 'SAR',

    'default_vat_rate' => 15,

    // Defaults for the ZATCA settings on General > System Settings (the settings table overrides them).
    // Rasd (SFDA drug track-and-trace): only "sandbox" is implemented; other modes ask for a manual upload.
    'rasd' => [
        'mode' => env('RASD_MODE', 'sandbox'),
    ],

    'zatca' => [
        'enabled' => env('ZATCA_ENABLED', true),
        'environment' => env('ZATCA_ENVIRONMENT', 'sandbox'),
        'submission_mode' => env('ZATCA_SUBMISSION_MODE', 'on_post'),
        // Simulate ZATCA acceptance without any network call (demo / offline installs).
        'offline' => env('ZATCA_OFFLINE', false),
    ],
];
