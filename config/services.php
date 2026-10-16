<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Delegated Microsoft Graph for Peter's Adlux mailbox (read-only on Castle).
    // Same Entra app as the Adlux server ("AdLux Autonomy Graph Integration").
    // The refresh token (confidential client, offline_access) works from any host;
    // the live token is stored in storage/app/graph-token.json, not here.
    'graph_delegated' => [
        'tenant_id'     => env('GRAPH_DEL_TENANT_ID'),
        'client_id'     => env('GRAPH_DEL_CLIENT_ID'),
        'client_secret' => env('GRAPH_DEL_CLIENT_SECRET'),
    ],

];
