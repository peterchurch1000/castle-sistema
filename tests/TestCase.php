<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Force an isolated, disposable in-memory SQLite database for EVERY test, then
     * fail closed if that did not take effect.
     *
     * This runs *before* parent::setUp() invokes the RefreshDatabase trait (which
     * calls migrate:fresh / db:wipe), so the trait can only ever operate on the
     * throwaway :memory: database — never on real data.
     *
     * Why force it in code rather than trust phpunit.xml env?
     * The castle-app container injects DB_DATABASE=castle_sistema / DB_HOST=db as
     * real process environment variables and ships a cached bootstrap/cache/config.php
     * (default = mysql, sqlite.database = castle_sistema). Both outrank phpunit.xml's
     * <env> values, so relying on them is unsafe. On 2026-10-08 that mismatch let
     * RefreshDatabase wipe the production MariaDB. Pinning the connection here is the
     * only reliable guarantee; the guard below is the backstop.
     */
    protected function refreshApplication()
    {
        parent::refreshApplication();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.driver' => 'sqlite',
            'database.connections.sqlite.url' => null,
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.prefix' => '',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);

        // Drop any connection resolved from the old (prod) config before use.
        $this->app['db']->purge('sqlite');
        DB::setDefaultConnection('sqlite');

        $this->guardAgainstNonTestDatabase();
    }

    private function guardAgainstNonTestDatabase(): void
    {
        $connection = config('database.default');
        $settings = config("database.connections.{$connection}", []);
        $driver = $settings['driver'] ?? null;
        $database = $settings['database'] ?? null;

        $isDisposable = $driver === 'sqlite' && $database === ':memory:';

        if (! $isDisposable) {
            throw new RuntimeException(sprintf(
                'REFUSING TO RUN TESTS: active DB connection [%s] is not isolated in-memory '.
                'SQLite (driver=%s, database=%s). The test suite uses RefreshDatabase, which '.
                'would WIPE this database. A cached bootstrap/cache/config.php or container '.
                'DB_* env is the usual cause — the in-memory pin in tests/TestCase.php failed.',
                $connection,
                $driver ?? 'null',
                $database ?? 'null'
            ));
        }
    }
}
