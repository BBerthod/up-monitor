<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Databases the suite is allowed to touch.
     *
     * RefreshDatabase truncates whatever connection it resolves to, so the
     * suite must prove it is pointed at a throwaway database before the first
     * test runs.
     */
    private const ALLOWED_DATABASE_SUFFIX = '_test';

    /**
     * Refuse to run against anything but a test database.
     *
     * phpunit.xml alone cannot guarantee this. Its <env> entries are ignored
     * whenever bootstrap/cache/config.php exists — the Docker entrypoint
     * regenerates that cache on every boot — because a cached config never
     * evaluates the env() calls those entries were meant to override. The suite
     * then resolves to the development database and RefreshDatabase truncates
     * it, silently and completely.
     *
     * The check lives here, not in setUp(), because setUp() runs the trait
     * hooks first: by the time a setUp() override regains control
     * RefreshDatabase has already migrated over the target. createApplication()
     * is the last point where the resolved config is known and nothing has
     * touched the database yet.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if (! str_ends_with($database, self::ALLOWED_DATABASE_SUFFIX)) {
            throw new RuntimeException(
                "Refusing to run tests against the '{$database}' database: the name does not end in "
                .self::ALLOWED_DATABASE_SUFFIX.', so this is not a throwaway database and RefreshDatabase '
                ."would truncate it.\n\n"
                ."A cached config is the usual cause — it overrides phpunit.xml. Clear it first:\n"
                ."    php artisan config:clear\n"
            );
        }

        return $app;
    }
}
