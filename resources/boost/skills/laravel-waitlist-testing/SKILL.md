---
name: laravel-waitlist-testing
description: >
  Write feature tests for an application's taldres/laravel-waitlist integration:
  get the plain tokens from events, assert the confirmation and other mails,
  test the signup, confirm and unsubscribe flows in PHP and over the package's
  HTTP routes, and bypass rate limits and bot checks in tests.
license: MIT
metadata:
  author: Dennis Petersmann
---

# Laravel Waitlist: testing the integration

Use this skill when writing or fixing tests in an application that uses
`taldres/laravel-waitlist`: its forms, listeners, mails and pages, not the
package itself.

## Primary Goal

- test the application's own code around the waitlist through the package's
  public API, with real tokens and real state changes

## Workflow

### 1. Setup

- The package migrations are published into `database/migrations`, so
  `RefreshDatabase` creates the tables.
- `APP_KEY` must be set in the test environment: addresses are encrypted and
  looked up with keys derived from it.
- Events implement `ShouldDispatchAfterCommit`; with `RefreshDatabase` they still
  dispatch as usual.
- Package routes are registered at boot. To test them, set
  `WAITLIST_ROUTES_ENABLED=true` in `phpunit.xml` or `.env.testing`;
  `config()->set()` inside a test is too late. An empty `WAITLIST_ROUTES_ENABLED=`
  is a config error; routes that are off answer `404`.
- The app's `WaitlistServiceProvider` boots in tests, so its lists, fields and
  pages are there. `Waitlist::define()` inside a test replaces that project for
  that test only; the next test boots the app again with the provider's
  definitions.
- Set other package config by key from the enum, not a string:
  `config()->set(ConfigKey::DoubleOptIn->value, false)`
  (`Taldres\Waitlist\Enums\ConfigKey`; the defaults live in the package's
  `config/waitlist.php`).
- A setup mistake throws `InvalidConfigurationException`, never a
  `WaitlistException`; assert it, do not catch it in app code. So does a config
  value that does not read, naming the key: `null` says never or off, and a number
  standing for "forever" is refused (a link may not end after 2038-01-19, a
  retention period may not reach back before 1970).

### 2. Post back what the form showed

Wording can be one text or one per locale; post back version and locale as served:

```php
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Support\PurposeWording;

$purposes = collect(Waitlist::purposes('beta'))
    ->filter(fn (PurposeWording $wording) => $wording->required)   // add optional ones as the test needs
    ->mapWithKeys(fn (PurposeWording $wording) => [$wording->purpose => ['version' => $wording->version, 'locale' => $wording->locale]])
    ->all();

$result = Waitlist::for('beta')->add('jane@example.com', $purposes);
```

### 3. Get the plain tokens

The plain confirm token exists only in the `EntrySubscribed` payload, on a list
with double opt-in. Unsubscribe and manage tokens also come from
`Waitlist::unsubscribeToken($entry)->token` and `Waitlist::manageLink($entry)->token`.

```php
use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\EntrySubscribed;

Event::fake([EntrySubscribed::class]);

Waitlist::for('beta')->add('jane@example.com', $purposes);

$event = Event::dispatched(EntrySubscribed::class)->first()[0];

$entry = Waitlist::confirm($event->confirmToken);
expect($entry->status)->toBe(EntryStatus::Confirmed);   // or $this->assertSame(...)
```

To keep the real listeners running (e.g. the mail listener) and still capture a
token, listen instead of faking:

```php
$token = null;
Event::listen(function (EntrySubscribed $event) use (&$token) {
    $token = $event->confirmToken;
});
```

`$event->unsubscribeToken` works the same for unsubscribe tests,
`ManageLinkRequested::$manageToken` for the preference page.

On a list with `->doubleOptIn(false)` (or with `waitlist.double_opt_in.enabled`
off), `EntrySubscribed` fires with `requiresConfirmation` false and `confirmToken`
null, then `EntryConfirmed`; the entry is confirmed already. A signup held back by
`max_pending_per_address` fires no `EntrySubscribed` at all (`$result->outcome` is
`confirmation_deferred`).

### 4. Assert the mails

```php
use Illuminate\Support\Facades\Mail;

Mail::fake();

Waitlist::for('beta')->add('jane@example.com', $purposes);

Mail::assertSent(ConfirmWaitlistMail::class, fn ($mail) => $mail->hasTo('jane@example.com'));
```

Queued listeners run inline with `QUEUE_CONNECTION=sync` (Laravel's test default).

### 5. HTTP routes

```php
use Illuminate\Routing\Middleware\ThrottleRequests;

$this->withoutMiddleware(ThrottleRequests::class);   // many signups from one test IP
Event::fake([EntrySubscribed::class]);

$wording = $this->getJson('/waitlist/purposes?list=beta')->assertOk()->json('data.0');

$this->postJson('/waitlist', [
    'email' => 'jane@example.com',
    'list' => 'beta',
    'purposes' => [$wording['purpose'] => ['version' => $wording['version'], 'locale' => $wording['locale']]],
])->assertStatus(202);

$token = Event::dispatched(EntrySubscribed::class)->first()[0]->confirmToken;

$this->postJson("/waitlist/confirm/{$token}")->assertOk()->assertJsonPath('data.status', 'confirmed');
$this->getJson('/waitlist/confirm/unknown')->assertNotFound()->assertJsonPath('error', 'invalid_token');
```

The package's own refusals name an `error` (`invalid_token`, `expired_token`,
`unknown_list`, `not_subscribed`, `list_unavailable`). Assert it: a `404` without
one is a wrong URL, routes that are off or the `useWaitlist` gate, and would pass
`assertNotFound()` for an unknown token.

Only the HTTP signup applies a project's fields; `add()` trusts its caller. Define
them in the test to assert the `422`:

```php
use Taldres\Waitlist\Definitions\ProjectDefinition;

Waitlist::define(function (ProjectDefinition $project): void {
    $project->purpose('waitlist', ['v1' => 'Email me.']);
    $project->list('teams', purpose: 'waitlist')->fields(['contact_phone' => ['required']]);
});

$this->postJson('/waitlist', [/* email, list, purposes */])->assertJsonValidationErrors('metadata.contact_phone');
```

Use `postJson()`/`getJson()`: without `Accept: application/json`, the pages in the
project's `urls()` turn answers into redirects.

A test that switches between a server caller and a guest calls `Auth::forgetGuards()`
in between: a guard built with `Auth::viaRequest()` keeps its user for the rest of
the test, so the guest request would still see the server.

### 6. Bot checks and erasure

```php
use Taldres\Waitlist\Events\EntryForgotten;

Waitlist::verifySpamUsing(fn () => true);    // bypass Turnstile & co. in tests
Waitlist::verifySpamUsing(fn () => false);   // assert the 422 "Spam check failed."
Waitlist::verifySpamUsing(null);             // back to the configured protector

Event::fake([EntryForgotten::class]);
Waitlist::allProjects()->forget('jane@example.com');
Event::assertDispatched(EntryForgotten::class, fn (EntryForgotten $event) => $event->email === 'jane@example.com');
```

## Rules, References, and Templates

- Assert state through the public API: `$entry->status`, `hasConsentFor()`,
  `Waitlist::for($list)->snapshot()`, `recipients()->count()`.
- Outcome of a signup: `$result->outcome` (`SubscribeOutcome`: `started`,
  `confirmation_resent`, `resend_suppressed`, `already_confirmed`, `resubscribed`,
  `confirmation_deferred`).
- Over HTTP, signups answer `202` for new and known addresses alike; assert state,
  not the response body.

## Examples

- "Test that signing up sends the confirmation mail": steps 2 and 4.
- "Test the confirm page": steps 3 and 5, or call the controller route with the
  captured token.
- "Test that the newsletter only goes to people who agreed": subscribe two
  addresses, one with the optional purpose, confirm both, assert
  `Waitlist::recipients('newsletter')->count() === 1`.

## Anti-patterns

- Creating `WaitlistEntry`, subscriptions or consents with factories or
  `create()`; go through `add()` and the token methods, or events and invariants
  are skipped.
- Reading tokens from the database columns; use the events or
  `Waitlist::unsubscribeToken()` and `Waitlist::manageLink()`.
- Enabling routes with `config()->set()` inside a test.
- Faking `EntrySubscribed` in a test that asserts the confirmation mail.
- Using arrow functions to capture a token: they capture by value.
