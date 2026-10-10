<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Models\WaitlistWording;

/**
 * What an app that upgrades the package gets wrong without a sound: settings
 * the package no longer reads, and tables or columns its migrations created
 * after the app ran them.
 *
 * @internal
 */
final class SetupAudit
{
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
