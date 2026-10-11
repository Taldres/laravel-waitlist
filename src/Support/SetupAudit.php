<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Contracts\ConfirmationUrlGenerator;
use Taldres\Waitlist\Contracts\EmailNormalizer;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Contracts\SpamProtector;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Http\RouteRegistration;
use Taldres\Waitlist\Models\WaitlistWording;
use Taldres\Waitlist\WaitlistManager;
use Throwable;

/**
 * What goes wrong without a sound in a setup: a setting the package does not
 * read, such as a typo, a binding the container cannot resolve, and a table or
 * column its migrations create that the database lacks.
 *
 * @internal
 */
final class SetupAudit
{
    private const array CONTRACTS = [
        ProjectCatalog::class,
        ConfirmationUrlGenerator::class,
        ProjectResolver::class,
        EmailNormalizer::class,
        SpamProtector::class,
    ];

    /**
     * The columns the migrations of this version create, by model. A test keeps
     * the lists in step with the migrations.
     */
    public const array COLUMNS = [
        'entry' => ['id', 'project', 'list', 'email', 'email_hash', 'status', 'latest_subscription_id', 'unsubscribe_token_hash', 'unsubscribe_token', 'manage_token_hash', 'manage_token_expires_at', 'manage_link_sent_at', 'metadata', 'created_at', 'updated_at'],
        'subscription' => ['id', 'waitlist_entry_id', 'sequence', 'active', 'confirm_token_hash', 'confirm_token_expires_at', 'started_at', 'confirmation_sent_at', 'confirmation_count', 'confirmation_outcome', 'confirmed_at', 'ended_at', 'end_reason', 'created_at', 'updated_at'],
        'consent' => ['id', 'waitlist_subscription_id', 'purpose', 'version', 'locale', 'text', 'required', 'granted_at', 'withdrawn_at', 'active'],
        'activity' => ['id', 'waitlist_entry_id', 'waitlist_subscription_id', 'project', 'list', 'type', 'previous_status', 'purpose', 'reference', 'ip', 'user_agent', 'occurred_at', 'occurred_on'],
        'wording' => ['id', 'project', 'purpose', 'version', 'locale', 'text', 'registered_by', 'registered_at', 'retired_at'],
    ];

    /**
     * The cron expression of the application's scheduled task that runs
     * waitlist:prune, or null when none does. A cron job outside Laravel's
     * scheduler is not seen.
     */
    public static function scheduledPrune(): ?string
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, 'waitlist:prune')) {
                return $event->expression;
            }
        }

        return null;
    }

    /**
     * Whether a guest is kept from the signup: by authentication.required with
     * the default gate, or by auth middleware (any AuthenticatesRequests) in
     * the signup group, such as auth:sanctum. A gate of the app's own decides
     * for itself.
     *
     * @throws InvalidConfigurationException
     */
    public static function signupRequiresLogin(): bool
    {
        if (WaitlistConfig::authenticationRequired()) {
            return true;
        }

        // A console run builds no HTTP kernel, which is what hands the aliases to the router.
        /** @var HttpKernel $kernel */
        $kernel = app(HttpKernel::class);
        $aliases = [...$kernel instanceof Kernel ? $kernel->getMiddlewareAliases() : [], ...Route::getMiddleware()];

        foreach (WaitlistConfig::signupMiddleware() as $middleware) {
            $name = Str::before($middleware, ':');
            $class = $aliases[$name] ?? $name;

            if (is_string($class) && is_a($class, AuthenticatesRequests::class, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The hint waitlist:check and waitlist:privacy give while scheduledPrune()
     * finds nothing.
     */
    public static function unscheduledPruneHint(): ?string
    {
        return self::scheduledPrune() === null
            ? "No scheduled task runs waitlist:prune, so the retention periods are not applied. Add Schedule::command('waitlist:prune')->daily() to routes/console.php, or ignore this if you run it another way."
            : null;
    }

    /**
     * Where the package routes as registered now differ from what the config
     * says: a group left out because a setting did not read when they were
     * registered, or a route cache built that way, or built while the routes
     * were on. Routes are registered on boot and by the route cache, so
     * routes.enabled does not switch a cached route off.
     *
     * @return list<string>
     */
    public static function routeProblems(): array
    {
        try {
            $enabled = WaitlistConfig::routesEnabled();
            $prefix = WaitlistConfig::routeName();
        } catch (InvalidConfigurationException) {
            return [];
        }

        $problems = [];

        foreach (RouteRegistration::GROUPS as $group => $names) {
            $registered = count(array_filter($names, fn (string $name): bool => Route::has($prefix.$name)));

            if ($enabled && $registered < count($names)) {
                $problems[] = "The {$group} routes are not registered although routes.enabled is on: a setting did not read when they were registered, or the route cache was built without them. Fix the setting, then run php artisan route:cache again, or route:clear.";
            }

            if (! $enabled && $registered > 0) {
                $problems[] = "routes.enabled is off, but the {$group} routes are still registered, as from a route cache built while it was on. Run php artisan route:clear, or route:cache again.";
            }
        }

        return $problems;
    }

    /**
     * Each contract the container cannot resolve, or resolves to something
     * that does not implement it, as a binding names a class in plain PHP that
     * no setting checks. The spam protector is the one the endpoints use.
     *
     * @return list<string>
     */
    public static function bindingProblems(): array
    {
        $problems = [];

        foreach (self::CONTRACTS as $contract) {
            try {
                $resolved = self::resolve($contract);
            } catch (Throwable $exception) {
                $problems[] = "{$contract} cannot be resolved: ".$exception->getMessage();

                continue;
            }

            if (! $resolved instanceof $contract) {
                $problems[] = "{$contract} resolves to ".get_debug_type($resolved).', which does not implement it.';
            }
        }

        return $problems;
    }

    /**
     * Whatever the app's binding hands out, which nothing typed has checked yet.
     */
    private static function resolve(string $contract): mixed
    {
        return $contract === SpamProtector::class ? app(WaitlistManager::class)->spamProtector() : app($contract);
    }

    /**
     * Settings in config/waitlist.php that no key of this version names, as the
     * first path of each that is unknown: a whole group counts once.
     *
     * @return list<string>
     */
    public static function unknownSettings(): array
    {
        $config = config('waitlist');

        if (! is_array($config)) {
            return [];
        }

        $known = [];
        $groups = [];

        foreach (ConfigKey::cases() as $key) {
            $known[$key->value] = true;
            $parts = explode('.', $key->value);

            for ($length = 1; $length <= count($parts); $length++) {
                $groups[implode('.', array_slice($parts, 0, $length))] = true;
            }
        }

        $unknown = [];
        self::walk($config, 'waitlist', $known, $groups, $unknown);

        return $unknown;
    }

    /**
     * The tables and columns of this version that the database lacks, by table
     * name: null for a table that does not exist.
     *
     * @return array<string, list<string>|null>
     */
    public static function missingSchema(): array
    {
        $schema = Schema::connection(WaitlistConfig::connection());
        $missing = [];

        foreach (self::tables() as $kind => $table) {
            if (! $schema->hasTable($table)) {
                $missing[$table] = null;

                continue;
            }

            $absent = array_values(array_diff(self::COLUMNS[$kind], $schema->getColumnListing($table)));

            if ($absent !== []) {
                $missing[$table] = $absent;
            }
        }

        return $missing;
    }

    /**
     * @return array<string, string> model kind => table
     */
    private static function tables(): array
    {
        /** @var array<string, class-string<Model>> $models */
        $models = [
            'entry' => WaitlistConfig::entryModel(),
            'subscription' => WaitlistConfig::subscriptionModel(),
            'consent' => WaitlistConfig::consentModel(),
            'activity' => WaitlistConfig::activityModel(),
            'wording' => WaitlistWording::class,
        ];

        return array_map(fn (string $model): string => (new $model)->getTable(), $models);
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  array<string, true>  $known
     * @param  array<string, true>  $groups
     * @param  list<string>  $unknown
     */
    private static function walk(array $node, string $path, array $known, array $groups, array &$unknown): void
    {
        foreach ($node as $key => $value) {
            $child = "{$path}.{$key}";

            if (isset($known[$child])) {
                continue;
            }

            if (! isset($groups[$child])) {
                $unknown[] = $child;

                continue;
            }

            if (is_array($value)) {
                self::walk($value, $child, $known, $groups, $unknown);
            }
        }
    }
}
