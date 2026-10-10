# Configuration

Everything in `config/waitlist.php`, with its environment variable where it has
one. Publish the file with `php artisan vendor:publish --tag=waitlist-config`;
its comments say the same in more words.

Switches read `true`/`false`, `on`/`off`, `yes`/`no` and `1`/`0`. Numbers read
whole numbers as written, leading zeros included (`05` is 5), and `null`
switches a limit or period off where a table below says so. Any other value is
refused with an `InvalidConfigurationException` that names the key and what it
must be, an empty variable included: a blank line in `.env` never turns double
opt-in or a retention period off. If an empty variable in `.env` causes it,
remove the variable to keep the default. The connection, the client IP
header, the limiters and the retention schedule read an empty value as unset,
like `null`.

A period is bounded by the `timestamp` columns it is stored in or compared
with, and a value beyond is refused with an `InvalidConfigurationException`
that names the key and the limit, since a period near the largest integer would
wrap around and land on the wrong side of now:

- A lifetime (`double_opt_in.token_ttl`, `manage.token_ttl`, in minutes) may not
  end after 2038-01-19 03:14:07 UTC, the last moment a MySQL or MariaDB
  `timestamp` column holds. That is about 5.9 million minutes (11 years) from
  October 2026, and the limit shrinks by the minute.
- A retention period (`retention.*_days`) and a cooldown (`resend_cooldown`,
  `manage.request_cooldown`, in minutes) count back from now and may not reach
  before 1970, earlier than any row: about 20 700 days or 29.9 million minutes
  today, growing with the clock. A period that long would never erase a row, so
  write `null` to keep.

`null` is how to say never or off where a table below allows it: a
`token_ttl` of `null` never expires, a retention period of `null` keeps the
data, a cooldown of `null` (or 0) throttles nothing. A manage link always
expires.

A setting is checked where the package uses it, so a mistake in one stops the
feature that needs it. A signup reads the
confirm link lifetime and the cap on pending requests only when it sends a
confirmation; a manage link for a person you identify needs `manage.token_ttl`
but not the request cooldown; `waitlist:privacy` reads only what it describes.
`waitlist:prune` applies every retention period that reads and then fails on
the ones that do not, reporting each to your logs and exiting with a failure,
so a typo in `retention.request_metadata_days` never keeps abandoned signups
and departed addresses past their period.

Leaving, withdrawing, confirming an issued link and erasing depend on few
settings: `routes.*` with the links limiter and middleware, and, for
confirming, what builds the unsubscribe link its event carries. A link that
cannot be built undoes the confirmation rather than leaving it unannounced.
Settings these steps only pass by fall back instead, and each mistake is
reported once per request, job or command:

- privacy settings that do not read store neither the IP address nor the user
  agent;
- guards or a client IP header that do not resolve treat a token link as a
  guest's request, limited by its address; the signup and the gate still
  refuse them;
- an email normalizer that does not work ends the entry the token names, and
  the address stays on its other lists until it leaves again;
- a catalog that does not read answers a browser in JSON instead of sending it
  to your page;
- `rate_limits.link_per_minute` or `links_per_ip_per_minute` that do not read
  give way to the limits the package ships, 10 and 600; a limiter of your own
  decides for itself.

Nothing in this config stops the app from booting, artisan included: a route
setting that does not read refuses the requests of its group, and a
`retention.schedule` that does not read is reported to your logs and leaves
`waitlist:prune` unscheduled. A route group registered while one of its
settings did not read lacks what that setting meant to add, so it keeps
refusing its requests until the routes are registered again: with cached
routes, run `php artisan route:cache` once the config is fixed.

The package merges its defaults into your config when it registers: a key your
published `config/waitlist.php` lacks takes the package's value, while a list
you set replaces the package's as a whole. With a cached config, run
`php artisan config:cache` again after updating the package; a key, or the whole
`waitlist` key, missing from a stale cache is reported instead of guessed.

In code, `Taldres\Waitlist\Enums\ConfigKey` names every key below:
`ConfigKey::DoubleOptIn->value` is `waitlist.double_opt_in.enabled`. Set a value
in a test with `config()->set(ConfigKey::DoubleOptIn->value, false)`; the routes
are registered when the app boots, so turn them on in `phpunit.xml` or
`.env.testing` instead.

## Projects and wording

Projects, their purposes, lists, fields and pages are not configured here: you
describe them with `Waitlist::define()` in a service provider, see
[Projects, lists and fields](../projects.md). The config only decides where they
come from.

| Key | Default | What it is |
| --- | --- | --- |
| `catalog` | `DefinedProjectCatalog` | Where projects, lists, wording, fields and pages come from. The default reads `Waitlist::define()`; `StoredWordingCatalog` reads the wording from `waitlist:wording` and the rest from the definitions; your own catalog replaces the definitions and decides double opt-in per list |
| `default_list` | `default` | The list the HTTP endpoints use when a request names none |
| `wording.require_hash` | `WAITLIST_REQUIRE_WORDING_HASH`, off | Refuse a purpose choice that is recorded without the hash of the text shown; keeping or withdrawing a purpose needs none |

## Double opt-in: `double_opt_in`

| Key | Variable | Default | |
| --- | --- | --- | --- |
| `enabled` | `WAITLIST_DOUBLE_OPT_IN` | on | per list with `->doubleOptIn()` |
| `token_ttl` | | 10080 (seven days) | minutes a confirm link works, at least 1 and not past 2038-01-19; `null` never expires |
| `resend_cooldown` | `WAITLIST_RESEND_COOLDOWN` | 5 | minutes between two confirmation requests of one cycle; 0 or `null` none, at most the minutes since 1970 |
| `max_confirmations` | `WAITLIST_MAX_CONFIRMATIONS` | 5 | confirmation requests per cycle, at least 1; `null` no cap. Past the cap, one more may go out once the last link has expired |
| `max_pending_per_address` | `WAITLIST_MAX_PENDING_PER_ADDRESS` | 5 | unconfirmed lists of a project one address gets a request for per day, at least 1; `null` no limit |
| `invalidate_confirm_token_after_confirmation` | `WAITLIST_INVALIDATE_CONFIRM_TOKEN` | off | make confirm links single-use |

## Preference page: `manage`

| Key | Variable | Default | |
| --- | --- | --- | --- |
| `token_ttl` | `WAITLIST_MANAGE_TOKEN_TTL` | 60 | minutes a manage link works, at least 1 and not past 2038-01-19; never unlimited |
| `request_cooldown` | `WAITLIST_MANAGE_REQUEST_COOLDOWN` | 5 | minutes between two manage link mails to one address; 0 or `null` none, at most the minutes since 1970 |

## Storage and privacy

| Key | Variable | Default | |
| --- | --- | --- | --- |
| `connection` | `WAITLIST_CONNECTION` | app default | a separate database connection for the waitlist tables |
| `privacy.store_ip` | `WAITLIST_STORE_IP` | off | IP on the activity log |
| `privacy.store_user_agent` | `WAITLIST_STORE_USER_AGENT` | off | user agent on the activity log |
| `retention.pending_days` | `WAITLIST_RETENTION_PENDING_DAYS` | 30 | unconfirmed signups, from the start of the cycle; 0 at the next run, `null` kept, at most the days since 1970 |
| `retention.unsubscribed_days` | `WAITLIST_RETENTION_UNSUBSCRIBED_DAYS` | 1095 | people who left, from when they left; 0 at the next run, `null` kept, at most the days since 1970 |
| `retention.request_metadata_days` | `WAITLIST_RETENTION_REQUEST_METADATA_DAYS` | 30 | IP and user agent on the log; 0 at the next run, `null` kept, at most the days since 1970 |
| `retention.schedule` | `WAITLIST_RETENTION_SCHEDULE` | `15 3 * * *` | when `waitlist:prune` runs; `null` or empty schedules nothing; one that can never run, such as the 30th of February, is refused |

The retention periods are technical defaults, not legal recommendations; see
[GDPR in practice](../gdpr.md#retention).

## HTTP routes: `routes`

| Key | Variable | Default | |
| --- | --- | --- | --- |
| `enabled` | `WAITLIST_ROUTES_ENABLED` | off | the package's JSON API, see [HTTP API](http-api.md). While off, every route answers `404`, also from a route cache, except a method it does not take (`405`) and `OPTIONS`, which Laravel answers before any middleware runs; neither acts. An empty `WAITLIST_ROUTES_ENABLED=` is no decision: it refuses every request, naming the key; write `false` to turn the routes off |
| `prefix` | | `waitlist` | the path the routes live under, empty for the root; no `{placeholder}`, as the links in mails cannot be built for one |
| `name` | | `waitlist.` | the route name prefix |
| `middleware` | | `['api']` | applies to every route, token links included: no authentication here |
| `group_middleware.signup` | | `[]` | added to signup, wording and manage links only, after the rate limit, apart from what Laravel always sorts ahead of it, such as the session of `web`: checks for your forms, such as CSRF or an origin check, see [Checks for your forms only](../securing-the-endpoints.md#checks-for-your-forms-only) |
| `group_middleware.links` | | `[]` | added to everything with a token only |
| `limiters.signup` | | `waitlist` | named limiter for signup, wording and manage links; `null` off; a name no `RateLimiter::for()` defines is refused |
| `limiters.links` | | `waitlist-links` | named limiter for everything with a token; `null` off; a name no `RateLimiter::for()` defines is refused |
| `rate_limits.signup_per_minute` | `WAITLIST_RATE_LIMIT_SIGNUP` | 10 | per visitor and endpoint: a guest's IP, or the address a server forwards; at least 1, as do all four |
| `rate_limits.link_per_minute` | `WAITLIST_RATE_LIMIT_LINK` | 10 | per token; one that does not read gives way to 10, reported |
| `rate_limits.links_per_ip_per_minute` | `WAITLIST_RATE_LIMIT_LINKS_PER_IP` | 600 | bounds made-up tokens per IP, per server, or per visitor a server forwards; one that does not read gives way to 600, reported |
| `rate_limits.caller_signup_per_minute` | `WAITLIST_RATE_LIMIT_CALLER_SIGNUP` | 120 | per server calling for a project and endpoint, see [Servers calling for a project](../securing-the-endpoints.md#servers-calling-for-a-project) |

See [Securing the endpoints](../securing-the-endpoints.md#rate-limits) for your own limiters.

The check of `enabled` and these settings runs ahead of the middleware Laravel
sorts by its priority list: the package puts it at the head of that list when it
boots, after a list set with `priority()` in `bootstrap/app.php`. An app that
replaces the list later with `setMiddlewarePriority()` must list
`Taldres\Waitlist\Http\Middleware\CheckRouteConfig` first, see
[Middleware order](../securing-the-endpoints.md#middleware-order).

## Who may call: `authentication`

See [Who may call](../projects.md#who-may-call-the-usewaitlist-gate). Token links
never need credentials.

| Key | Variable | Default | |
| --- | --- | --- | --- |
| `guards` | | `[null]` | the guards that find the caller of the signup, the wording and the manage link by address, in order; the first that authenticates wins; `null` is the default guard; one `config/auth.php` does not define is refused, naming it |
| `required` | `WAITLIST_AUTHENTICATION_REQUIRED` | off | the default `useWaitlist` gate refuses guests (`401`) and callers without a project (`403`); a gate of your own decides for itself |
| `client_ip_header` | `WAITLIST_CLIENT_IP_HEADER` | `null` | the header in which servers calling for a project forward their visitor's address, e.g. `X-Waitlist-Client-Ip`: limits per visitor and the recorded IP use it; never read from guests |

## Bindings

Swap in your own implementation; see [Extending](../extending.md). A binding
names a class, or an interface or abstract class your app binds in the
container; the contract itself and a model class that is abstract are refused.

| Key | Default | Decides |
| --- | --- | --- |
| `model`, `subscription_model`, `consent_model`, `activity_model` | the package models | the Eloquent models, for extra columns |
| `url_generator` | `DefaultConfirmationUrlGenerator` | the confirm, unsubscribe and manage URLs in mails |
| `project_resolver` | `DefaultProjectResolver`, always `default` | which project an HTTP signup is for; `AuthenticatedProjectResolver` takes it from the caller |
| `email_normalizer` | `DefaultEmailNormalizer`, lowercase and trim | which spellings are the same address; part of the lookup hash |
| `spam_protector` | `NullSpamProtector`, accepts all | the bot check on the signup and manage links by address |

## CSV export: `export`

| Key | Default | |
| --- | --- | --- |
| `columns` | id, list, email, status, purposes, confirmed_at, unsubscribed_at, metadata, created_at | limited to `CsvExporter::EXPORTABLE`, so tokens never end up in a file |
| `spreadsheet_safe` | on | prefixes cells starting with `=`, `+`, `-`, `@`, tab or CR, so spreadsheets read them as text |
