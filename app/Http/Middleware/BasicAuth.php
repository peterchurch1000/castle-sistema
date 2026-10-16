<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // Let the health check endpoint through without a prompt.
        if ($request->is('up')) {
            return $next($request);
        }

        // API routes authenticate via Supabase JWT; skip Basic auth.
        if ($request->is('api/*')) {
            return $next($request);
        }

        // The login form and its handlers must stay reachable.
        if ($request->is('login') || $request->is('logout')) {
            return $next($request);
        }

        $user = (string) config('dashboard.auth_user');
        $pass = (string) config('dashboard.auth_pass');

        // If no password is configured, don't lock anyone out.
        if ($pass === '') {
            return $next($request);
        }

        // Form login: session flag set by the POST /login handler.
        if ($request->hasSession() && $request->session()->get('castle_authed') === true) {
            return $next($request);
        }

        // Basic-auth header still accepted for cron / curl / scripted callers.
        if (hash_equals($user, (string) $request->getUser())
            && hash_equals($pass, (string) $request->getPassword())) {
            return $next($request);
        }

        // Browsers get the in-page login form instead of a native popup.
        return redirect()->guest('/login');
    }
}
