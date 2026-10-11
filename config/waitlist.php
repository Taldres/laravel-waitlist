<?php

use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;

/*
| Every setting is described in docs/reference/configuration.md, which is not
| installed with the package: open it at the tag of your version on
| https://github.com/Taldres/laravel-waitlist/blob/main/docs/reference/configuration.md
|
| An empty variable in .env is refused, naming the key, for every setting but
| two that read it as unset, like null: the connection and the client IP
| header. Their comments say so.
*/

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
    | Null, or an empty value, uses the application default. Set this to keep
    | waitlist tables on a separate connection.
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
    | Wording
    |--------------------------------------------------------------------------
    |
    | Projects, their lists, the wording of their purposes, the fields a signup
    | may carry and their frontend URLs are not configured here: describe them
    | with Waitlist::define() in a service provider, see docs/projects.md.
    |
    | A form that renders its own copy of the wording, e.g. from a CMS, can
    | post back the hash of what it showed ({version, locale, hash}); a hash
    | that does not match the registered text is refused. require_hash makes
    | the hash mandatory for every choice a form posts.
    |
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
    | days, whole numbers from 0, which applies at the next run; null keeps the
    | data. A period reaching back before 1970, which would never match a row,
    | is refused: write null to keep. waitlist:prune
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
    | A project can promise periods of its own, see ProjectDefinition::retention().
    |
    | waitlist:prune applies them. Schedule it in routes/console.php, for
    | example Schedule::command('waitlist:prune')->daily(); the package does
    | not, and waitlist:privacy warns when no scheduled task runs it.
    |
    */

    'retention' => [
        'pending_days' => env('WAITLIST_RETENTION_PENDING_DAYS', 30),
        'unsubscribed_days' => env('WAITLIST_RETENTION_UNSUBSCRIBED_DAYS', 1095),
        'request_metadata_days' => env('WAITLIST_RETENTION_REQUEST_METADATA_DAYS', 30),
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
    | authentication into the project resolver instead, see "authentication".
    |
    | group_middleware adds middleware to one group only, after its rate limit
    | (Laravel still sorts the cookies and session of "web" ahead of it):
    | "signup" for checks on your forms, such as CSRF ("web") or an origin
    | check, which the token links that mail providers call must not face.
    |
    | Rate limits apply per group: "signup" (signup, wording, manage links by
    | address) through the limiter "waitlist", and "links" (everything with a
    | token) through "waitlist-links". The numbers under rate_limits tune the
    | package's own two: whole numbers, at least 1, counted per minute. To
    | replace a group's limit, define the limiter of its name yourself with
    | RateLimiter::for(); to turn it off, return Limit::none() from there.
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
        'rate_limits' => [
            'signup_per_minute' => env('WAITLIST_RATE_LIMIT_SIGNUP', 10),           // per visitor and endpoint
            // The package's own link limits, 10 and 600, are also what stands in
            // for a value here that does not read.
            'link_per_minute' => env('WAITLIST_RATE_LIMIT_LINK', 10), // per token
            'links_per_ip_per_minute' => env('WAITLIST_RATE_LIMIT_LINKS_PER_IP', 600), // bounds made-up tokens
            'caller_signup_per_minute' => env('WAITLIST_RATE_LIMIT_CALLER_SIGNUP', 120), // per server with a project and endpoint
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | Who calls the signup, the wording and the manage link by address: the
    | user of the first of these guards that authenticates the request; null
    | is the default guard. The project resolver and the useWaitlist gate both
    | see this caller; bind AuthenticatedProjectResolver to take the project
    | from it, see docs/projects.md. Define the gate yourself to decide who may
    | do what; the default keeps a caller with a project (HasWaitlistProject)
    | to that project, and with "required" refuses guests (401) and callers
    | without a project (403). Leave "required" off for public forms, and for
    | an API that only your app's signed-in users may call, which takes
    | auth:sanctum in routes.group_middleware.signup and a gate instead; turn
    | it on for an API that only your projects' servers may call.
    |
    | A server signs up its visitors from one address, so the limits per
    | visitor are its job; the API only caps the server itself. Name the header
    | in which your servers forward the visitor's address, e.g.
    | "X-Waitlist-Client-Ip", to limit per visitor here as well and record
    | that address with the consent. It is read from callers with a project
    | only, never from guests, who could send anything. Null, or an empty value,
    | names none.
    |
    */

    'authentication' => [
        'guards' => [null],
        'required' => env('WAITLIST_AUTHENTICATION_REQUIRED', false),
        'client_ip_header' => env('WAITLIST_CLIENT_IP_HEADER'),
    ],

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
