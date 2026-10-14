# Release Notes

## [Unreleased](https://github.com/taldres/laravel-waitlist/commits/main/compare/v0.1.1...HEAD)

- Requires PHP 8.3+ and Laravel 13
- Subscription cycles with exactly-once state changes
- Consent per purpose: one required primary purpose per list, optional purposes withdrawable on their own
- Wording per locale: forms get the text in the visitor's language, and each consent stores the locale it was given in
- Forms with their own copy of the wording post back its hash, and a text that drifted from the registered one is refused
- `recipients()`: everyone a mail for a purpose may go to, once per address, with the links and the locale that mail needs
- Wording registered where it is written: `StoredWordingCatalog`, `waitlist:wording` to sync a frontend's wording on deploy, and `Waitlist::registerWording()` for CMS webhooks
- Wording sent by a project's own servers: with `wordingFromCallers()` a signup brings the text along with the version, a version seen for the first time is registered with the server that sent it (`registered_by`, `WordingRegistered`), and a registered version never changes; only callers the `useWaitlist` gate allows `WaitlistAction::RegisterWording`, by default those acting for the project, may send it, never guests
- A purpose choice that is wrong says what is wrong with it, and hashes are accepted in either case
- Addresses, metadata, IP and user agent always encrypted at rest with Laravel's `encrypted` casts, following `Model::encryptUsing()`, or with an encrypter for the package only (`Waitlist::encryptUsing()`); lookups on a keyed hash
- Retention periods applied by `waitlist:prune`, which your application schedules; `waitlist:privacy` warns while no scheduled task runs it
- Config checked where it is used, with a clear exception that names the key: documented `.env` forms are read, anything else is refused, an empty variable included, and limits such as a link lifetime of at least one minute apply; the package's defaults are merged into a published config, groups key by key and lists as a whole
- Leaving, withdrawing, confirming an issued link and erasing read only the settings they need, and fail clearly where they cannot do without one (a valid token, the authorization, the database, the state change); settings they only pass by fall back: privacy settings that do not read store neither IP address nor user agent, guards or a client IP header that do not resolve treat a token link as a guest's request, an email normalizer that throws an `InvalidConfigurationException` still ends the entry the token names and reaches the address's other lists by that entry's lookup hash, a catalog that does not read answers a browser in JSON, and link limits that do not read give way to the ones the package ships; each such mistake is reported once per request, job or command, while the signup, the gate and the reports still refuse it
- A setting breaks only the flow that reads it: a signup without confirmation reads none of the confirmation settings, `Waitlist::manageLink()` not the request cooldown, `waitlist:privacy` only what it describes, saving fewer purposes on the manage page neither the double opt-in default nor `wording.require_hash`, which is demanded only for a consent that gets recorded; `waitlist:prune` applies every retention period that reads, then fails on the ones that do not, and stops before deleting anything when the catalog that tells it a project's periods does not read
- A link that cannot be built undoes the signup or confirmation it belongs to, rather than leaving it committed and never announced
- Periods bounded by the `timestamp` columns they meet: a link lifetime may not end after 2038-01-19 03:14:07 UTC, a retention period or cooldown may not reach back before 1970, and `null` is how to say never or off, so a huge number can no longer wrap around and erase what was just collected
- Routes are registered on boot while `routes.enabled` is on. A route setting that does not read is reported once and leaves out the routes it belongs to, all of them for the switch, prefix, name or shared middleware and one group for its own middleware, rather than routes that lack what it meant to add; Laravel's middleware priority stays untouched. `routes.enabled` switches the registration, not routes a route cache holds, which `waitlist:check` finds by comparing the registered routes with the config
- Contracts are swapped with a container binding alone: the package binds its defaults with `bindIf()`, and a `bind()` in a provider of the app replaces them. On the signup and the manage link by address, a `Waitlist::verifySpamUsing()` closure wins over the bound `SpamProtector`, however it is bound, without building it; `app(SpamProtector::class)` stays the bound class. `waitlist:check` also resolves the five contracts and fails on one the container cannot build or that does not implement its contract
- A bot check without an outside service: bind `TimeTrapSpamProtector` as the `SpamProtector`, and `GET /purposes` hands out a `challenge` (sent with `Cache-Control: no-store`) that a signup or a manage link by address sends back in `X-Waitlist-Challenge` no sooner than 2 seconds and no later than an hour after, with the hidden `website` field left empty; servers calling for their project and the token links are not checked
- Middleware per route group (`routes.group_middleware`): checks for the forms, such as CSRF or an origin check, go on the signup only, so the token links in mails and one-click unsubscribes never face them
- Refusals the routes answer themselves name an `error` (`invalid_token`, `expired_token`, `unknown_list`, `not_subscribed`, `list_unavailable`, `manage_links_disabled`, `spam_check_failed`), so a client can tell them from a `404` for a route that does not exist
- Reporting from the activity log: daily series and totals per list, and the confirmed count at the end of any day (`confirmedOn()`), with every departure recorded by the status it left so a later clean-up is not counted twice; days end in `app.timezone`, stored with each row when it is written
- Self-service preference page: purposes, data export, erasure. Mails carry an unsubscribe token that can only remove; the page opens with a short-lived manage link mailed to the address on request
- `waitlist:privacy` for the record of processing; it counts auth middleware in `routes.group_middleware.signup` (any `AuthenticatesRequests`, such as `auth:sanctum`) like `authentication.required` as keeping guests out, and states the limits per server wherever a caller can act for a project: with `required`, named guards, `AuthenticatedProjectResolver` or `authentication.client_ip_header`
- `waitlist:check` for a deploy: every setting that does not read at once (by key, never by value), settings in a published config that the package does not read, a hint while no scheduled task runs `waitlist:prune` (`--strict` leaves it alone, as it sees only Laravel's scheduler), package routes that disagree with `routes.enabled`, and tables or columns the migrations create that the database lacks; a Laravel Waitlist section in `php artisan about`
- Manage links per project: `$project->manageLinks(false)` (or `manageLinks()` on a `ProjectCatalog` of your own) for a project without a preference page; no manage link is mailed, requesting or minting one throws `ManageLinksDisabledException`, `POST /manage-link` answers `404` with `manage_links_disabled`, and `waitlist:privacy` says that access and erasure go through you
- Projects defined in code: `Waitlist::define()` in a service provider describes a project's purposes, lists, fields and pages, checks what it is given, and runs when the project is first needed; `waitlist:install` publishes a `WaitlistServiceProvider` to start from and registers it, with `--projects` one with several named projects whose setup it calls incomplete until a `ProjectResolver` is bound
- Websites that may use a project: `$project->origins([...])` refuses a guest's browser request from any other website with `403` before the `useWaitlist` gate; requests without an `Origin` header and servers calling for their project are not affected, and CORS stays in `config/cors.php`
- Projects: one app can run the waitlists of several products, each with its own purposes, lists, fields and frontend pages; all of it can come from your own `ProjectCatalog`, and a `ProjectResolver` picks the project of each signup before it is validated, so one app can serve as a central waitlist API
- Who may call, as a Laravel gate: `useWaitlist` decides per project, action and list for the signup, the wording and a manage link by address, after the project is resolved and before the body is validated; its default keeps a caller acting for a project (`HasWaitlistProject`) to its own and, with `waitlist.authentication.required`, refuses guests and callers without a project; define the ability to replace it. `AuthenticatedProjectResolver` takes the project from the caller of the configured guards, for central APIs whose clients hold credentials
- Setup mistakes throw `InvalidConfigurationException` (an `InvalidArgumentException`, never a `WaitlistException`), and a primary purpose without wording `MissingWordingException`, which `waitlist:privacy` reports while failing on anything else
- Config keys and frontend pages as enums: `ConfigKey` names every key of `config/waitlist.php`, which a test keeps in step with the file, and `Page` the pages a project defines
- Fields per project and list: a signup accepts only the metadata its project and list define, validated with any Laravel rule, rule objects included, built anew for every request; `Waitlist::for($list)->fields()` hands the same rules to your own controllers, and `waitlist:privacy` lists them
- One withdrawal rule: lists that share a primary purpose are separate waitlists, an optional purpose such as a newsletter is withdrawn on every list of the project, and leaving a list withdraws its primary purpose
- Recording of the confirmation mail reference via `Waitlist::confirmationMailed()`, next to the consent wording, and of a mail that could not be sent via `Waitlist::confirmationFailed()`, which takes the request's cooldown and count back; both take the `EntrySubscribed` event, and each report counts once per request, bound to it by the event's confirm token rather than by a model that a refresh or a queued job may have reloaded, so a repeated, late or contradicting report changes nothing and a mail that went out after its failure is accepted once
- `waitlist:rekey` moves every stored value onto the current key, so old keys can be retired
- RFC 8058 one-click unsubscribe
- Rate limits per route group through the named limiters `waitlist` and `waitlist-links`, on by default: `routes.rate_limits` tunes them, and an app replaces one, or turns it off with `Limit::none()`, by defining it under its name with `RateLimiter::for()`; token links are limited per token rather than per IP
- Servers calling for a project are limited as one source, since all their visitors share an address: capped per server at `caller_signup_per_minute`, with the limit per visitor left to them, unless they forward the visitor's address in the header named by `authentication.client_ip_header`, which then also counts per visitor and is the IP recorded
- Signups capped per address across lists (`max_pending_per_address`), so made-up list names cannot flood a mailbox; errors name the field in a JSON 422. The cap is checked per request: signups or resends for different lists that arrive at the same moment can each pass it
- Steps on one address wait for each other instead of deadlocking: each locks the entry row before its cycle, as an erasure does, withdrawals and preference changes lock the address's entries in key order and read the purposes held under that lock, so two tabs no longer mix their submissions, and the package's outermost transactions try up to three times with fresh data on a deadlock; a resubscribe sent twice no longer answers `500` for an address that had left
- Requests that race an erasure answer as if the address were gone: a signup starts afresh, a resend sends nothing, and an unsubscribe, withdrawal, grant or confirmation whose entry was erased on the way answers `InvalidTokenException` rather than a `404` for a missing model; a withdrawal never ends the address's other lists for an entry it can no longer read
- Inside a transaction of the application, a signup or confirmation that loses a race reads what the other request committed past the transaction's snapshot (MySQL and MariaDB REPEATABLE READ), instead of answering `UnknownWaitlistException` or `InvalidTokenException`
- Manage links by address are sent once per cooldown across the address's lists also when requested on two lists at once, two requests minting a lost unsubscribe token at once hand out the same one, and `waitlist:wording` or `Waitlist::registerWording()` on two deploys at once retry instead of failing on a deadlock or a unique violation
- Lifecycle events and `ManageLinkRequested` wait for the commit on `waitlist.connection`, so a transaction the application rolls back on another connection no longer discards what the package deferred; a manage link that cannot be built no longer starts the request cooldown
- `waitlist:install --mail` writes the double opt-in confirmation into your app: a mailable, its view and a queued, encrypted `EntrySubscribed` listener that lists the purposes still held, reports `confirmationMailed()`, or `confirmationFailed()` for a send that threw; files that exist are left as they are, and no listener is written while `EntrySubscribed` already has one
- Bundled Laravel Boost skills: the integration as a whole, plus focused skills for the frontend, mail, provider sync, projects, reporting, the launch, privacy operations and testing

## [v0.1.1](https://github.com/taldres/laravel-waitlist/commits/main/compare/v0.1.0...v0.1.1) - 2026-10-14

Requests that arrive together for the same address (double submits, several open tabs, a one-click unsubscribe crossing an erasure) no longer deadlock, act twice or answer with a server error.

### What changes

- **Steps on one address wait for each other.** Every step locks the entry before its cycle, as an erasure does. Withdrawals, preference changes and manage links by address lock the address's entries in a fixed order. Where the package opens the outermost transaction, it retries a deadlock up to three times.
- **Events are no longer lost.** Lifecycle events and `ManageLinkRequested` wait for the commit on `waitlist.connection`, so a rollback on another connection no longer discards them.
- **Only the newest confirm link confirms**, also when a resend or the expiry lands between looking the link up and confirming it.
- **Preference changes from two tabs** no longer mix their submissions.
- **Manage links by address** go out once per cooldown across the address's lists, also when requested on two lists at once. A link that cannot be built no longer starts the cooldown.
- **Requests racing an erasure** answer as if the address were gone: a signup starts afresh, a resend sends nothing, token links answer `invalid_token`, and `Waitlist::for($list)->unsubscribe()` / `->withdraw()` return `null`.
- **Inside your own transaction**, a signup or confirmation that loses a race acts on what the other request committed, instead of failing on MySQL and MariaDB.
- Two requests minting a lost unsubscribe token hand out the same one, and `waitlist:wording` or `Waitlist::registerWording()` on two deploys at once retry instead of failing.

### Worth knowing

- **`max_pending_per_address` is checked per request.** Signups or resends for different lists that arrive at the same moment can each pass it. The per-cycle cooldown and `max_confirmations` hold for concurrent requests.
- **Read replicas:** `waitlist.connection` must read what was just committed. Point it at the primary.
- **Your own transactions:** when you call the package inside one, retrying a deadlock is up to you (`DB::transaction($callback, attempts: 3)`).
- **SQLite with several workers:** set a `busy_timeout`, WAL and, on PHP 8.4, `'transaction_mode' => 'IMMEDIATE'`.
- **Extending:** a replaced `SubscriptionLifecycle` must lock the entry row before the cycle row. Subclasses that override `SubscribeToWaitlist::find()`, `GetUnsubscribeToken::mint()` or `RequestManageLink::sentToAddressSince()` need the new signatures.

No new migrations and no config changes. Details are in [docs/lifecycle.md](https://github.com/Taldres/laravel-waitlist/blob/v0.1.1/docs/lifecycle.md).

**Full Changelog**: https://github.com/Taldres/laravel-waitlist/compare/v0.1.0...v0.1.1

## [v0.1.0](https://github.com/taldres/laravel-waitlist/commits/main/compare/main...v0.1.0) - 2026-10-11

<!-- Release notes generated using configuration in .github/release.yml at main -->
### What's Changed

#### Enhancements

* Make the HTTP API safe to tell apart from Laravel's own answers by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/1
* feat: add middleware to one route group by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/2

#### Other Changes

* Require Laravel 13 by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/3
* Read the config typed and checked where it is used by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/4
* Read the bot check proof from a header by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/5
* Skip the database lanes when only docs or agent files change by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/6
* feat: let a project turn manage links off by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/7
* fix: leave projects without lists out of the manage link summary by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/8
* ci: keep ParaTest below 7.26.1 until Pest supports it by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/16
* fix: name the limits of servers in waitlist:privacy by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/9
* feat: warn about launch mistakes in waitlist:privacy by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/10
* fix: take a null or empty list for the default list by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/11
* fix: make config error texts easier to act on by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/12
* docs: add the notes that integrating a central API turned up by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/13
* docs: say per key whether an empty variable means unset by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/14
* test: keep the test cache store out of .env, and note two testing pitfalls by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/15
* ci: run type coverage only where Pest 5 resolves by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/18
* refactor: drop the single-use confirm link switch by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/19
* feat: request a manage link by token on its own route in the links group by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/20
* feat: let a listener report a confirmation mail it could not send by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/21
* feat: let a project promise its own retention periods and confirm link lifetime by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/22
* feat: name a failed spam check with an error code by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/23
* docs: add decision tables for where wording lives and for who may call by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/24
* fix: bind confirmation reports to their request and make them idempotent by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/25
* docs: add the Laravel-first rules to AGENTS.md by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/31
* feat: let a project list the websites that may use it from a browser by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/26
* feat: check the settings and the tables on deploy by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/27
* docs: say in the skills how schema changes ship after the first release by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/28
* docs: state the bounds of the periods and the rate limits in the config comments by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/30
* fix: refuse to build package links without an absolute APP_URL by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/32
* fix: keep the limiters and contract bindings the app defined first by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/33
* test: keep the documentation and the bundled skills in step with the settings by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/29
* refactor: leave the scheduling of waitlist:prune to the application by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/34
* ci: run PHPStan in one process by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/37
* refactor: register the routes without fallbacks and leave the middleware priority alone by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/35
* fix: stop waitlist:prune before deleting when the project catalog does not read by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/36
* fix: accept the date class an app chose with Date::use() by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/38
* refactor: remove code nothing in the package calls by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/39
* test: cover auth middleware with the project resolver by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/40
* ci: cover Laravel's database versions and build the test schema once by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/41
* fix: do not fail waitlist:check --strict for a prune nobody schedules by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/42
* fix: keep php artisan about from failing and name the classes in use by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/43
* fix: state the rate limits waitlist:privacy names only while they are the package's by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/44
* refactor: lean on Laravel for limiters and contracts by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/45
* feat: add waitlist:install --projects by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/46
* fix: reach the address's other lists by the entry's hash when the normalizer does not read by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/47
* refactor!: report a confirmation mail with the EntrySubscribed event by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/48
* refactor: use Command::fail() and keep the prune hint in one place by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/49
* ci: run the tests weekly and let every lane finish by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/50
* fix: tell the three authentication setups apart in the docs and waitlist:privacy by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/51
* docs: note that the package is in development by @Taldres in https://github.com/Taldres/laravel-waitlist/pull/52

**Full Changelog**: https://github.com/Taldres/laravel-waitlist/commits/v0.1.0
