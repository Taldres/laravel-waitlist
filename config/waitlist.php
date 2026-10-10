<?php

use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;
use Taldres\Waitlist\Support\DefaultConfirmationUrlGenerator;
use Taldres\Waitlist\Support\DefaultEmailNormalizer;
use Taldres\Waitlist\Support\DefaultProjectResolver;
use Taldres\Waitlist\Support\DefinedProjectCatalog;
use Taldres\Waitlist\Support\NullSpamProtector;
use Taldres\Waitlist\WaitlistServiceProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Swap any of these for your own subclass. The entry is the address, the
    | subscription is one opt-in cycle, a consent is agreement to one purpose
    | within it, and activity is the append-only log behind reporting.
    |
    */

    'model' => WaitlistEntry::class,
    'subscription_model' => WaitlistSubscription::class,
    'consent_model' => WaitlistConsent::class,
    'activity_model' => WaitlistActivity::class,

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | Null uses the application default. Set this to keep waitlist tables on a
    | separate connection.
    |
    */

    'connection' => env('WAITLIST_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Default List
    |--------------------------------------------------------------------------
    |
    | The list the HTTP endpoints use when a request names none.
    | Waitlist::for() always takes a list.
    |
    */

    'default_list' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Catalog
    |--------------------------------------------------------------------------
    |
    | Projects, their lists, the wording of their purposes, the fields a signup
    | may carry and their frontend URLs come from a ProjectCatalog. The default
    | reads what you describe with Waitlist::define() in a service provider,
    | see docs/projects.md. StoredWordingCatalog reads the wording registered
    | with waitlist:wording instead, and the rest from the definitions. Bind
    | your own to keep it all in a database; your catalog then decides double
    | opt-in per list (ListPolicy::$doubleOptIn) instead of
    | double_opt_in.enabled.
    |
    */

    'catalog' => DefinedProjectCatalog::class,

    /*
    | A form that renders its own copy of the wording, e.g. from a CMS, can
    | post back the hash of what it showed ({version, locale, hash}); a hash
    | that does not match the registered text is refused. require_hash makes
    | the hash mandatory for every choice a form posts.
    */
    'wording' => [
        'require_hash' => env('WAITLIST_REQUIRE_WORDING_HASH', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Double Opt-in
    |--------------------------------------------------------------------------
    |
    | When enabled, a new cycle starts as "pending" and must be confirmed via
    | token. Override it per list with ->doubleOptIn() in the list's
    | definition. The token TTL is in minutes, at least 1; set it to null to
    | never expire. A link may not expire after 2038-01-19 03:14:07 UTC, where
    | a MySQL or MariaDB timestamp column ends, so a longer TTL is refused.
    |
    */

    'double_opt_in' => [
        'enabled' => env('WAITLIST_DOUBLE_OPT_IN', true),
        'token_ttl' => 60 * 24 * 7,
        /*
        | Minimum minutes between two confirmation requests for the same cycle
        | (0 or null for none; at most the minutes since 1970),
        | and how many may go out in total. Subscribing again while a cycle is
        | pending counts as a resend request, so these two caps stop a public
        | form from mailing the same person repeatedly. Past the cap, one more
        | goes out once the last link has expired, so nobody can lock an
        | address out of confirming.
        */
        'resend_cooldown' => env('WAITLIST_RESEND_COOLDOWN', 5),
        'max_confirmations' => env('WAITLIST_MAX_CONFIRMATIONS', 5),

        /*
        | How many unconfirmed lists of a project one address may get a
        | confirmation request for within a day. Beyond that, a signup starts
        | its cycle without one, sent by a later signup or resend. This stops a
        | "*" list from mailing an address once per made-up list name. Null for
        | no limit.
        */
        'max_pending_per_address' => env('WAITLIST_MAX_PENDING_PER_ADDRESS', 5),

        /*
        | By default the confirm link keeps working after confirmation and only
        | reports the status. Enable this to make it single-use: the token is
        | cleared on confirmation, and re-clicking raises an invalid-token error.
        */
        'invalidate_confirm_token_after_confirmation' => env('WAITLIST_INVALIDATE_CONFIRM_TOKEN', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Preference Page
    |--------------------------------------------------------------------------
    |
    | Every mail carries an unsubscribe token, which can only remove. The
    | preference page (data, purposes, erasure) needs a manage token instead:
    | requested with the unsubscribe token or the address, mailed to the
    | address via ManageLinkRequested, and valid for token_ttl minutes, at
    | least 1 and, like the confirm link, not past 2038-01-19. A new link
    | replaces the last one. Requested by address, it only goes to an address
    | that confirmed at least once. request_cooldown is the minimum number of
    | minutes between two such mails to one address, across the project's
    | lists; 0 or null for none. A project without a preference page turns
    | manage links off in its definition: $project->manageLinks(false).
    |
    */

    'manage' => [
        'token_ttl' => env('WAITLIST_MANAGE_TOKEN_TTL', 60),
        'request_cooldown' => env('WAITLIST_MANAGE_REQUEST_COOLDOWN', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy (data minimization)
    |--------------------------------------------------------------------------
    |
    | Whether the activity log stores the IP and user agent of a request. Both
    | are cleared when an entry is erased. Infrastructure logs and rate-limit
    | storage are separate and remain the operator's responsibility.
    |
    */

    'privacy' => [
        'store_ip' => env('WAITLIST_STORE_IP', false),
        'store_user_agent' => env('WAITLIST_STORE_USER_AGENT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Technical defaults, not statutory periods or legal recommendations. You
    | must justify your retention periods and monitor the cleanup. Values are
    | days; null keeps the data. A period reaching back before 1970, which
    | would never match a row, is refused: write null to keep. waitlist:prune
    | applies the periods; one that does not read is reported and fails the
    | run once the others are applied. Active confirmed entries and the
    | remaining activity rows never expire automatically.
    |
    | - pending_days: addresses whose confirmation stayed outstanding, counted
    |   from the start of the cycle, so resends never extend it
    | - unsubscribed_days: addresses that left, counted from when they left.
    |   Keeping them for a while retains consent records in case a mail is
    |   disputed; agree on the period with your data protection officer
    | - request_metadata_days: IP and user agent on the log, when stored at all
    |
    | The package schedules waitlist:prune with this cron expression; null
    | leaves scheduling to you. One that can never run, such as the 30th of
    | February, is refused. Either way, Laravel's scheduler must run.
    |
    */

    'retention' => [
        'pending_days' => env('WAITLIST_RETENTION_PENDING_DAYS', 30),
        'unsubscribed_days' => env('WAITLIST_RETENTION_UNSUBSCRIBED_DAYS', 1095),
        'request_metadata_days' => env('WAITLIST_RETENTION_REQUEST_METADATA_DAYS', 30),
        'schedule' => env('WAITLIST_RETENTION_SCHEDULE', '15 3 * * *'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Routes
    |--------------------------------------------------------------------------
    |
    | The package's JSON API: POST {prefix}, GET {prefix}/purposes,
    | GET|POST {prefix}/confirm/{token}, GET|POST {prefix}/unsubscribe/{token},
    | POST {prefix}/manage-link, and the preference page behind the manage
    | token: GET {prefix}/manage/{token}, POST .../data, PUT .../purposes,
    | POST .../unsubscribe, POST .../erase. The prefix is a plain path, with no
    | placeholder in it: the links in mails are built from the route name alone.
    |
    | Disabled by default so installing the package never silently exposes a
    | public write endpoint.
    |
    | GET never changes anything: it reports the token's state and, with the
    | pages a project defines with ->urls(), sends the browser to your page,
    | which posts the token back. Mail scanners and link previews follow GET links, and a GET
    | that unsubscribes people would remove them without a click.
    |
    | The middleware applies to every route, including the links in your mails
    | and RFC 8058 one-click requests, which carry no credentials. Put
    | authentication into the project resolver below instead.
    |
    | group_middleware adds middleware to one group only, after its rate limit
    | (Laravel still sorts the cookies and session of "web" ahead of it):
    | "signup" for checks on your forms, such as CSRF ("web") or an origin
    | check, which the token links that mail providers call must not face.
    |
    | Rate limits apply per group: "signup" (signup, wording, manage links)
    | and "links" (everything with a token). Each names a limiter: the
    | package's "waitlist" and "waitlist-links", tuned by rate_limits, or one
    | you define with RateLimiter::for(); a name nothing defines is refused.
    | Null turns a group's limit off.
    | Guests are limited per IP; a server calling for its project (see
    | "authentication") per server, since all its visitors share its address.
    |
    */

    'routes' => [
        'enabled' => env('WAITLIST_ROUTES_ENABLED', false),
        'prefix' => 'waitlist',
        'name' => 'waitlist.',
        'middleware' => ['api'],
        'group_middleware' => [
            'signup' => [],
            'links' => [],
        ],
        'limiters' => [
            'signup' => 'waitlist',
            'links' => 'waitlist-links',
        ],
        'rate_limits' => [
            'signup_per_minute' => env('WAITLIST_RATE_LIMIT_SIGNUP', 10),           // per visitor and endpoint
            // The package's own link limits, 10 and 600, are also what stands in
            // for a value here that does not read.
            'link_per_minute' => env('WAITLIST_RATE_LIMIT_LINK', WaitlistServiceProvider::LINK_PER_MINUTE), // per token
            'links_per_ip_per_minute' => env('WAITLIST_RATE_LIMIT_LINKS_PER_IP', WaitlistServiceProvider::LINKS_PER_IP_PER_MINUTE), // bounds made-up tokens
            'caller_signup_per_minute' => env('WAITLIST_RATE_LIMIT_CALLER_SIGNUP', 120), // per server with a project and endpoint
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bindings
    |--------------------------------------------------------------------------
    */

    'url_generator' => DefaultConfirmationUrlGenerator::class,

    /*
    | Which project a signup, a request for the wording, or a manage link
    | requested by address is for. The default always answers "default"; a
    | central waitlist API resolves it from a key or the Origin, and may
    | reject a request. AuthenticatedProjectResolver takes it from the caller
    | (see "authentication"). Links with a token need no resolver.
    */
    'project_resolver' => DefaultProjectResolver::class,

    /*
    | Who calls the signup, the wording and the manage link by address: the
    | user of the first of these guards that authenticates the request; null
    | is the default guard. The resolver and the useWaitlist gate both see this
    | caller. Define the gate yourself to decide who may do what; the default
    | keeps a caller with a project (HasWaitlistProject) to that project, and
    | with "required" refuses guests (401) and callers without a project (403).
    | Leave "required" off for public forms; turn it on for an API that only
    | your projects' servers may call.
    |
    | A server signs up its visitors from one address, so the limits per
    | visitor are its job; the API only caps the server itself. Name the header
    | in which your servers forward the visitor's address, e.g.
    | "X-Waitlist-Client-Ip", to limit per visitor here as well and record
    | that address with the consent. It is read from callers with a project
    | only, never from guests, who could send anything.
    */
    'authentication' => [
        'guards' => [null],
        'required' => env('WAITLIST_AUTHENTICATION_REQUIRED', false),
        'client_ip_header' => env('WAITLIST_CLIENT_IP_HEADER'),
    ],
    'email_normalizer' => DefaultEmailNormalizer::class,

    /*
    | Guards the signup and the manage link requested by address, where anyone
    | can type an address. The default accepts everything; bind your own
    | implementation (Turnstile, reCAPTCHA, honeypot), see
    | docs/securing-the-endpoints.md. It covers the HTTP endpoints only. A
    | closure registered via Waitlist::verifySpamUsing() takes precedence.
    */
    'spam_protector' => NullSpamProtector::class,

    /*
    |--------------------------------------------------------------------------
    | CSV Export
    |--------------------------------------------------------------------------
    |
    | Columns are limited to CsvExporter::EXPORTABLE, so an export can never
    | contain tokens. Keep spreadsheet_safe on unless you post-process the
    | file: it prefixes cells starting with = + - @ tab or CR with an
    | apostrophe, so spreadsheet applications treat them as text instead of
    | formulas.
    |
    */

    'export' => [
        'spreadsheet_safe' => true,
        'columns' => [
            'id',
            'list',
            'email',
            'status',
            'purposes',
            'confirmed_at',
            'unsubscribed_at',
            'metadata',
            'created_at',
        ],
    ],

];
