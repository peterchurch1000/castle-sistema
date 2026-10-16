<?php

namespace App\Providers;

use App\Mail\Transport\GmailApiTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Custom Gmail REST API mail transport (SMTP is blocked outbound on this host).
        Mail::extend('gmail_api', function (array $config) {
            return new GmailApiTransport(
                (string) ($config['client_id'] ?? env('GMAIL_API_CLIENT_ID', '')),
                (string) ($config['client_secret'] ?? env('GMAIL_API_CLIENT_SECRET', '')),
                (string) ($config['refresh_token'] ?? env('GMAIL_API_REFRESH_TOKEN', '')),
            );
        });
    }
}
