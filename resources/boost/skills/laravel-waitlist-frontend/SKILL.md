---
name: laravel-waitlist-frontend
description: >
  Build the pages of a taldres/laravel-waitlist integration: the signup form with
  the registered wording, and the confirm, unsubscribe and preference pages,
  either in Laravel controllers (Blade, Livewire, Inertia) or in an SPA or static
  site over the package's JSON API, including CORS and bot protection.
license: MIT
metadata:
  author: Dennis Petersmann
---

# Laravel Waitlist: frontend pages

Use this skill when building or changing the signup form, the confirmation page,
the unsubscribe page or the preference page for `taldres/laravel-waitlist`.

## Primary Goal

- forms show exactly the registered wording and post back what they showed; pages
  behind mail links never change state on GET

## Workflow

### 1. Pick the architecture

- **Laravel renders the pages** (Blade, Livewire, Inertia): call the `Waitlist`
  facade from controllers. The package routes can stay off, unless mails should
  carry RFC 8058 one-click `List-Unsubscribe` headers, which need them; the pages
  still render the UI either way.
- **SPA or static site without a Laravel backend for the pages**: set
  `WAITLIST_ROUTES_ENABLED=true` and call the JSON API under `/waitlist`.

Point the mail links at the app's own pages either way, in the project's
definition (`app/Providers/WaitlistServiceProvider.php`). Read the environment
through `config()`, never `env()`:

```php
$project->urls(
    confirm: config('app.frontend_url').'/waitlist/confirm/{token}',
    unsubscribe: config('app.frontend_url').'/waitlist/leave/{token}',
    manage: config('app.frontend_url').'/waitlist/preferences/{token}',
);
```

The same call takes `confirmed`, `expired`, `invalid`, `unsubscribed` and `erased`,
the pages a browser lands on after posting to the package routes.

### 2. Signup form

Render the wording from the package, never type it into the template:

```php
return view('waitlist.signup', [
    'purposes' => Waitlist::purposes('default', locale: app()->getLocale()),
]);
```

```blade
@foreach ($purposes as $wording)
    <input type="hidden" name="purposes[{{ $wording->purpose }}][version]" value="{{ $wording->version }}">
    <input type="hidden" name="purposes[{{ $wording->purpose }}][locale]" value="{{ $wording->locale }}">
    <input type="hidden" name="purposes[{{ $wording->purpose }}][hash]" value="{{ $wording->hash() }}">

    @if ($wording->required)
        <input type="hidden" name="agreed[]" value="{{ $wording->purpose }}">
        <p>{{ $wording->text }}</p>
    @else
        <label><input type="checkbox" name="agreed[]" value="{{ $wording->purpose }}"> {{ $wording->text }}</label>
    @endif
@endforeach
```

```php
use Illuminate\Support\Arr;
use Taldres\Waitlist\Exceptions\WaitlistException;
use Taldres\Waitlist\Http\Rules\PurposeChoice;
use Taldres\Waitlist\Support\RequestContext;

$validated = $request->validate([
    'email' => ['required', 'email:filter', 'max:255'],
    'purposes' => ['required', 'array'],
    'purposes.*' => ['required', new PurposeChoice],
    'agreed' => ['required', 'array'],
    'agreed.*' => ['string'],
]);

try {
    Waitlist::for('default')->add($validated['email'], Arr::only($validated['purposes'], $validated['agreed']), context: RequestContext::fromRequest($request));
} catch (WaitlistException $exception) {
    return back()->withErrors(['purposes' => $exception->getMessage()]);
}

return view('waitlist.check-your-inbox'); // the same answer for new and known addresses
```

Over HTTP: `GET /waitlist/purposes?list=default&locale=de`, then
`POST /waitlist` with `email`, `list` and `purposes` as
`{purpose: {version, locale, hash}}`. Post back the `locale` the response served,
which may be a fallback. Answers: `202` for every address, `422` names the field.

Calling from your own server instead (Next.js Server Actions, a BFF with a token
per project): keep the token server-side and limit each visitor there, since the
API sees only your server. With `wordingFromCallers()` on the project the server
may post `{version, locale?, text}` with the exact text the form showed instead of
rendering it from the API.

More than the address goes under `metadata`, and only for fields the project or
list defines (`$project->fields(...)`, `->fields(...)` on a list); anything else is
a `422`. In your own controllers validate with `Waitlist::for('default')->fields()`.

### 3. Confirm page

The mail link opens the page; the page posts the token back.

```php
use Taldres\Waitlist\Exceptions\ExpiredTokenException;
use Taldres\Waitlist\Exceptions\InvalidTokenException;

try {
    Waitlist::confirm($token);
} catch (ExpiredTokenException) {
    return view('waitlist.expired');
} catch (InvalidTokenException) {
    abort(404);
}
```

Over HTTP: `POST /waitlist/confirm/{token}` with `Accept: application/json`
answers `{"data": {..., "status": "confirmed"}}`, `404` unknown, `410` expired;
the package's own refusals name an `error`, e.g. `invalid_token`, and a `404`
without one means a wrong URL, routes that are off or a `useWaitlist` gate that
keeps the caller out.

### 4. Unsubscribe page

```php
// POST from the page; a link for one purpose carries ?purpose=
$purpose = $request->query('purpose');

is_string($purpose)
    ? Waitlist::withdrawConsent($token, $purpose)   // only that purpose
    : Waitlist::unsubscribe($token);                // leaves the list

Waitlist::requestManageLink($token);                // "manage preferences instead": mails a manage link
```

Both throw `InvalidTokenException` for an unknown token.

Over HTTP: `POST /waitlist/unsubscribe/{token}[?purpose=]` and
`POST /waitlist/manage-link` with `{"token": ...}`.

### 5. Preference page

Behind the manage token from the mailed link (expires after
`waitlist.manage.token_ttl` minutes, 60 by default, at least 1, never unlimited):
`GET /waitlist/manage/{token}` (status, purposes in force),
`PUT .../purposes` with the complete wanted set (the primary purpose must stay),
`POST .../data` (JSON copy), `POST .../unsubscribe`, `POST .../erase` with
`{"confirm": true}`. On `410`, offer to send a new link. Ask for an explicit
confirmation before erasing. In PHP: `Waitlist::findByManageToken($token)`,
`Waitlist::grantConsent($token, $purpose, $version, $locale)`.

Shape the `purposes` of that `PUT` like the signup's. With
`WAITLIST_REQUIRE_WORDING_HASH=true`, only a purpose being added needs its `hash`;
keeping or dropping one does not.

### 6. Protect the public endpoints

- Optional bot check on the signup and manage links by address (the default
  `NullSpamProtector` accepts every request), e.g. Cloudflare Turnstile:

  ```php
  Waitlist::verifySpamUsing(fn (Request $request): bool => Http::asForm()
      ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
          'secret' => config('services.turnstile.secret'),
          'response' => (string) ($request->header('X-Waitlist-Challenge') ?? $request->input('turnstile_token')),
          'remoteip' => $request->ip(),
      ])->json('success') === true);
  ```

  It guards the package routes only; in own controllers, check it yourself.
- Frontend on another origin: add `'waitlist', 'waitlist/*'` to `paths` in
  `config/cors.php`.
- Behind a proxy or CDN: configure trusted proxies, or all visitors share one rate
  limit bucket.
- Routes that are off (the default) answer `404` before any rate limit or session
  starts. Laravel still answers `405` for a method a route does not take and
  `OPTIONS` with the allowed methods before any middleware runs; neither acts. An
  empty `WAITLIST_ROUTES_ENABLED=` is a config error; `false` turns the routes off.
  The package puts its check, `CheckRouteConfig`, at the head of Laravel's middleware
  priority. An app that replaces that list later (`setMiddlewarePriority()` in a
  provider booting after the package, or in `booted()`) must list
  `Taldres\Waitlist\Http\Middleware\CheckRouteConfig` first; `priority()` in
  `bootstrap/app.php` needs nothing.

## Rules, References, and Templates

- Optional purposes are separate, unticked checkboxes; the primary purpose is
  agreed to by sending the form.
- Validation errors from the package routes are always `422` JSON.
- Set `Accept: application/json` on API calls, or browsers are redirected to the
  pages in the project's `urls()`.
- Full guides: https://github.com/Taldres/laravel-waitlist/blob/main/docs/getting-started.md,
  https://github.com/Taldres/laravel-waitlist/blob/main/docs/examples/landing-page-spa.md,
  https://github.com/Taldres/laravel-waitlist/blob/main/docs/securing-the-endpoints.md

## Examples

- "Add a waitlist form to the Blade landing page": steps 2 to 4 in controllers.
- "Our Nuxt site needs a waitlist": `WAITLIST_ROUTES_ENABLED=true`, CORS, then the
  HTTP variants of steps 2 to 5.
- "Add Turnstile to the waitlist form": step 6, and send the token in the
  `X-Waitlist-Challenge` header, or as `turnstile_token` from a plain form.

## Anti-patterns

- Confirming or unsubscribing on GET; mail scanners follow every link.
- Typing the consent text into the template, or posting text instead of versions.
- Telling apart new and known addresses in the response.
- Putting authentication into `waitlist.routes.middleware`: it also guards the
  token links and one-click unsubscribes. Use a `ProjectResolver` or own
  controllers for closed signups, and `waitlist.routes.group_middleware.signup`
  for checks on the forms only, such as CSRF or an origin check.
- A pre-ticked checkbox for an optional purpose, or one checkbox for two purposes.
