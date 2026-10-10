<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Config;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Taldres\Waitlist\Contracts\ConfirmationUrlGenerator;
use Taldres\Waitlist\Contracts\EmailNormalizer;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Contracts\SpamProtector;
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
        return ConfigReader::of(config('waitlist'))->subclass(ConfigKey::Model, WaitlistEntry::class);
    }

    /**
     * @return class-string<WaitlistSubscription>
     */
    public static function subscriptionModel(): string
    {
        return ConfigReader::of(config('waitlist'))->subclass(ConfigKey::SubscriptionModel, WaitlistSubscription::class);
    }

    /**
     * @return class-string<WaitlistConsent>
     */
    public static function consentModel(): string
    {
        return ConfigReader::of(config('waitlist'))->subclass(ConfigKey::ConsentModel, WaitlistConsent::class);
    }

    /**
     * @return class-string<WaitlistActivity>
     */
    public static function activityModel(): string
    {
        return ConfigReader::of(config('waitlist'))->subclass(ConfigKey::ActivityModel, WaitlistActivity::class);
    }

    public static function connection(): ?string
    {
        return ConfigReader::of(config('waitlist'))->stringOrNull(ConfigKey::Connection);
    }

    public static function defaultList(): string
    {
        return ConfigReader::of(config('waitlist'))->string(ConfigKey::DefaultList);
    }

    public static function requireWordingHash(): bool
    {
        return ConfigReader::of(config('waitlist'))->boolean(ConfigKey::WordingRequireHash);
    }

    /**
     * Whether a list asks for confirmation unless its definition says.
     */
    public static function doubleOptIn(): bool
    {
        return ConfigReader::of(config('waitlist'))->boolean(ConfigKey::DoubleOptIn);
    }

    /**
     * Every setting of the confirmation, for a check. A step reads the ones
     * it needs, so a mistake in one stops only the steps that use it.
     */
    public static function confirmation(): ConfirmationConfig
    {
        return new ConfirmationConfig(
            tokenTtl: self::confirmTokenTtl(),
            resendCooldown: self::resendCooldown(),
            maxConfirmations: self::maxConfirmations(),
            maxPendingPerAddress: self::maxPendingPerAddress(),
        );
    }

    /**
     * Minutes a confirm link works; null never expires.
     */
    public static function confirmTokenTtl(): ?int
    {
        return ConfigReader::of(config('waitlist'))->lifetimeMinutesOrNull(ConfigKey::ConfirmTokenTtl, min: 1);
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
        return ConfigReader::of(config('waitlist'))->lookbackMinutesOrNull(ConfigKey::ResendCooldown, min: 0);
    }

    /**
     * Confirmation requests per cycle; null no cap.
     */
    public static function maxConfirmations(): ?int
    {
        return ConfigReader::of(config('waitlist'))->integerOrNull(ConfigKey::MaxConfirmations, min: 1);
    }

    /**
     * Requests per address and day for a project's unconfirmed lists; null no cap.
     */
    public static function maxPendingPerAddress(): ?int
    {
        return ConfigReader::of(config('waitlist'))->integerOrNull(ConfigKey::MaxPendingPerAddress, min: 1);
    }

    /**
     * Whether a confirm link stops working once used.
     */
    public static function singleUseConfirmTokens(): bool
    {
        return ConfigReader::of(config('waitlist'))->boolean(ConfigKey::InvalidateConfirmToken);
    }

    /**
     * Every setting of the preference page, for a check; see confirmation().
     */
    public static function manage(): ManageConfig
    {
        return new ManageConfig(
            tokenTtl: self::manageTokenTtl(),
            requestCooldown: self::manageRequestCooldown(),
        );
    }

    /**
     * Minutes a manage link works. Never unlimited: it stands in for a login.
     */
    public static function manageTokenTtl(): int
    {
        return ConfigReader::of(config('waitlist'))->lifetimeMinutes(ConfigKey::ManageTokenTtl, min: 1);
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
        return ConfigReader::of(config('waitlist'))->lookbackMinutesOrNull(ConfigKey::ManageRequestCooldown, min: 0);
    }

    public static function privacy(): PrivacyConfig
    {
        $config = ConfigReader::of(config('waitlist'));

        return new PrivacyConfig(
            storeIp: $config->boolean(ConfigKey::StoreIp),
            storeUserAgent: $config->boolean(ConfigKey::StoreUserAgent),
        );
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
        return ConfigReader::of(config('waitlist'))->lookbackDaysOrNull(ConfigKey::RetentionPendingDays, min: 0);
    }

    /**
     * Days an address is kept after it left; null keeps it.
     */
    public static function unsubscribedDays(): ?int
    {
        return ConfigReader::of(config('waitlist'))->lookbackDaysOrNull(ConfigKey::RetentionUnsubscribedDays, min: 0);
    }

    /**
     * Days the IP and user agent stay on the log; null keeps them.
     */
    public static function requestMetadataDays(): ?int
    {
        return ConfigReader::of(config('waitlist'))->lookbackDaysOrNull(ConfigKey::RetentionRequestMetadataDays, min: 0);
    }

    /**
     * When waitlist:prune runs; null schedules nothing.
     */
    public static function pruneSchedule(): ?string
    {
        return ConfigReader::of(config('waitlist'))->cronOrNull(ConfigKey::RetentionSchedule);
    }

    public static function routesEnabled(): bool
    {
        return ConfigReader::of(config('waitlist'))->boolean(ConfigKey::RoutesEnabled);
    }

    public static function routePrefix(): string
    {
        return ConfigReader::of(config('waitlist'))->routePrefix(ConfigKey::RoutesPrefix);
    }

    public static function routeName(): string
    {
        return ConfigReader::of(config('waitlist'))->string(ConfigKey::RoutesName, allowEmpty: true);
    }

    /**
     * @return list<string>
     */
    public static function routeMiddleware(): array
    {
        return ConfigReader::of(config('waitlist'))->middleware(ConfigKey::RoutesMiddleware);
    }

    /**
     * @return list<string>
     */
    public static function signupMiddleware(): array
    {
        return ConfigReader::of(config('waitlist'))->middleware(ConfigKey::SignupMiddleware);
    }

    /**
     * @return list<string>
     */
    public static function linksMiddleware(): array
    {
        return ConfigReader::of(config('waitlist'))->middleware(ConfigKey::LinksMiddleware);
    }

    /**
     * The limiter of the signup group; null turns its limit off.
     */
    public static function signupLimiter(): ?string
    {
        return ConfigReader::of(config('waitlist'))->stringOrNull(ConfigKey::SignupLimiter);
    }

    /**
     * The limiter of the links group; null turns its limit off.
     */
    public static function linksLimiter(): ?string
    {
        return ConfigReader::of(config('waitlist'))->stringOrNull(ConfigKey::LinksLimiter);
    }

    public static function signupPerMinute(): int
    {
        return ConfigReader::of(config('waitlist'))->integer(ConfigKey::SignupPerMinute, min: 1);
    }

    public static function linkPerMinute(): int
    {
        return ConfigReader::of(config('waitlist'))->integer(ConfigKey::LinkPerMinute, min: 1);
    }

    public static function linksPerIpPerMinute(): int
    {
        return ConfigReader::of(config('waitlist'))->integer(ConfigKey::LinksPerIpPerMinute, min: 1);
    }

    public static function callerSignupPerMinute(): int
    {
        return ConfigReader::of(config('waitlist'))->integer(ConfigKey::CallerSignupPerMinute, min: 1);
    }

    /**
     * @return list<string|null> null for the default guard
     */
    public static function guards(): array
    {
        return ConfigReader::of(config('waitlist'))->guards(ConfigKey::AuthenticationGuards);
    }

    public static function authenticationRequired(): bool
    {
        return ConfigReader::of(config('waitlist'))->boolean(ConfigKey::AuthenticationRequired);
    }

    public static function clientIpHeader(): ?string
    {
        return ConfigReader::of(config('waitlist'))->stringOrNull(ConfigKey::ClientIpHeader);
    }

    /**
     * @return class-string<ProjectCatalog>
     */
    public static function catalog(): string
    {
        return ConfigReader::of(config('waitlist'))->implementation(ConfigKey::Catalog, ProjectCatalog::class);
    }

    /**
     * @return class-string<ConfirmationUrlGenerator>
     */
    public static function urlGenerator(): string
    {
        return ConfigReader::of(config('waitlist'))->implementation(ConfigKey::UrlGenerator, ConfirmationUrlGenerator::class);
    }

    /**
     * @return class-string<ProjectResolver>
     */
    public static function projectResolver(): string
    {
        return ConfigReader::of(config('waitlist'))->implementation(ConfigKey::ProjectResolver, ProjectResolver::class);
    }

    /**
     * @return class-string<EmailNormalizer>
     */
    public static function emailNormalizer(): string
    {
        return ConfigReader::of(config('waitlist'))->implementation(ConfigKey::EmailNormalizer, EmailNormalizer::class);
    }

    /**
     * @return class-string<SpamProtector>
     */
    public static function spamProtector(): string
    {
        return ConfigReader::of(config('waitlist'))->implementation(ConfigKey::SpamProtector, SpamProtector::class);
    }

    public static function export(): ExportConfig
    {
        $config = ConfigReader::of(config('waitlist'));

        return new ExportConfig(
            spreadsheetSafe: $config->boolean(ConfigKey::ExportSpreadsheetSafe),
            columns: $config->columns(ConfigKey::ExportColumns, CsvExporter::EXPORTABLE),
        );
    }

    /**
     * Reads every setting, for a check before going live.
     */
    public static function check(): void
    {
        self::entryModel();
        self::subscriptionModel();
        self::consentModel();
        self::activityModel();
        self::connection();
        self::defaultList();
        self::requireWordingHash();
        self::doubleOptIn();
        self::confirmation();
        self::singleUseConfirmTokens();
        self::manage();
        self::privacy();
        self::retention();
        self::pruneSchedule();
        self::routesEnabled();
        self::routePrefix();
        self::routeName();
        self::routeMiddleware();
        self::signupMiddleware();
        self::linksMiddleware();
        self::definedLimiter(ConfigKey::SignupLimiter, self::signupLimiter());
        self::definedLimiter(ConfigKey::LinksLimiter, self::linksLimiter());
        self::signupPerMinute();
        self::linkPerMinute();
        self::linksPerIpPerMinute();
        self::callerSignupPerMinute();
        self::guards();
        self::authenticationRequired();
        self::clientIpHeader();
        self::catalog();
        self::urlGenerator();
        self::projectResolver();
        self::emailNormalizer();
        self::spamProtector();
        self::export();
    }

    /**
     * Laravel meets a name nothing defines only when a request arrives. A
     * number is no name: throttle:60 is a limit per minute. Asked here only, as
     * an app may define its limiters after the package registers its routes.
     */
    private static function definedLimiter(ConfigKey $key, ?string $limiter): void
    {
        if ($limiter !== null && ! is_numeric($limiter) && RateLimiter::limiter($limiter) === null) {
            throw new InvalidConfigurationException("The {$key->value} config must name a limiter defined with RateLimiter::for(), or null, got one that is not defined.");
        }
    }
}
