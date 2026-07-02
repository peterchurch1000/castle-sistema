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

        $user = (string) config('dashboard.auth_user');
        $pass = (string) config('dashboard.auth_pass');

        // If no password is configured, don't lock anyone out.
        if ($pass === '') {
            return $next($request);
        }

        if (hash_equals($user, (string) $request->getUser())
            && hash_equals($pass, (string) $request->getPassword())) {
            return $next($request);
        }

        return response('Autenticacion requerida.', 401, [
            'WWW-Authenticate' => 'Basic realm="Panel Castle"',
        ]);
    }
}
