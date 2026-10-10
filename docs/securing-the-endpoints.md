# Securing the endpoints

The subscribe endpoint is public by nature: an anonymous visitor enters an
email. "Public" does not mean "unprotected". Nothing looks up data by address
over HTTP: `show`, `export`, `personalData` and `forget` exist only as artisan
commands and PHP APIs. A person reaches their own data and erasure only through
a manage link that was mailed to their address and expires.

## The three architectures

**A: Frontend talks directly to the package routes** (`WAITLIST_ROUTES_ENABLED=true`).
Right for static landing pages without their own backend routing. What protects
you out of the box:

| Threat | Protection |
| --- | --- |
| Spam / flooding | [Rate limits](#rate-limits) per group (signups per IP and endpoint, token links per token; replaceable) plus the `SpamProtector` hook (below) |
| Mail-bombing one address | A repeat signup is a resend, capped by `resend_cooldown` and `max_confirmations`; across lists, `max_pending_per_address` caps the confirmation requests per day; manage links by address only go to confirmed addresses, once per `manage.request_cooldown` |
| Overwriting someone's data | A signup for an address with a running cycle never changes its metadata or consents; that takes the mailbox. After someone has left, a new signup starts a new cycle that again needs the confirmation |
| Email enumeration | Subscribe and manage-link requests always answer `202` with an identical body; queue the listeners that send mail, so response times do not tell either |
| Subscribing someone else's email | Double opt-in; foreign addresses never reach `confirmed` |
| Token guessing | 64-char random tokens, looked up via SHA-256 hash, optional TTL |
| Forwarded mails, providers reading headers | The token in every mail can only remove; the data and erasure need a manage link that is mailed to the address on request and expires |
| Link scanners acting for the user | `GET` and `HEAD` never change state and never return the address; confirming, unsubscribing, exporting and erasing need `POST` |
| Tokens sent to another host | Links to the package routes are built from `APP_URL`, never from the Host header of the request that triggered the mail |
| Forged consent wording | Clients send purpose versions only; the stored text comes from the catalog, or, for a project that takes it from its servers, from those servers alone ([C](#the-three-architectures)) |

**B: Through your own backend (BFF pattern).** Package routes stay disabled;
your controller calls the actions:

```php
use Taldres\Waitlist\Exceptions\WaitlistException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Http\Rules\PurposeChoice;
use Taldres\Waitlist\Support\RequestContext;

Route::post('/api/waitlist', function (Request $request) {
    $validated = $request->validate([
        'email' => ['required', 'email:filter', 'max:255'],
        'purposes' => ['required', 'array'],
        'purposes.*' => ['required', new PurposeChoice], // a version, or {version, locale, hash}
    ]);

    try {
        Waitlist::for('beta')->add($validated['email'], $validated['purposes'], context: RequestContext::fromRequest($request));
    } catch (WaitlistException $exception) {
        return response()->json(['message' => $exception->getMessage()], 422);
    }

    return response()->json(['message' => 'Subscribed.'], 202);
})->middleware('throttle:waitlist'); // the limiter is registered even with routes disabled
```

Right when a backend already exists, or for flows behind login, for example a
"notify me when available" feature for authenticated shop customers. There your
normal app auth applies; the package doesn't need to know about it.

`add()` stores the metadata it is given. If your form carries any, validate it
with the list's own rules, `Waitlist::for('beta')->fields()`, see
[Fields](projects.md#fields).

**C: Your server calls the package routes with credentials of its own:** a
central waitlist API for several sites, each site's server (Next.js Server
Actions or route handlers, any backend) posting to `/waitlist`, so no token ever
reaches the browser. Set it up with `authentication.guards`,
`AuthenticatedProjectResolver` and `authentication.required`, see
[Servers with credentials](projects.md#servers-with-credentials-authenticatedprojectresolver).
Two things change against A:

- **Limiting visitors is your server's job.** The API sees your server's
  address for every visitor, so a limit per IP would throttle all of them
  together. For a caller acting for a project, the package caps the server as a
  whole and leaves the limit per visitor to it: in the Server Action, before it
  calls the API, next to the form's bot protection. To limit per visitor in the
  API too, forward the visitor's address, see
  [Servers calling for a project](#servers-calling-for-a-project).
- **The wording can come from your server.** A project defined with
  `wordingFromCallers()` takes the text along with the version, so it lives only
  on the site, see [Wording sent by your servers](purposes-and-wording.md#wording-sent-by-your-servers).

```ts
// app/actions/join-waitlist.ts
"use server"

import { headers } from "next/headers"
import { allowSignup } from "@/lib/rate-limit" // yours: Upstash, Redis, in memory

const CONSENT = "Email me when Acme launches. I can unsubscribe with the link in every email."

export async function joinWaitlist(email: string) {
  // The visitor's address as your proxy reports it; trust only your proxy's header.
  const visitor = (await headers()).get("x-real-ip") ?? "unknown"

  if (!(await allowSignup(visitor))) {
    return { status: "rate_limited" as const }
  }

  const response = await fetch(`${process.env.WAITLIST_API_URL}/waitlist`, {
    method: "POST",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      Authorization: `Bearer ${process.env.WAITLIST_API_TOKEN}`,
      "X-Waitlist-Client-Ip": visitor, // read only with authentication.client_ip_header
    },
    body: JSON.stringify({
      email,
      list: "beta",
      purposes: { waitlist: { version: "2026-10", text: CONSENT } },
    }),
  })

  return { status: response.status === 202 ? "accepted" as const : "failed" as const }
}
```

## Where should mail links point?

At your pages, which post the token back to the package. A GET on a package
route, from an old link or a mail client opening the `List-Unsubscribe` header,
only reports state and redirects to your page. The page can be a single button,
and that is the point: mail scanners follow every GET link they see, so a safe
method must not change anything. Mail clients with one-click support skip the
page entirely when your mails carry `Waitlist::listUnsubscribeHeaders($entry)`.

The pages to set with `urls()` in the project's definition, and what happens when
they are unset, are in the [HTTP API reference](reference/http-api.md#where-mail-links-point);
pages that call the endpoints are in [the SPA example](examples/landing-page-spa.md).

## Rotating `APP_KEY`

Addresses and unsubscribe tokens are encrypted with the application key. Keep the
old key in `APP_PREVIOUS_KEYS` when you rotate it, or links sent earlier stop
working; see [Encryption and keys](encryption-and-keys.md#rotating-app_key).

## Consent records

Each consent is stored with the cycle it belongs to and frozen there: the models
reject any change to the wording and refuse to delete a consent or a cycle on its
own. A later cycle records its own consents and never rewrites an earlier one.
(This is an application-level guarantee via model events, not a database one;
raw SQL still bypasses it.)

```php
$entry->currentSubscription->consents;  // purpose, version, text, granted_at, withdrawn_at
$entry->purposes;                       // purposes in force
$entry->hasConsentFor('newsletter');
```

Request IP and user agent land on the activity log only when `privacy.store_ip`
and `privacy.store_user_agent` allow it; retention clears them after
`request_metadata_days`, and an erasure clears them at once.

## CORS

If your SPA runs on a different origin than the API, configure CORS in the
consuming app, since the package cannot decide this for you. Add the waitlist
path in `config/cors.php`:

```php
'paths' => ['api/*', 'waitlist', 'waitlist/*'],
```

## Who may sign up (closed betas, central APIs)

`routes.middleware` applies to every package route, including the links in your
mails and one-click requests from mail providers, which carry no credentials.
Never put authentication there. Decide in the `useWaitlist` gate instead: it runs
for the signup, the wording and manage links requested by address, after the
project is resolved and before the body is validated. Checks that only your
forms have to pass go into `routes.group_middleware.signup`, see
[Checks for your forms only](#checks-for-your-forms-only).

```php
// app/Providers/WaitlistServiceProvider.php
Gate::define('useWaitlist', fn (?User $user) => $user?->can('join-beta') === true);
```

```php
// config/waitlist.php
'authentication' => ['guards' => ['sanctum']],
```

The project a request acts for comes from the `ProjectResolver`; for servers that
call with credentials of their own, `AuthenticatedProjectResolver` takes it from
them. See [Who may call](projects.md#who-may-call-the-usewaitlist-gate).

## Checks for your forms only

`routes.group_middleware` adds middleware to one group, after its rate limit,
apart from what Laravel always sorts ahead of it, such as the cookies and the
session of `web`, see [Middleware order](#middleware-order). Checks that only
your own forms can pass belong on `signup`: the token links in your mails, and
the one-click requests mail providers send without cookies or credentials,
never face them.

```php
// config/waitlist.php
'routes' => [
    'group_middleware' => [
        'signup' => ['web'], // sessions and CSRF for the signup, the wording and manage links
        'links' => [],
    ],
],
```

**CSRF** fits a form that the same app serves, or a site whose requests carry
the app's cookies. `GET /purposes` then sets the `XSRF-TOKEN` cookie, and the
form sends it back in the `X-XSRF-TOKEN` header; axios does that on its own,
`fetch` needs the header set. A site on a subdomain also needs
`credentials: "include"` and `supports_credentials` in your CORS config. A
frontend on another domain gets no cookie of yours at all: give it an origin
check and bot protection instead.

CSRF keeps other websites from posting your form in their visitors' browsers.
It does not stop a script, which fetches the token first; that is what the
[`SpamProtector`](#bot-protection-via-spamprotector) and the rate limits are for.

**An origin check** keeps browsers on other websites out:

```php
// app/Http/Middleware/OwnOrigins.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OwnOrigins
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        // Browsers always send it on a POST; servers calling the API do not.
        abort_unless($origin === null || in_array($origin, config('services.waitlist.origins'), true), 403);

        return $next($request);
    }
}
```

```php
'group_middleware' => ['signup' => [App\Http\Middleware\OwnOrigins::class], 'links' => []],
```

A browser cannot fake its `Origin`; a script outside one can, so this, too,
keeps out other websites, not bots.

## Middleware order

Every package route starts with a check of the `waitlist.routes` settings, ahead
of the rate limit and the middleware you configure. It answers `404` while
`routes.enabled` is off, which also covers a route cache that still holds the
routes, and refuses a request with an `InvalidConfigurationException` naming the
key when a setting of its group does not read.

The check is not in Laravel's middleware priority list, so Laravel would sort
the rate limit, route bindings and, with `web`, the cookies and the session ahead
of it. The package therefore puts the check at the head of that list when it boots.
A route that is off then neither counts against a limiter nor starts a session,
and answers `404` even when a rate limit does not read.
Middleware of your own that the list does not name is not moved: it runs ahead
of the check only if you list it in `routes.middleware` before everything the
list does name, the `api` group for one.

An app that sets the list with `priority()` in `bootstrap/app.php` is covered:
Laravel applies it when the HTTP kernel resolves, before any provider boots, and
the package then adds the check in front of it. An app that replaces the list
afterwards, with `setMiddlewarePriority()` in a provider that boots after the
package or in a `booted()` callback, takes the check out again. A route that is
off then counts against its limiter and answers `429` once the limit is used up.
List the check first in your own list:

```php
use Taldres\Waitlist\Http\Middleware\CheckRouteConfig;

$kernel->setMiddlewarePriority([
    CheckRouteConfig::class,
    // the rest of your list
]);
```

A route that is off still answers a method it does not take with `405` and
`OPTIONS` with the methods it allows, as Laravel answers both before any
middleware runs, a route cache included. Nothing acts on them: no entry is
written and no event fires.

## Rate limits

The routes fall into two groups, each throttled by its own named limiter:

| Group | Routes | Default limiter | Keyed by |
| --- | --- | --- | --- |
| `signup` | `POST /waitlist`, `GET /purposes`, `POST /manage-link` | `waitlist` | client IP and endpoint; for a [server calling for a project](#servers-calling-for-a-project), the server and endpoint |
| `links` | everything with a `{token}` | `waitlist-links` | the token, plus a looser ceiling per IP, or per server |

Token links are limited per token because one-click unsubscribes arrive from mail
providers' servers, many recipients behind a few addresses; a tight IP limit
would turn them away. The ceiling per IP still bounds what one client can send
with made-up tokens.

There are three levels of control.

**Tune the numbers:**

```php
// config/waitlist.php
'routes' => [
    'rate_limits' => [
        'signup_per_minute' => 10,          // WAITLIST_RATE_LIMIT_SIGNUP
        'link_per_minute' => 10,            // WAITLIST_RATE_LIMIT_LINK
        'links_per_ip_per_minute' => 600,   // WAITLIST_RATE_LIMIT_LINKS_PER_IP
        'caller_signup_per_minute' => 120,  // WAITLIST_RATE_LIMIT_CALLER_SIGNUP
    ],
],
```

**Use a limiter of your own** for one group, without touching the other. Define
it under a name of your own and point the group at it:

```php
// config/waitlist.php
'routes' => [
    'limiters' => [
        'signup' => 'waitlist-signup',
        'links' => 'waitlist-links',
    ],
],
```

Define it in a service provider's `boot()`, so it exists by the first request.
A name that no `RateLimiter::for()` defines refuses the requests of the group
with an `InvalidConfigurationException` that names the config key, token links
included.

Your limiter then decides everything for that group, the way any Laravel
[named rate limiter](https://laravel.com/docs/routing#rate-limiting) does; the
numbers under `rate_limits` only tune the package's own two:

- **what is counted**: `->by()` takes any key, the IP, the user, the project,
  a header, or a combination
- **how much**: one limit or several at once, per minute, hour or day
- **per endpoint**: `$request->routeIs()` tells the routes of a group apart
- **who is exempt**: `Limit::none()`, for example for your own backend
- **the answer**: `->response()` replaces the default `429`

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Taldres\Waitlist\Contracts\ProjectResolver;

public function boot(): void
{
    RateLimiter::for('waitlist-signup', function (Request $request) {
        if ($request->ip() === config('services.bff.ip')) {
            return Limit::none();
        }

        // Route names follow waitlist.routes.name, "waitlist." by default.
        if ($request->routeIs('waitlist.manage-link')) {
            return Limit::perHour(5)->by($request->ip());
        }

        $client = $request->user()?->getAuthIdentifier() ?? $request->ip();
        $project = app(ProjectResolver::class)->resolve($request);

        return [
            Limit::perMinute(10)->by("{$project}|{$client}"),
            Limit::perDay(100)->by($request->ip())->response(
                fn (Request $request, array $headers) => response()->json(['message' => 'Too many signups today.'], 429, $headers),
            ),
        ];
    });
}
```

Fixed is only which routes belong to which group. Within a group, `routeIs()`
covers the rest; for a different split altogether, add middleware to one group
with `routes.group_middleware` or to every route with `routes.middleware`, or keep
the package routes off and call the actions
from routes of your own, each with any `throttle:` you like (pattern B above).

Think twice before replacing `links`: a limiter keyed by IP alone brings back
failed one-click unsubscribes.

**Turn a group off** with `null`, for example when Cloudflare or a WAF limits
already. The `waitlist` and `waitlist-links` limiters stay registered either way,
so your own routes can use them too (`->middleware('throttle:waitlist')`).

**Behind a proxy or load balancer:** `$request->ip()` only returns the real
client IP when [trusted proxies](https://laravel.com/docs/requests#configuring-trusted-proxies)
are configured. Without that, every request appears to come from the proxy, so
all clients share a single bucket — legitimate users throttle each other and the
limiter does little against a real attacker. Configure `trustProxies(...)` in
`bootstrap/app.php` for any deployment that sits behind a reverse proxy, CDN, or
load balancer.

### Servers calling for a project

A caller acting for a project (`HasWaitlistProject`, see
[Who may call](projects.md#who-may-call-the-usewaitlist-gate)) is a server, and
every one of its visitors arrives from its address. The default limiters treat
it as one source:

- signups, wording and manage links by address are capped per server and
  endpoint at `caller_signup_per_minute`, 120 by default. That cap is a ceiling
  against a leaked token, not a limit per visitor;
- the ceiling on token requests counts the server once, not each of its
  addresses.

Limit each visitor where you still see them: in your server, before it calls
the API, as in [C](#the-three-architectures). To limit per visitor in the
API as well, let your servers forward the visitor's address and name the header:

```php
// config/waitlist.php
'authentication' => [
    'client_ip_header' => 'X-Waitlist-Client-Ip', // WAITLIST_CLIENT_IP_HEADER
],
```

Then a signup counts against `signup_per_minute` per visitor and endpoint as
well, token requests count per visitor, and with `privacy.store_ip` the
visitor's address is the one recorded. The header is read from callers with a
project only, never from guests, who could send any address; a value that is
not an IP address is ignored. A visitor the per-visitor limit refuses does not
use up the server's cap.

## Bot protection via SpamProtector

The package ships a `SpamProtector` contract with a no-op default. It guards the
two endpoints where anyone can type an address: the signup (`POST /waitlist`)
and a manage link requested by address (`POST /waitlist/manage-link` with
`email`), so a form for either must send what your check expects. There are two
ways to plug in your own check.

### The quick path: a closure

Register a closure in your `AppServiceProvider::boot()` — no class, no config
change. This example verifies Cloudflare Turnstile using Laravel's own `Http`
facade, so it needs no extra dependency:

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Taldres\Waitlist\Facades\Waitlist;

public function boot(): void
{
    Waitlist::verifySpamUsing(function (Request $request): bool {
        $token = $request->input('turnstile_token');

        if (! is_string($token) || $token === '') {
            return false;
        }

        return Http::asForm()
            ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => config('services.turnstile.secret'),
                'response' => $token,
                'remoteip' => $request->ip(),
            ])
            ->json('success') === true;
    });
}
```

A registered closure always wins over the `waitlist.spam_protector` config
key; pass `null` to remove it and fall back to the configured class again.

### The class path: implement the contract

Prefer a dedicated class when you want constructor injection or to unit-test
the check in isolation. The class lives in your app:

```php
// app/Waitlist/TurnstileProtector.php
namespace App\Waitlist;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Taldres\Waitlist\Contracts\SpamProtector;

class TurnstileProtector implements SpamProtector
{
    public function passes(Request $request): bool
    {
        $token = $request->input('turnstile_token');

        if (! is_string($token) || $token === '') {
            return false;
        }

        return Http::asForm()
            ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => config('services.turnstile.secret'),
                'response' => $token,
                'remoteip' => $request->ip(),
            ])
            ->json('success') === true;
    }
}
```

```php
// config/waitlist.php
'spam_protector' => App\Waitlist\TurnstileProtector::class,
```

Either way, a failed check returns `422 {"message": "Spam check failed."}`.
The same pattern works for reCAPTCHA, hCaptcha, or a simple honeypot field
(the check returns false when the hidden field is filled).

**Scope:** the protector guards only the package's HTTP endpoint. Actions and
your own controllers are never affected. If you build your own controller
(architecture B), inject and consult the protector yourself.
