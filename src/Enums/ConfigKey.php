<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Enums;

use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;
use Taldres\Waitlist\Support\DefaultConfirmationUrlGenerator;
use Taldres\Waitlist\Support\DefaultEmailNormalizer;
use Taldres\Waitlist\Support\DefaultProjectResolver;
use Taldres\Waitlist\Support\DefinedProjectCatalog;
use Taldres\Waitlist\Support\NullSpamProtector;

/**
 * Every key of config/waitlist.php, with the package default. Read them with
 * Support\Setting, passing the value: Setting::enabled(ConfigKey::RoutesEnabled->value).
 */
enum ConfigKey: string
{
    case Model = 'waitlist.model';
    case SubscriptionModel = 'waitlist.subscription_model';
    case ConsentModel = 'waitlist.consent_model';
    case ActivityModel = 'waitlist.activity_model';
    case Connection = 'waitlist.connection';
    case DefaultList = 'waitlist.default_list';
    case Catalog = 'waitlist.catalog';
    case WordingRequireHash = 'waitlist.wording.require_hash';

    case DoubleOptIn = 'waitlist.double_opt_in.enabled';
    case ConfirmTokenTtl = 'waitlist.double_opt_in.token_ttl';
    case ResendCooldown = 'waitlist.double_opt_in.resend_cooldown';
    case MaxConfirmations = 'waitlist.double_opt_in.max_confirmations';
    case MaxPendingPerAddress = 'waitlist.double_opt_in.max_pending_per_address';
    case InvalidateConfirmToken = 'waitlist.double_opt_in.invalidate_confirm_token_after_confirmation';

    case ManageTokenTtl = 'waitlist.manage.token_ttl';
    case ManageRequestCooldown = 'waitlist.manage.request_cooldown';

    case StoreIp = 'waitlist.privacy.store_ip';
    case StoreUserAgent = 'waitlist.privacy.store_user_agent';

    case ReportingTimezone = 'waitlist.reporting.timezone';

    case RetentionPendingDays = 'waitlist.retention.pending_days';
    case RetentionUnsubscribedDays = 'waitlist.retention.unsubscribed_days';
    case RetentionRequestMetadataDays = 'waitlist.retention.request_metadata_days';
    case RetentionSchedule = 'waitlist.retention.schedule';

    case RoutesEnabled = 'waitlist.routes.enabled';
    case RoutesPrefix = 'waitlist.routes.prefix';
    case RoutesName = 'waitlist.routes.name';
    case RoutesMiddleware = 'waitlist.routes.middleware';
    case SignupMiddleware = 'waitlist.routes.group_middleware.signup';
    case LinksMiddleware = 'waitlist.routes.group_middleware.links';
    case SignupLimiter = 'waitlist.routes.limiters.signup';
    case LinksLimiter = 'waitlist.routes.limiters.links';
    case SignupPerMinute = 'waitlist.routes.rate_limits.signup_per_minute';
    case LinkPerMinute = 'waitlist.routes.rate_limits.link_per_minute';
    case LinksPerIpPerMinute = 'waitlist.routes.rate_limits.links_per_ip_per_minute';
    case CallerSignupPerMinute = 'waitlist.routes.rate_limits.caller_signup_per_minute';

    case UrlGenerator = 'waitlist.url_generator';
    case ProjectResolver = 'waitlist.project_resolver';
    case AuthenticationGuards = 'waitlist.authentication.guards';
    case AuthenticationRequired = 'waitlist.authentication.required';
    case ClientIpHeader = 'waitlist.authentication.client_ip_header';
    case EmailNormalizer = 'waitlist.email_normalizer';
    case SpamProtector = 'waitlist.spam_protector';

    case ExportSpreadsheetSafe = 'waitlist.export.spreadsheet_safe';
    case ExportColumns = 'waitlist.export.columns';

    /**
     * The same as config/waitlist.php without environment variables; a test
     * keeps the two in step.
     */
    public function default(): mixed
    {
        return match ($this) {
            self::Model => WaitlistEntry::class,
            self::SubscriptionModel => WaitlistSubscription::class,
            self::ConsentModel => WaitlistConsent::class,
            self::ActivityModel => WaitlistActivity::class,
            self::Connection, self::ReportingTimezone, self::ClientIpHeader => null,
            self::DefaultList => 'default',
            self::Catalog => DefinedProjectCatalog::class,
            self::WordingRequireHash, self::InvalidateConfirmToken, self::StoreIp, self::StoreUserAgent, self::RoutesEnabled, self::AuthenticationRequired => false,
            self::DoubleOptIn, self::ExportSpreadsheetSafe => true,
            self::ConfirmTokenTtl => 60 * 24 * 7,
            self::ResendCooldown, self::MaxConfirmations, self::MaxPendingPerAddress, self::ManageRequestCooldown => 5,
            self::ManageTokenTtl => 60,
            self::RetentionPendingDays, self::RetentionRequestMetadataDays => 30,
            self::RetentionUnsubscribedDays => 1095,
            self::RetentionSchedule => '15 3 * * *',
            self::RoutesPrefix => 'waitlist',
            self::RoutesName => 'waitlist.',
            self::RoutesMiddleware => ['api'],
            self::SignupMiddleware, self::LinksMiddleware => [],
            self::SignupLimiter => 'waitlist',
            self::LinksLimiter => 'waitlist-links',
            self::SignupPerMinute, self::LinkPerMinute => 10,
            self::LinksPerIpPerMinute => 600,
            self::CallerSignupPerMinute => 120,
            self::UrlGenerator => DefaultConfirmationUrlGenerator::class,
            self::ProjectResolver => DefaultProjectResolver::class,
            self::AuthenticationGuards => [null],
            self::EmailNormalizer => DefaultEmailNormalizer::class,
            self::SpamProtector => NullSpamProtector::class,
            self::ExportColumns => ['id', 'list', 'email', 'status', 'purposes', 'confirmed_at', 'unsubscribed_at', 'metadata', 'created_at'],
        };
    }
}
