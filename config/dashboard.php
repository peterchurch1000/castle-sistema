<?php

return [
    'auth_user' => env('DASHBOARD_AUTH_USER', 'castle'),
    'auth_pass' => env('DASHBOARD_AUTH_PASS'),

    // Monthly dashboard screenshot email (task 246 — requested by Luciano).
    // Fires at 23:00 on the last calendar day of each month (America/Argentina/Buenos_Aires).
    'monthly_email' => [
        'enabled' => env('MONTHLY_DASHBOARD_EMAIL_ENABLED', false),
        'to' => env('DASHBOARD_EMAIL_TO', 'luciano.tabares@castle-global.com'),
        'from' => env('DASHBOARD_EMAIL_FROM', 'peter.church@castle-global.com'),
        'from_name' => env('DASHBOARD_EMAIL_FROM_NAME', 'Panel Castle'),
        // Mailer used for this feature only (SMTP is blocked → Gmail REST API).
        'mailer' => env('DASHBOARD_EMAIL_MAILER', 'gmail_api'),
        'timezone' => 'America/Argentina/Buenos_Aires',
        // slug => URL captured headlessly (internal, avoids the public TLS hop).
        'pages' => [
            'ventas' => env('DASHBOARD_EMAIL_URL_VENTAS', 'http://127.0.0.1:8000/ventas'),
            'operaciones' => env('DASHBOARD_EMAIL_URL_OPERACIONES', 'http://127.0.0.1:8000/operaciones'),
        ],
    ],
];
