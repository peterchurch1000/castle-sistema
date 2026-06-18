<?php

/*
|--------------------------------------------------------------------------
| External integration credentials (NetSuite + Salesforce)
|--------------------------------------------------------------------------
| These values MUST be read here (in a config file) rather than via env()
| at runtime in controllers/services. Laravel only evaluates env() inside
| config files; once `php artisan config:cache` is run, env() returns null
| everywhere else. Reading them here keeps the app working whether or not
| the config cache is built. Always reference them with config('integrations.*').
*/

return [

    'netsuite' => [
        'realm'   => env('OAUTH_REALM'),
        'version' => env('OAUTH_VERSION', '1.0'),

        // RESTlet (saved-search invoices / sales)
        'restlet' => [
            'consumer_key'    => env('OAUTH_RESTLET_CONSUMER_KEY'),
            'consumer_secret' => env('OAUTH_RESTLET_CONSUMER_SECRET'),
            'token'           => env('OAUTH_RESTLET_TOKEN'),
            'token_secret'    => env('OAUTH_RESTLET_TOKEN_SECRET'),
        ],

        // SuiteQL (quotas)
        'suiteql' => [
            'consumer_key'    => env('OAUTH_SUITEQL_CONSUMER_KEY'),
            'consumer_secret' => env('OAUTH_SUITEQL_CONSUMER_SECRET'),
            'token'           => env('OAUTH_SUITEQL_TOKEN'),
            'token_secret'    => env('OAUTH_SUITEQL_TOKEN_SECRET'),
        ],

        // DIFOT Operaciones card (search 674 "GEP - CUMPLIMIENTO OPERACIONES - AL DIA").
        // Reproduced via the custom "Castle DIFOT Saved Search API" RESTlet (script 1503,
        // deploy 1), which loads search 674 and re-runs it through search.create — see
        // OperacionesController::fetchDifotOperaciones(). Called with the restlet creds above.
        'difot' => [
            'restlet_script' => env('NETSUITE_DIFOT_SCRIPT', '1503'),
            'restlet_deploy' => env('NETSUITE_DIFOT_DEPLOY', '1'),
            'search_id'      => env('NETSUITE_DIFOT_SEARCH', '674'),
        ],
    ],

    'salesforce' => [
        'instance_url'  => env('SF_INSTANCE_URL'),
        'client_id'     => env('SF_CLIENT_ID'),
        'client_secret' => env('SF_CLIENT_SECRET'),
    ],

];
