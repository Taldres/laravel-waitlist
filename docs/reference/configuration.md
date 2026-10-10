# Configuration

Everything in `config/waitlist.php`, with its environment variable where it has
one. Publish the file with `php artisan vendor:publish --tag=waitlist-config`;
its comments say the same in more words.

Switches read `true`/`false`, `on`/`off`, `yes`/`no` and `1`/`0`. Numbers read
whole numbers, and `null` switches a limit or period off. An empty or unreadable
value keeps the default, so a blank variable never turns double opt-in or a
retention period off. The one exception is
`WAITLIST_RETENTION_SCHEDULE`: blank, it schedules nothing, like `null`.

In code, `Taldres\Waitlist\Enums\ConfigKey` names every key below, with its
default: `ConfigKey::RoutesEnabled->value` is `waitlist.routes.enabled`. Set a
value in a test with `config()->set(ConfigKey::RoutesEnabled->value, true)`.

## Projects and wording

Projects, their purposes, lists, fields and pages are not configured here: you
describe them with `Waitlist::define()` in a service provider, see
[Projects, lists and fields](../projects.md). The config only decides where they
come from.

| Key | Default | What it is |
| --- | --- | --- |
| `catalog` | `DefinedProjectCatalog` | Where projects, lists, wording, fields and pages come from. The default reads `Waitlist::define()`; `StoredWordingCatalog` reads the wording from `waitlist:wording` and the rest from the definitions; your own catalog replaces the definitions and decides double opt-in per list |
| `default_list` | `default` | The list the HTTP endpoints use when a request names none |
| `wording.require_hash` | `WAITLIST_REQUIRE_WORDING_HASH`, off | Refuse a purpose choice that does not post back the hash of the text shown |

## Double opt-in: `double_opt_in`

| Key | Variable | Default | |
| --- | --- | --- | --- |
| `enabled` | `WAITLIST_DOUBLE_OPT_IN` | on | per list with `->doubleOptIn()` |
| `token_ttl` | | 10080 (seven days) | minutes a confirm link works; `null` never expires |
| `resend_cooldown` | `WAITLIST_RESEND_COOLDOWN` | 5 | minutes between two confirmation requests of one cycle |
| `max_confirmations` | `WAITLIST_MAX_CONFIRMATIONS` | 5 | confirmation requests per cycle |
| `max_pending_per_address` | `WAITLIST_MAX_PENDING_PER_ADDRESS` | 5 | unconfirmed lists of a project one address gets a request for per day; `null` no limit |
| `invalidate_confirm_token_after_confirmation` | `WAITLIST_INVALIDATE_CONFIRM_TOKEN` | off | make confirm links single-use |

## Preference page: `manage`

| Key | Variable | Default | |
| --- | --- | --- | --- |
| `token_ttl` | `WAITLIST_MANAGE_TOKEN_TTL` | 60 | minutes a manage link works |
| `request_cooldown` | `WAITLIST_MANAGE_REQUEST_COOLDOWN` | 5 | minutes between two manage link mails to one address; `null` none |

## Storage and privacy

| Key | Variable | Default | |
| --- | --- | --- | --- |
| `connection` | `WAITLIST_CONNECTION` | app default | a separate database connection for the waitlist tables |
| `privacy.store_ip` | `WAITLIST_STORE_IP` | off | IP on the activity log |
| `privacy.store_user_agent` | `WAITLIST_STORE_USER_AGENT` | off | user agent on the activity log |
| `retention.pending_days` | `WAITLIST_RETENTION_PENDING_DAYS` | 30 | unconfirmed signups, from the start of the cycle |
| `retention.unsubscribed_days` | `WAITLIST_RETENTION_UNSUBSCRIBED_DAYS` | 1095 | people who left, from when they left |
| `retention.request_metadata_days` | `WAITLIST_RETENTION_REQUEST_METADATA_DAYS` | 30 | IP and user agent on the log |
| `retention.schedule` | `WAITLIST_RETENTION_SCHEDULE` | `15 3 * * *` | when `waitlist:prune` runs; `null` or blank schedules nothing |
| `reporting.timezone` | `WAITLIST_REPORTING_TIMEZONE` | `app.timezone` | where a reporting day ends; pick it before you collect |

The retention periods are technical defaults, not legal recommendations; see
[GDPR in practice](../gdpr.md#retention).

## HTTP routes: `routes`

| Key | Variable | Default | |
| --- | --- | --- | --- |
| `enabled` | `WAITLIST_ROUTES_ENABLED` | off | the package's JSON API, see [HTTP API](http-api.md) |
| `prefix` | | `waitlist` | the path the routes live under |
| `name` | | `waitlist.` | the route name prefix |
| `middleware` | | `['api']` | applies to every route, token links included: no authentication here |
| `limiters.signup` | | `waitlist` | named limiter for signup, wording and manage links; `null` off |
| `limiters.links` | | `waitlist-links` | named limiter for everything with a token; `null` off |
| `rate_limits.signup_per_minute` | `WAITLIST_RATE_LIMIT_SIGNUP` | 10 | per visitor and endpoint: a guest's IP, or the address a server forwards |
| `rate_limits.link_per_minute` | `WAITLIST_RATE_LIMIT_LINK` | 10 | per token |
| `rate_limits.links_per_ip_per_minute` | `WAITLIST_RATE_LIMIT_LINKS_PER_IP` | 600 | bounds made-up tokens per IP, per server, or per visitor a server forwards |
| `rate_limits.caller_signup_per_minute` | `WAITLIST_RATE_LIMIT_CALLER_SIGNUP` | 120 | per server calling for a project and endpoint, see [Servers calling for a project](../securing-the-endpoints.md#servers-calling-for-a-project) |

See [Securing the endpoints](../securing-the-endpoints.md#rate-limits) for your own limiters.

## Who may call: `authentication`

See [Who may call](../projects.md#who-may-call-the-usewaitlist-gate). Token links
never need credentials.

| Key | Variable | Default | |
| --- | --- | --- | --- |
| `guards` | | `[null]` | the guards that find the caller of the signup, the wording and the manage link by address, in order; the first that authenticates wins; `null` is the default guard |
| `required` | `WAITLIST_AUTHENTICATION_REQUIRED` | off | the default `useWaitlist` gate refuses guests (`401`) and callers without a project (`403`); a gate of your own decides for itself |
| `client_ip_header` | `WAITLIST_CLIENT_IP_HEADER` | `null` | the header in which servers calling for a project forward their visitor's address, e.g. `X-Waitlist-Client-Ip`: limits per visitor and the recorded IP use it; never read from guests |

## Bindings

Swap in your own implementation; see [Extending](../extending.md).

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
