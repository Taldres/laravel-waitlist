<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Enums;

/**
 * Every key of config/waitlist.php. The defaults live in that file alone;
 * read the values through Config\WaitlistConfig, which checks them.
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

    case ManageTokenTtl = 'waitlist.manage.token_ttl';
    case ManageRequestCooldown = 'waitlist.manage.request_cooldown';

    case StoreIp = 'waitlist.privacy.store_ip';
    case StoreUserAgent = 'waitlist.privacy.store_user_agent';

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
}
