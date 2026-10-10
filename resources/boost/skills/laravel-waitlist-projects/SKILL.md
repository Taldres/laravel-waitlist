---
name: laravel-waitlist-projects
description: >
  Run the waitlists of several products in one Laravel app with
  taldres/laravel-waitlist projects: define projects, scope facade calls with
  project(), resolve the project of HTTP signups, send mail per project, handle
  requests across all projects, or keep projects in a database for a central
  waitlist API.
license: MIT
metadata:
  author: Dennis Petersmann
---

# Laravel Waitlist: several projects

Use this skill when one application collects waitlists for more than one product,
or when adding a second product to an existing `taldres/laravel-waitlist` setup.
With one product, nothing here is needed: `Waitlist::define()` without a name
describes the `default` project.

## Primary Goal

- every call, signup and mail acts for the right product, and nothing silently
  lands in the default project

## Workflow

### 1. Define the projects

Each project has its own purposes, lists, fields and pages; nothing is inherited
from the default project or another project. List names only need to be unique
within a project.

```php
// app/Providers/WaitlistServiceProvider.php, boot()
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Facades\Waitlist;

// The default project stays without lists, so a signup that forgets project()
// fails instead of landing on the wrong product. Its page catches unknown tokens.
Waitlist::define(fn (ProjectDefinition $project) => $project->urls(
    invalid: 'https://example.com/waitlist/oops',
));

Waitlist::define('rocket', function (ProjectDefinition $project): void {
    $project->purpose('waitlist', ['2026-10' => 'Email me when Rocket launches.']);
    $project->list('default', purpose: 'waitlist');
    $project->fields(fn () => ['source' => ['nullable', 'string', 'max:50']]);
    $project->urls(
        confirm: 'https://rocket.example/waitlist/confirm/{token}',
        unsubscribe: 'https://rocket.example/waitlist/leave/{token}',
        manage: 'https://rocket.example/waitlist/preferences/{token}',
    );
});

Waitlist::define('anvil', function (ProjectDefinition $project): void { /* same methods */ });
```

- Keeping the main product in the default project and naming the others also
  works; then every call without `project()` acts on the main product.
- Fields are per project and list: `$project->fields()` applies to every list, a
  list's own `->fields()` comes on top. Fields of one project never reach another;
  a project without fields takes no metadata over HTTP.
- Read the environment through `config()`, never `env()`. Defining a project again
  replaces it; the callback runs when the project is first needed.

### 2. Scope every call

```php
Waitlist::project('rocket')->for('default')->add($email, $purposes);
Waitlist::project('rocket')->purposes('default', app()->getLocale());
Waitlist::project('rocket')->recipients('newsletter');
Waitlist::project('rocket')->report()->since(30)->totals();
```

- Without `project()`, the facade acts on the default project, never on all.
- Token methods (`Waitlist::confirm()`, `unsubscribe()`, `withdrawConsent()`,
  `requestManageLink()`) need no project: a token belongs to its entry.
- An unknown project throws `UnknownProjectException`.

### 3. HTTP signups need a resolver

With `WAITLIST_ROUTES_ENABLED=true`, the signup, the wording and manage links
requested by address act for the project a `ProjectResolver` returns. The default
`DefaultProjectResolver` always returns `default`, so bind one:

```php
namespace App\Waitlist;

use Illuminate\Http\Request;
use Taldres\Waitlist\Contracts\ProjectResolver;

class SiteResolver implements ProjectResolver
{
    public function resolve(Request $request): string
    {
        $host = parse_url((string) $request->headers->get('Origin'), PHP_URL_HOST) ?: $request->getHost();

        return match ($host) {
            'rocket.example' => 'rocket',
            'anvil.example' => 'anvil',
            default => abort(403),
        };
    }
}
```

```php
// config/waitlist.php
'project_resolver' => App\Waitlist\SiteResolver::class,
```

- A signup without `list` goes to `waitlist.default_list` (`default`) of the
  resolved project: give each project a list of that name, or always post `list`.
- The resolver runs once per request, first, then the `useWaitlist` gate, both
  before the body is validated: the project decides which fields are accepted,
  and a refused request answers `401`/`403` unread.
- An unknown project from the resolver: signup `422`, wording `404`.
- Own controllers need no resolver; they call `Waitlist::project(...)` directly.
- Never authenticate in `waitlist.routes.middleware`; it also guards token links.

### 3b. Who may call: the `useWaitlist` gate and servers with credentials

The package asks Laravel's gate `useWaitlist` before the signup, the wording and a
manage link by address (never for token links), with
`(?Authenticatable $caller, string $project, WaitlistAction $action, ?string $list)`.
The caller comes from the first guard in `waitlist.authentication.guards` that
authenticates the request; `Waitlist::caller($request)` returns the same one. A guard
`config/auth.php` does not define is refused, naming it; token links do not wait for
it and are limited as a guest's request then.

- Default: everyone may; a caller implementing `HasWaitlistProject` only for its
  own project (`404` otherwise). With `waitlist.authentication.required`: guests
  `401`, callers without a project `403`.
- Replace it with `Gate::define('useWaitlist', ...)` in any provider; it wins
  whichever provider boots first. Return a bool or a gate response;
  `Response::denyWithStatus(401)` and `denyAsNotFound()` reach the client.
- Servers calling with tokens of their own: the token's model implements
  `HasWaitlistProject`, `project_resolver` is `AuthenticatedProjectResolver`
  (`401` without a caller, `403` without a project or for an undefined one),
  `authentication.guards` names the token guard, `authentication.required` is on.
  Issuing and revoking the tokens stays with the app (Sanctum, Passport, a JWT
  guard); gate them too, e.g. `Sanctum::authenticateAccessTokensUsing()` with
  `Waitlist::hasProject()`.
- Sanctum abilities are read with `tokenCan()`, not `can()`, which asks gates
  and policies.
- Such servers send for all their visitors from one address: the default limiters
  cap each server (`rate_limits.caller_signup_per_minute`, 120) and leave the
  limit per visitor to it, e.g. in a Next.js Server Action. Set
  `authentication.client_ip_header` (e.g. `X-Waitlist-Client-Ip`) to also limit
  per forwarded visitor; the header is never read from guests, and a value that is
  not an IP address is ignored.
- `$project->wordingFromCallers()` lets them send the consent text with the
  version (`{version, locale?, text}`), so it lives only on the site: the first
  signup registers it with the server; a known version must read the same. The
  gate asks `WaitlistAction::RegisterWording` for such signups; by default only
  callers acting for the project get it.

### 4. Mail per project

Listeners read `$event->entry->project` and pick sender, mailer and template from
it. Mail links already point at the project's own pages (`urls()`); a project
without them uses the package routes, or `null` with routes off.

### 5. Requests about a person cover every project

```php
Waitlist::allProjects()->personalData($email);
Waitlist::allProjects()->forget($email);
Waitlist::allProjects()->report();
```

`waitlist:show`, `waitlist:forget`, `waitlist:prune` and `waitlist:privacy` cover
every project without `--project`, unless a `--list` narrows them to that list of
the default project; `waitlist:export`, `waitlist:wording` and
`waitlist:forget --all` act on the default project unless `--project` is given.

### 6. Projects in a database

For projects that come and go at runtime, or manage their own lists and wording,
bind a `ProjectCatalog` (`waitlist.catalog`), usually extending
`StoredWordingCatalog`, with `policy()`, `fields()` (field => rules; stored rules
can only be strings), `urlPattern()`, `manageLinks()`, `projects()` (must include
`default`) and `lists()`, plus a resolver that maps a publishable key to a project. A catalog of
your own replaces the definitions. Projects register wording with
`Waitlist::project($key)->registerWording()`.

## Rules, References, and Templates

- All projects belong to one controller under the GDPR; projects are not tenant
  isolation. Serving other companies' waitlists needs contracts, separation and
  per-customer keys that projects do not provide.
- A withdrawal never reaches another project.
- `waitlist.project_resolver` and `waitlist.catalog` name a class that implements
  the contract, or an interface or abstract class your app binds in the container;
  the contract itself and a class the container cannot build are refused.
- Guides: https://github.com/Taldres/laravel-waitlist/blob/main/docs/projects.md,
  https://github.com/Taldres/laravel-waitlist/blob/main/docs/examples/several-products.md,
  https://github.com/Taldres/laravel-waitlist/blob/main/docs/examples/central-waitlist-api.md

## Examples

- "Add a waitlist for our second product": keep the first product in the default
  project or name it too, add a `Waitlist::define('<key>', ...)` for the second,
  scope its calls with `project()`, and bind a resolver if signups come over HTTP.
- "Delete everything about jane@example.com":
  `Waitlist::allProjects()->forget('jane@example.com')`.

## Anti-patterns

- Enabling the package routes with several projects and no resolver: every HTTP
  signup goes to `default`.
- Relying on the default gate to close an API: without
  `waitlist.authentication.required` or `AuthenticatedProjectResolver`, a caller
  that leaves out its token is a guest, and guests may.
- Calling `Waitlist::for($list)` for a product that has a project of its own.
- Expecting a project to inherit the purposes, lists, fields or pages of the
  default project.
- Answering a person's erasure request with `Waitlist::project($key)->forget()` when
  they are on several products.
