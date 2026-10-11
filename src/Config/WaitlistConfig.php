<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Config;

use Illuminate\Support\Carbon;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Exports\CsvExporter;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;

/**
 * config/waitlist.php, typed and checked where it is used.
 *
 * Each accessor reads the config anew and checks only what it returns, so a
 * mistake in one setting stops the feature that needs it, and no other. The
 * settings of a group also come as one object, which check() reads; a step
 * reads only the ones it uses.
 *
 * @internal
 */
final class WaitlistConfig
{
    /**
     * @return class-string<WaitlistEntry>
     */
    public static function entryModel(): string
    {
        return self::reader()->subclass(ConfigKey::Model, WaitlistEntry::class);
    }

    /**
     * @return class-string<WaitlistSubscription>
     */
    public static function subscriptionModel(): string
    {
        return self::reader()->subclass(ConfigKey::SubscriptionModel, WaitlistSubscription::class);
    }

    /**
     * @return class-string<WaitlistConsent>
     */
    public static function consentModel(): string
    {
        return self::reader()->subclass(ConfigKey::ConsentModel, WaitlistConsent::class);
    }

    /**
     * @return class-string<WaitlistActivity>
     */
    public static function activityModel(): string
    {
        return self::reader()->subclass(ConfigKey::ActivityModel, WaitlistActivity::class);
    }

    public static function connection(): ?string
    {
        return self::reader()->stringOrNull(ConfigKey::Connection);
    }

    public static function defaultList(): string
    {
        return self::reader()->string(ConfigKey::DefaultList);
    }

    public static function requireWordingHash(): bool
    {
        return self::reader()->boolean(ConfigKey::WordingRequireHash);
    }

    /**
     * Whether a list asks for confirmation unless its definition says.
     */
    public static function doubleOptIn(): bool
    {
        return self::reader()->boolean(ConfigKey::DoubleOptIn);
    }

    /**
     * Minutes a confirm link works; null never expires.
     */
    public static function confirmTokenTtl(): ?int
    {
        return self::reader()->lifetimeMinutesOrNull(ConfigKey::ConfirmTokenTtl, min: 1);
    }

    /**
     * When a confirm link issued at $from stops working; null when it never does.
     */
    public static function confirmTokenExpiresAt(Carbon $from): ?Carbon
    {
        $minutes = self::confirmTokenTtl();

        return $minutes === null ? null : $from->copy()->addMinutes($minutes);
    }

    /**
     * Minutes between two confirmation requests of one cycle; null none.
     */
    public static function resendCooldown(): ?int
    {
        return self::reader()->lookbackMinutesOrNull(ConfigKey::ResendCooldown, min: 0);
    }

    /**
     * Confirmation requests per cycle; null no cap.
     */
    public static function maxConfirmations(): ?int
    {
        return self::reader()->integerOrNull(ConfigKey::MaxConfirmations, min: 1);
    }

    /**
     * Requests per address and day for a project's unconfirmed lists; null no cap.
     */
    public static function maxPendingPerAddress(): ?int
    {
        return self::reader()->integerOrNull(ConfigKey::MaxPendingPerAddress, min: 1);
    }

    /**
     * Minutes a manage link works. Never unlimited: it stands in for a login.
     */
    public static function manageTokenTtl(): int
    {
        return self::reader()->lifetimeMinutes(ConfigKey::ManageTokenTtl, min: 1);
    }

    /**
     * When a manage link issued at $from stops working.
     */
    public static function manageTokenExpiresAt(Carbon $from): Carbon
    {
        $minutes = self::manageTokenTtl();

        return $from->copy()->addMinutes($minutes);
    }

    /**
     * Minutes between two manage link mails to one address; null none.
     */
    public static function manageRequestCooldown(): ?int
    {
        return self::reader()->lookbackMinutesOrNull(ConfigKey::ManageRequestCooldown, min: 0);
    }

    public static function privacy(): PrivacyConfig
    {
        return new PrivacyConfig(
            storeIp: self::storeIp(),
            storeUserAgent: self::storeUserAgent(),
        );
    }

    public static function storeIp(): bool
    {
        return self::reader()->boolean(ConfigKey::StoreIp);
    }

    public static function storeUserAgent(): bool
    {
        return self::reader()->boolean(ConfigKey::StoreUserAgent);
    }

    /**
     * The three periods together, for a check. A negative period would move
     * the cutoff into the future and erase what was just collected.
     */
    public static function retention(): RetentionConfig
    {
        return new RetentionConfig(
            pendingDays: self::pendingDays(),
            unsubscribedDays: self::unsubscribedDays(),
            requestMetadataDays: self::requestMetadataDays(),
        );
    }

    /**
     * Days an address may stay unconfirmed; null keeps it.
     */
    public static function pendingDays(): ?int
    {
        return self::reader()->lookbackDaysOrNull(ConfigKey::RetentionPendingDays, min: 0);
    }

    /**
     * Days an address is kept after it left; null keeps it.
     */
    public static function unsubscribedDays(): ?int
    {
        return self::reader()->lookbackDaysOrNull(ConfigKey::RetentionUnsubscribedDays, min: 0);
    }

    /**
     * Days the IP and user agent stay on the log; null keeps them.
     */
    public static function requestMetadataDays(): ?int
    {
        return self::reader()->lookbackDaysOrNull(ConfigKey::RetentionRequestMetadataDays, min: 0);
    }

    public static function routesEnabled(): bool
    {
        return self::reader()->boolean(ConfigKey::RoutesEnabled);
    }

    public static function routePrefix(): string
    {
        return self::reader()->routePrefix(ConfigKey::RoutesPrefix);
    }

    public static function routeName(): string
    {
        return self::reader()->string(ConfigKey::RoutesName, allowEmpty: true);
    }

    /**
     * @return list<string>
     */
    public static function routeMiddleware(): array
    {
        return self::reader()->middleware(ConfigKey::RoutesMiddleware);
    }

    /**
     * @return list<string>
     */
    public static function signupMiddleware(): array
    {
        return self::reader()->middleware(ConfigKey::SignupMiddleware);
    }

    /**
     * @return list<string>
     */
    public static function linksMiddleware(): array
    {
        return self::reader()->middleware(ConfigKey::LinksMiddleware);
    }

    public static function signupPerMinute(): int
    {
        return self::reader()->integer(ConfigKey::SignupPerMinute, min: 1);
    }

    public static function linkPerMinute(): int
    {
        return self::reader()->integer(ConfigKey::LinkPerMinute, min: 1);
    }

    public static function linksPerIpPerMinute(): int
    {
        return self::reader()->integer(ConfigKey::LinksPerIpPerMinute, min: 1);
    }

    public static function callerSignupPerMinute(): int
    {
        return self::reader()->integer(ConfigKey::CallerSignupPerMinute, min: 1);
    }

    /**
     * @return list<string|null> null for the default guard
     */
    public static function guards(): array
    {
        return self::reader()->guards(ConfigKey::AuthenticationGuards);
    }

    public static function authenticationRequired(): bool
    {
        return self::reader()->boolean(ConfigKey::AuthenticationRequired);
    }

    public static function clientIpHeader(): ?string
    {
        return self::reader()->stringOrNull(ConfigKey::ClientIpHeader);
    }

    public static function export(): ExportConfig
    {
        return new ExportConfig(
            spreadsheetSafe: self::exportSpreadsheetSafe(),
            columns: self::exportColumns(),
        );
    }

    public static function exportSpreadsheetSafe(): bool
    {
        return self::reader()->boolean(ConfigKey::ExportSpreadsheetSafe);
    }

    /**
     * @return list<string>
     */
    public static function exportColumns(): array
    {
        return self::reader()->columns(ConfigKey::ExportColumns, CsvExporter::EXPORTABLE);
    }

    /**
     * Every setting that does not read, each once and in the order of the
     * settings. The messages name the key and what it must be, never a value.
     *
     * @return list<InvalidConfigurationException>
     */
    public static function problems(): array
    {
        $checks = [
            self::entryModel(...),
            self::subscriptionModel(...),
            self::consentModel(...),
            self::activityModel(...),
            self::connection(...),
            self::defaultList(...),
            self::requireWordingHash(...),
            self::doubleOptIn(...),
            self::confirmTokenTtl(...),
            self::resendCooldown(...),
            self::maxConfirmations(...),
            self::maxPendingPerAddress(...),
            self::manageTokenTtl(...),
            self::manageRequestCooldown(...),
            self::storeIp(...),
            self::storeUserAgent(...),
            self::pendingDays(...),
            self::unsubscribedDays(...),
            self::requestMetadataDays(...),
            self::routesEnabled(...),
            self::routePrefix(...),
            self::routeName(...),
            self::routeMiddleware(...),
            self::signupMiddleware(...),
            self::linksMiddleware(...),
            self::signupPerMinute(...),
            self::linkPerMinute(...),
            self::linksPerIpPerMinute(...),
            self::callerSignupPerMinute(...),
            self::guards(...),
            self::authenticationRequired(...),
            self::clientIpHeader(...),
            self::exportSpreadsheetSafe(...),
            self::exportColumns(...),
        ];

        $problems = [];

        foreach ($checks as $check) {
            try {
                $check();
            } catch (InvalidConfigurationException $exception) {
                // A config that is no array fails every reader alike.
                $problems[$exception->getMessage()] = $exception;
            }
        }

        return array_values($problems);
    }

    /**
     * A new on every call, so each accessor sees the current config.
     */
    private static function reader(): ConfigReader
    {
        return ConfigReader::of(config('waitlist'));
    }
}
