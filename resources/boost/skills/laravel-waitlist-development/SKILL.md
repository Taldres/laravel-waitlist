---
name: laravel-waitlist-development
description: >
  Integrate taldres/laravel-waitlist in a Laravel application: define purposes,
  lists and fields, build the signup form, send the confirmation mail from events,
  add a preference page, and keep consent, retention and encryption intact.
license: MIT
metadata:
  author: Dennis Petersmann
---

# Laravel Waitlist

Use this skill when a Laravel application collects email addresses for a waitlist,
early access or launch notifications with `taldres/laravel-waitlist`.

## Primary Goal

- apply the package's public API in the smallest correct way, preserving its
  technical privacy controls: consent records, double opt-in, encryption, retention

## Workflow

### 1. Install

```bash
composer require taldres/laravel-waitlist
php artisan waitlist:install   # provider, config and migrations; registers the provider
php artisan migrate
```

`waitlist:install` publishes `app/Providers/WaitlistServiceProvider.php`
(`waitlist-provider`), `config/waitlist.php` (`waitlist-config`) and the migrations
(`waitlist-migrations`). Laravel's scheduler must run: the package schedules
`waitlist:prune` itself.

### 2. Define purposes, lists, fields and pages

Describe the waitlist in `boot()` of the published provider; `config/waitlist.php`
keeps only the technical settings.

```php
// app/Providers/WaitlistServiceProvider.php
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Facades\Waitlist;

Waitlist::define(function (ProjectDefinition $project): void {
    $project->purpose('waitlist', ['2026-10' => 'Email me when early access opens.']);
    $project->purpose('newsletter', ['2026-10' => 'Also send me the monthly newsletter.']);

    $project->list('default', purpose: 'waitlist')->optional('newsletter');
});
```

- Every list has exactly one required primary purpose; anything else is optional.
- Only defined lists accept signups; `$project->list('*', purpose: ...)` covers any
  other list name. `->doubleOptIn(false)` turns double opt-in off for one list.
- Read the environment through `config()`, never `env()`: with a cached config,
  `env()` returns `null` outside the config files. The callback runs when the
  project is first needed, and defining a project again replaces it.
- Fields beyond the address, per project and list: `$project->fields(fn () => [...])`
  for every list, `->fields(...)` on a list on top (field => validation rules; a
  closure when the rules are objects). The HTTP signup accepts only these under
  `metadata` and refuses the rest with a `422`; without fields it refuses all
  metadata. `add()` trusts its caller: in your own controllers validate with
  `Waitlist::for($list)->fields()`.
- Several products in one app: one `Waitlist::define('<key>', ...)` per product with
  its own purposes, lists, fields and pages, and use
  `Waitlist::project('<key>')->for($list)`. Without `project()` the facade always
  means the default project, never all of them; an unknown project throws
  `UnknownProjectException`.
  For projects kept in a database, bind your own `ProjectCatalog` (`waitlist.catalog`):
  it supplies lists, fields, wording versions and frontend URL patterns per project.
  Over HTTP, a `ProjectResolver` (`waitlist.project_resolver`) picks the project of
  a signup, e.g. from a publishable key; never put that check into
  `routes.middleware`, which also guards the token links in mails. Checks for
  the forms only, such as CSRF or an origin check, go into
  `routes.group_middleware.signup`.
- Frontend pages per project in `$project->urls(...)`: `confirm`, `unsubscribe`,
  `manage` (mail links, `{token}` replaced) and `confirmed`, `expired`, `invalid`,
  `unsubscribed`, `erased` (where a browser lands after posting).
- Rate limits per group in `routes.limiters`: `signup` → `waitlist` (per IP),
  `links` → `waitlist-links` (per token). Tune `routes.rate_limits`, point a group at
  your own `RateLimiter::for()` name, or set it to `null`. An own limiter decides
  the key (`->by()`), the limits, exemptions (`Limit::none()`), per-route rules
  (`$request->routeIs()`) and the response; `rate_limits` does not apply to it.
  Never key `links` by IP alone: one-click unsubscribes share mail providers' IPs.
  A caller acting for a project (`HasWaitlistProject`) is a server: it is capped
  as a whole (`rate_limits.caller_signup_per_minute`) and limits its visitors
  itself, unless it forwards their address in the header named by
  `authentication.client_ip_header`, which then counts per visitor too.
- To change wording, add a new version key. Never edit an existing version's text.
- Several languages: a version holds `['en' => ..., 'de' => ...]`. Render
  `Waitlist::purposes($list, locale: $locale)` (or `GET /waitlist/purposes?locale=`)
  and post back `{version, locale}` as served; such a version needs the locale.
- A form rendering its own copy of the text (CMS) adds `hash` = sha256 of the shown
  text; a mismatch is a 422. `WAITLIST_REQUIRE_WORDING_HASH=true` requires it.
- Wording written in the frontend or CMS: set `waitlist.catalog` to
  `StoredWordingCatalog` and register it on deploy with
  `php artisan waitlist:wording wording.json` (purpose => version => text; first
  `--from-definitions` to carry over what `purpose()` holds), or
  `Waitlist::registerWording($purpose, $version, $wording)`. Registered text is
  immutable: a change needs a new version.
- Wording sent by the project's own servers: `$project->wordingFromCallers()`; a
  signup posts `{version, locale?, text}` and a new version is registered with the
  server (`registered_by`, event `WordingRegistered`). Other text for a known
  version is a 422; guests sending text get a 403 (gate action `RegisterWording`).

### 3. Signup

Render the wording from the server and post back the versions shown:

```php
use Taldres\Waitlist\Facades\Waitlist;

$wording = Waitlist::purposes('default'); // list<PurposeWording>: purpose, version, text, required, locale; hash()

$result = Waitlist::for('default')->add($email, ['waitlist' => '2026-10', 'newsletter' => '2026-10']);
```

Over HTTP (with `WAITLIST_ROUTES_ENABLED=true`): `GET /waitlist/purposes?list=default`,
then `POST /waitlist` with `email`, `list`, and `purposes` as `{purpose: version}`.
Fields beyond the address go under `metadata`, and only those the project or list
defines are accepted.

### 4. Mails come from your listeners

The package never sends mail. Listen to `EntrySubscribed` for the confirmation mail:

```php
use Taldres\Waitlist\Events\EntrySubscribed;

public function handle(EntrySubscribed $event): void
{
    if (! $event->requiresConfirmation) {
        return;
    }

    Mail::to($event->entry->email)->send(new ConfirmWaitlist(
        confirmUrl: $event->confirmUrl,
        purposes: $event->subscription->consents,
        unsubscribeUrl: $event->unsubscribeUrl,
    ));

    // Record the mail reference alongside the consent wording.
    Waitlist::confirmationMailed($event->subscription, 'confirm-mail@2026-10');
}
```

- `$event->confirmUrl` comes from the `confirm` page of the project's `urls()` or,
  with `WAITLIST_ROUTES_ENABLED=true`, the package route. With neither it is
  `null`: set one before the first signup, or the mail goes out without a link.
- Every later mail carries a link that withdraws exactly its purpose:
  `Waitlist::unsubscribeUrl($entry, 'newsletter')` and
  `Waitlist::listUnsubscribeHeaders($entry, 'newsletter')`.
- Queued listeners implement `ShouldBeEncrypted`: payloads hold tokens and, for
  `EntryForgotten`, the address in plain text.

### 5. Before sending, check consent

Send to `recipients()`: only addresses with the purpose in force, once each, with
the right links (`unsubscribeUrl()`, `listUnsubscribeHeaders()`) and the consent's
`locale`.

```php
Waitlist::recipients('newsletter')->each(fn (Recipient $recipient) => ...);  // project-wide; links withdraw the purpose (a primary purpose: that list only)
Waitlist::for('beta')->recipients()->each(...);                              // one list; links leave it
$entry->hasConsentFor('newsletter');
```

### 6. Preference page and rights

- Mails carry only the unsubscribe token, which can only remove. The preference
  page needs a short-lived manage token, mailed on request: listen to
  `ManageLinkRequested` and send `$event->manageUrl` to `$event->entry->email`.
  Requests: `POST /waitlist/manage-link` with `token` (the unsubscribe token) or
  `email`, `Waitlist::requestManageLink($token)`,
  `Waitlist::for($list)->requestManageLink($email)`.
- With routes on, the page uses `GET /waitlist/manage/{token}`, `PUT .../purposes`,
  `POST .../data`, `POST .../unsubscribe`, and `POST .../erase` with
  `{"confirm": true}`; `410` once the link expired.
- On request: `Waitlist::allProjects()->personalData($email)`,
  `Waitlist::allProjects()->forget($email)` (every project; one controller),
  `Waitlist::for($list)->withdraw($email, $purpose)`, and
  `Waitlist::for($list)->forgetAll()` once the list's purpose is fulfilled.

## Rules, References, and Templates

- Events: `EntrySubscribed`, `EntryConfirmed`, `ConsentGranted`, `ConsentWithdrawn`,
  `EntryUnsubscribed`, `SubscriptionExpired`, `EntryForgotten`, `ManageLinkRequested`;
  all dispatch after commit.
- Personal data is always encrypted with Laravel's `encrypted` casts. When
  rotating `APP_KEY`, keep the old key in `APP_PREVIOUS_KEYS`, then run
  `php artisan waitlist:rekey` before retiring it.
- The package's models follow Laravel's encrypter, `Model::encryptUsing()`
  included. A key for the package only goes in a service provider's `boot()`:
  `Waitlist::encryptUsing($encrypter)` with any `Illuminate\Contracts\Encryption\Encrypter`.
  Its keys also make the lookup hash, so they must stay stable.
- `php artisan waitlist:privacy` prints the facts for the record of processing.
- The operator is responsible for lawful processing, valid consent, notices,
  justified retention, infrastructure security and provider agreements. The
  package offers no legal advice, certification or GDPR compliance warranty;
  refer to https://github.com/Taldres/laravel-waitlist/blob/main/docs/responsibility.md and the MIT License, subject to mandatory law.
- Retention defaults are not legal recommendations. Confirmed active entries and
  remaining reporting rows do not expire automatically. Review backups, queues,
  logs, exports and providers separately; remaining activity is not guaranteed anonymous.
- `waitlist:privacy` is an incomplete technical inventory, not a complete Art. 30
  record or compliance check. The package ships no application legal texts.

## Related skills

This skill covers the integration as a whole. For a focused task, load:

- `laravel-waitlist-frontend`: signup form, confirm, unsubscribe and preference pages, CORS, bot checks
- `laravel-waitlist-mail`: confirmation, preference link, welcome, launch and newsletter mails
- `laravel-waitlist-provider-sync`: Brevo, Mailchimp, Mailcoach in step, both ways
- `laravel-waitlist-projects`: several products in one app, resolvers, a central API
- `laravel-waitlist-reporting`: daily series, totals, the confirmed count over time
- `laravel-waitlist-launch`: invitations in batches, launch mail, erasing the list afterwards
- `laravel-waitlist-privacy-operations`: access and erasure requests, retention, key rotation, go-live check
- `laravel-waitlist-testing`: tokens from events, mail assertions, HTTP route tests

## Examples

- "Add a waitlist to the landing page": define a list with its primary purpose,
  render `Waitlist::purposes()` in the form, call `add()` with the versions shown,
  and send the confirmation mail from an `EntrySubscribed` listener.
- "Sync confirmed signups to Brevo": listen to `EntryConfirmed`, `ConsentGranted`,
  `ConsentWithdrawn`, `EntryUnsubscribed` and `EntryForgotten`, and use
  `$entry->purposes` to pick provider lists.
- Testing: `Event::fake([EntrySubscribed::class])`, subscribe, then
  `Event::assertDispatched(EntrySubscribed::class)`.

## Anti-patterns

- Writing to `waitlist_subscriptions`, `waitlist_consents` or `waitlist_activity` directly.
- Posting consent text from the client, or bundling two purposes into one checkbox.
- Mailing for a purpose without `recipients()`, `whereConsentedTo()` or `hasConsentFor()`.
- Changing state on GET or HEAD; confirm, unsubscribe, export and erase are POST.
- Querying `email` in SQL; addresses are encrypted, use `forEmail()`.
- Putting a manage link (`Waitlist::manageLink()`) into ordinary mails; they carry the
  unsubscribe link, and the manage link is mailed only on request.
