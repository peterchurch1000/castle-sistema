<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The dashboard is protected by HTTP Basic auth (App\Http\Middleware\BasicAuth):
     * an unauthenticated request to the home route must be challenged with 401.
     * A fixed password is forced via config so the test does not depend on the
     * container's DASHBOARD_AUTH_* environment. The middleware short-circuits before
     * the controller, so this needs no database schema.
     */
    public function test_home_route_requires_basic_auth(): void
    {
        config([
            'dashboard.auth_user' => 'castle',
            'dashboard.auth_pass' => 'secret',
        ]);

        $this->get('/')->assertStatus(401);
    }
}
