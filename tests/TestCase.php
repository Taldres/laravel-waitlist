<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Tests;

use Closure;
use Illuminate\Database\Migrations\Migration;
use Orchestra\Testbench\TestCase as Orchestra;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\WaitlistServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->migrateWaitlist();

        Waitlist::define(static::defineTestProject(...));
    }

    /**
     * The default project every test starts from: every list name accepted,
     * each with the primary purpose and an optional newsletter.
     */
    public static function defineTestProject(ProjectDefinition $project): void
    {
        static::defineTestPurposes($project);

        $project->list('*', purpose: 'waitlist')->optional('newsletter');
    }

    /**
     * Two versions of the primary purpose, so a form loaded before a wording
     * change can be tested, and the newsletter.
     */
    public static function defineTestPurposes(ProjectDefinition $project): void
    {
        $project->purpose('waitlist', [
            '2026-09' => 'Earlier wording.',
            '2026-10' => 'Email me when early access opens.',
        ]);
        $project->purpose('newsletter', ['2026-10' => 'Also send me the newsletter.']);
    }

    /**
     * Every test opens up to three connections and server databases cap them;
     * close them, or a long suite runs out on Postgres.
     */
    protected function tearDown(): void
    {
        foreach (array_keys($this->app['db']->getConnections()) as $connection) {
            $this->app['db']->disconnect($connection);
        }

        parent::tearDown();
    }

    /**
     * Down first: server databases keep tables between tests.
     */
    protected function migrateWaitlist(): void
    {
        // Every include compiles the file again and that memory is never freed.
        static $migrations = null;

        $migrations ??= array_map(
            fn (string $path): Migration => include $path,
            glob(__DIR__.'/../database/migrations/*.php') ?: [],
        );

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }

        foreach ($migrations as $migration) {
            $migration->up();
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            WaitlistServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', static::connectionConfig());

        // Two more handles onto the same database, so concurrency tests can act
        // as independent clients with their own snapshots and row locks.
        foreach (['waitlist_a', 'waitlist_b'] as $actor) {
            $app['config']->set("database.connections.{$actor}", static::connectionConfig());
        }
    }

    /**
     * Strict as in Laravel's default config, so the server's own sql_mode
     * cannot hide what an app would run into.
     *
     * @return array<string, mixed>
     */
    protected static function connectionConfig(): array
    {
        return match (env('DB_CONNECTION', 'sqlite')) {
            'mysql' => [
                'driver' => 'mysql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', 3306),
                'database' => env('DB_DATABASE', 'waitlist'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'strict' => true,
            ],
            'mariadb' => [
                'driver' => 'mariadb',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', 3306),
                'database' => env('DB_DATABASE', 'waitlist'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'strict' => true,
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', 5432),
                'database' => env('DB_DATABASE', 'waitlist'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                // SQLite ignores foreign keys unless enabled; the erasure cascade needs them.
                'foreign_key_constraints' => true,
            ],
        };
    }

    protected static function usesServerDatabase(): bool
    {
        return in_array(env('DB_CONNECTION', 'sqlite'), ['mysql', 'mariadb', 'pgsql'], true);
    }

    /**
     * An in-memory SQLite database is per connection, so a second handle would
     * not see the first one's rows.
     */
    protected function skipWithoutSecondConnection(): void
    {
        if (! static::usesServerDatabase()) {
            $this->markTestSkipped('Needs a server database; run with DB_CONNECTION=mysql, mariadb or pgsql.');
        }
    }

    /**
     * Models loaded inside keep that client's connection, so a stale read stays
     * stale instead of being repaired by shared PHP state.
     */
    protected function as(string $actor, Closure $callback): mixed
    {
        $previous = config(ConfigKey::Connection->value);

        config()->set(ConfigKey::Connection->value, "waitlist_{$actor}");

        try {
            return $callback();
        } finally {
            config()->set(ConfigKey::Connection->value, $previous);
        }
    }
}
