# Projects, lists and fields

A **project** is one product you collect a waitlist for. It has its own purposes,
lists, fields, wording and frontend pages. You describe it in code, in a service
provider, with `Waitlist::define()`. Most apps have exactly one project, called
`default`, and the facade acts on it.

| You have | Use | Read |
| --- | --- | --- |
| One product | the `default` project | [One product](#one-product-the-default-project) |
| A few products in one app | one `Waitlist::define()` per product | [Several products](#several-products-in-one-app), [example](examples/several-products.md) |
| A central API for all your products, which manage their own lists and wording | a database-backed `ProjectCatalog` and a `ProjectResolver` | [A central waitlist API](examples/central-waitlist-api.md) |

All projects belong to one controller. Projects organize waitlists; they are not
tenant isolation, see [Privacy across projects](#privacy-across-projects).

## Where the definition lives

`php artisan waitlist:install` publishes `app/Providers/WaitlistServiceProvider.php`
with a default project to start from and registers it in
`bootstrap/providers.php`; with `--projects` it starts with
[several projects](#several-products-in-one-app) instead. The definition can live
in any provider's `boot()`, your `AppServiceProvider` included; the published one
keeps it in one place.

```php
// app/Providers/WaitlistServiceProvider.php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Facades\Waitlist;

class WaitlistServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Waitlist::define(function (ProjectDefinition $project): void {
            $project->purpose('waitlist', ['2026-10' => 'Email me when early access opens.']);
            $project->list('default', purpose: 'waitlist');
        });
    }
}
```

Why code and not `config/waitlist.php`:

- **Validation rules can be objects.** `config:cache` cannot store objects or
  closures, so a config file only takes string rules. A definition takes
  `Rule::enum()`, `Rule::in()`, `Rule::requiredIf()` and your own
  `ValidationRule` classes.
- **Mistakes fail.** A misspelt key in a config array is ignored without a word.
  A misspelt method or named argument is a PHP error, and the definition checks
  what it is given: a list whose primary purpose is optional too, a mail link
  without `{token}` or a malformed wording throws an `InvalidConfigurationException`.
- **Everything about a project is in one place**, next to the code that uses it.

The config keeps the settings that are about running the waitlist, not about a
product: double opt-in, retention, rate limits, routes and the models, see
[Configuration](reference/configuration.md). The contracts are bound in the
container instead, see [Extending](extending.md).

**Read the environment through `config()`, never `env()`.** Once the config is
cached, `env()` returns `null` outside the config files, so a URL built from it
would silently fall back to the package routes. When a page's URL differs per
environment, add a key to a config file of your own and read it with `config()`.

## One product: the default project

Without a name, `Waitlist::define()` describes the default project:

```php
Waitlist::define(function (ProjectDefinition $project): void {
    $project->purpose('waitlist', ['2026-10' => 'Email me when early access opens.']);
    $project->purpose('newsletter', ['2026-10' => 'Also send me the monthly newsletter.']);

    $project->list('default', purpose: 'waitlist')->optional('newsletter');
    $project->list('beta', purpose: 'waitlist')->optional('newsletter');

    $project->urls(
        confirm: 'https://example.com/waitlist/confirm/{token}',
        unsubscribe: 'https://example.com/waitlist/leave/{token}',
        manage: 'https://example.com/waitlist/preferences/{token}',
    );
});
```

You never name the project:

```php
Waitlist::for('beta')->add($email, ['waitlist' => '2026-10']);
Waitlist::recipients('newsletter');
Waitlist::report()->since(30)->totals();
Waitlist::forget($email);
```

Over HTTP, the package routes act for the default project too: the built-in
`DefaultProjectResolver` always answers `default`. Several lists, such as
`default` and `beta` above, are still one project: they share the purposes, the
pages and the withdrawal rule, see [Lifecycle](lifecycle.md#what-a-withdrawal-reaches).

[Getting started](getting-started.md) walks through it end to end.

## Purposes

```php
$project->purpose('waitlist', [
    '2026-09' => 'Email me when early access opens.',
    '2026-10' => ['en' => 'Email me when early access opens.', 'de' => 'Schreibt mir, wenn der Zugang startet.'],
]);
```

A purpose is what people can agree to, with its wording per version: one text,
or a text per locale. The last version is the current one; the earlier ones stay
accepted, so a form loaded before a change still records what it showed. Never
edit a version's wording, add a new version. Leave a version out to stop
accepting it; stored consents keep their wording. A purpose defined with no
versions is retired: lists stop offering it, and stored consents can still be
withdrawn.

Defining a purpose again replaces it. Names and versions are at most 255
characters, locales at most 35. More in
[Purposes and wording](purposes-and-wording.md), including wording that a CMS or
your frontend registers instead.

When the project's servers call the API for their visitors, they can bring the
wording with each signup instead, so it lives only on the site that shows it:

```php
$project->wordingFromCallers();
```

The project then needs no `purpose()` at all; see
[Wording sent by your servers](purposes-and-wording.md#wording-sent-by-your-servers).

## Lists

```php
$project->list('beta', purpose: 'waitlist')
    ->optional('newsletter')
    ->doubleOptIn(false);
```

Only defined lists accept signups. Each has exactly one **primary purpose**,
required for every signup and never bundled with another, and any number of
**optional purposes** a person grants and withdraws on their own. `optional()`
adds to what it was given before; a list that names its primary purpose as
optional too throws.

| Method | Default | |
| --- | --- | --- |
| `list(string $name, string $purpose)` | | defines the list; defining it again replaces it |
| `->optional(string ...$purposes)` | none | purposes granted on their own |
| `->doubleOptIn(bool $enabled = true)` | `waitlist.double_opt_in.enabled` | overrides the global switch for this list |
| `->fields(Closure\|array $rules)` | none | see [Fields](#fields) |
| `->publicCount(bool $enabled = true)` | off | answers `GET /waitlist/count` for this list, rounded, with double opt-in only, see [Count and position](reference/http-api.md#count-and-position) |
| `->publicPosition(bool $enabled = true)` | off | lets the unsubscribe token read the person's place in line on this list, with double opt-in only |

**`*` takes every list name the project does not define.** It suits lists made
up at runtime, one per product page for example:

```php
$project->list('*', purpose: 'waitlist');

Waitlist::for('product-42')->add($email, ['waitlist' => '2026-10']);
```

A named list wins over `*`. List names are what a signup can post: at most 100
characters, starting with a letter or digit, then letters, digits, `.`, `_`, `:`
and `-`.

## Fields

A signup may carry more than the address: a country, the company someone
works for, where they found the form. Each field is personal data, so the HTTP signup
accepts only the fields a project or list defines, with their validation rules,
and refuses everything else with a `422`. Without fields, it refuses all
metadata. Accepted values are stored encrypted in the entry's `metadata`.

```php
use App\Rules\CountryCode;
use Illuminate\Validation\Rule;

Waitlist::define('acme', function (ProjectDefinition $project): void {
    $project->purpose('waitlist', ['2026-10' => 'Email me when Acme launches.']);

    // Every list of the project
    $project->fields(fn () => [
        'country' => ['required', new CountryCode],
        'role' => ['nullable', Rule::enum(JobRole::class)],
    ]);

    $project->list('individuals', purpose: 'waitlist');

    // On top of the project's fields, for this list only
    $project->list('teams', purpose: 'waitlist')->fields(fn () => [
        'company' => ['required', 'string', 'max:120'],
        'contact_phone' => ['nullable', 'string', 'max:40'],
    ]);
});
```

- **Project fields apply to every list** of the project; a list's own fields come
  on top. A field the list defines again replaces the project's: make `company`
  optional for the project and required on `teams`, for example.
- **`*` has fields of its own**, and they apply only to the lists the project does
  not name.
- **Fields of one project never reach another**: a signup for a project without
  fields can send no metadata at all.
- **Pass a closure when the rules hold objects.** It runs for every validation, so
  each request gets new rule objects. A rule implementing `DataAwareRule` or
  `ValidatorAwareRule` is handed the request's data; shared across requests, as
  under Octane, it would carry one person's data into the next request. An array
  of string rules can be passed as it is.
- The closure runs without the request: the processing record lists the fields
  without one. Logic that depends on the request belongs in rules such as
  `Rule::requiredIf()`.
- Field names start with a letter and contain letters, digits, `_` and `-`. A rule
  refers to another field by its full key: `required_with:metadata.company`.

Over HTTP, the fields are posted under `metadata`; a refused key or value names
the field, as `metadata` or `metadata.country`:

```http
POST /waitlist

{"email": "jane@example.com", "list": "teams", "purposes": {"waitlist": "2026-10"},
 "metadata": {"country": "DE", "company": "Initech"}}
```

`add()` trusts its caller and stores whatever metadata it is given. In your own
controllers, validate with the same rules:

```php
$fields = Waitlist::project('acme')->for('teams')->fields();   // field => rules

$validated = $request->validate([
    'email' => ['required', 'email:filter'],
    'metadata' => ['array:'.implode(',', array_keys($fields))],
    ...collect($fields)->mapWithKeys(fn ($rules, $field) => ["metadata.{$field}" => $rules])->all(),
]);
```

Metadata is only taken when a cycle starts, see
[Lifecycle](lifecycle.md). `waitlist:privacy` lists the fields per project and
list for your record of processing; keep them as few as you can.

## Pages

```php
$project->urls(
    confirm: 'https://app.example.com/waitlist/confirm/{token}',
    unsubscribe: 'https://app.example.com/waitlist/leave/{token}',
    manage: 'https://app.example.com/waitlist/preferences/{token}',
    invalid: 'https://app.example.com/waitlist/oops',
);
```

| Page | Where it is used |
| --- | --- |
| `confirm` | the link in the confirmation mail |
| `unsubscribe` | the link in every mail |
| `manage` | the link in the preference page mail |
| `confirmed` | where a browser lands after confirming |
| `expired` | where a browser lands with an expired token |
| `invalid` | where a browser lands with an unknown token |
| `unsubscribed` | where a browser lands after leaving |
| `erased` | where a browser lands after erasing |

`{token}` is replaced, and the three mail links need it. A link for one purpose
gets `?purpose=`. Unset mail links use the package routes when they are enabled,
rooted at `APP_URL`; with the routes off, they are `null`. Unset landing pages
answer JSON. `urls()` adds to an earlier call, and `null` or an empty string
leaves a page as it is. See [HTTP API](reference/http-api.md#where-mail-links-point).

A link with an unknown token belongs to no project, so it lands on the default
project's `invalid` page.

A project without a preference page turns manage links off:

```php
$project->manageLinks(false);
```

No manage link is mailed then, and a request for one is refused (see
[The preference page link](mail.md#the-preference-page-link)). People still
leave through the unsubscribe link in every mail; requests for access or erasure
reach you and go through `waitlist:export` and `waitlist:forget`.

### Websites that may use a project

A project used from a browser on another site lists that site:

```php
$project->origins(['https://acme.example']);
```

A guest request from any other website is refused with `403`. CORS stays in
`config/cors.php`, which lists the websites of all projects together. A server
calling for the project and a request without an `Origin` header are not
affected. Details in
[Securing the endpoints](securing-the-endpoints.md#websites-that-may-use-a-project).

### Periods a project promises itself

`waitlist.retention` and `waitlist.double_opt_in.token_ttl` are global. In a
central API, one site may tell its visitors that unconfirmed signups are deleted
within seven days, while another keeps the thirty days of the configuration. A
project says so in its definition:

```php
$project
    ->retention(pendingDays: 7, unsubscribedDays: 365, requestMetadataDays: 14)
    ->confirmLinkLifetime(60 * 24 * 7);   // minutes

// Ask confirmed addresses again after a year; whoever does not answer
// within 30 days leaves the list.
$project->retention(reconfirmAfterDays: 365, reconfirmWithinDays: 30);
```

Each period is optional and falls back to the configuration when left out. A
project cannot say "never" for one; to keep data longer than the configuration
does, give a longer period; so a project can start asking confirmed addresses
again, but not stop where the configuration asks them. The values are checked when the project is defined,
against the same bounds as the configuration (no earlier than 1970, no later
than 2038 for a link).

`waitlist:prune` applies a project's own periods to that project and the
configured ones to every other, also with `--project`. `waitlist:privacy` lists
the periods that differ, and a confirm link issued for the project lives as long
as the project says. A catalog of your own answers with `periods($project)`, a
`ProjectPeriods`. A catalog that does not read stops `waitlist:prune` before it
deletes anything, since the configured periods are not a stand-in for the ones a
project promised, which may be longer.

## When the definition runs

`Waitlist::define()` only registers the callback. It runs when the waitlist first
needs the project, and its result is kept:

- A broken definition throws where the project is used, not at boot, so
  `php artisan` keeps working while you fix it.
- `config()` values are read when the project is first needed, not when the
  provider boots.
- **Defining a project again replaces it.** The definitions are not merged.
- The field closures are the exception: they run for every validation.

## Several products in one app

Give every product a name. Each brings its own purposes, lists, fields and pages;
nothing is inherited from the default project or from another project:

```php
Waitlist::define('anvil', function (ProjectDefinition $project): void {
    $project->purpose('launch', ['2026-10' => 'Email me when Anvil launches.']);
    $project->list('default', purpose: 'launch');

    $project->urls(
        confirm: 'https://anvil.example/waitlist/confirm/{token}',
        unsubscribe: 'https://anvil.example/waitlist/leave/{token}',
        manage: 'https://anvil.example/waitlist/preferences/{token}',
    );
});
```

List names only need to be unique within a project: `default` above is Anvil's
own list, apart from the `default` list of the default project. Project names
follow the rule for list names.

On a new install, `php artisan waitlist:install --projects` publishes a provider
shaped this way: two named projects to adapt, and the default project with pages
only, as [below](#the-default-project-when-every-product-has-a-name). That setup
is incomplete once the package routes are enabled, until you bind a resolver
([Over HTTP](#over-http-tell-the-package-which-project-a-signup-is-for)): the
routes act for the default project, so every signup over HTTP answers `422`. A provider that is already published is never overwritten; add the
projects to it.

### What changes in your code

The same calls, with `project()` in front:

| | Default project | Project `anvil` |
| --- | --- | --- |
| Sign up | `Waitlist::for('beta')->add(...)` | `Waitlist::project('anvil')->for('default')->add(...)` |
| Wording for the form | `Waitlist::purposes('beta')` | `Waitlist::project('anvil')->purposes('default')` |
| Fields of a list | `Waitlist::for('beta')->fields()` | `Waitlist::project('anvil')->for('default')->fields()` |
| Who may get the newsletter | `Waitlist::recipients('newsletter')` | `Waitlist::project('anvil')->recipients('newsletter')` |
| Report | `Waitlist::report()` | `Waitlist::project('anvil')->report()` |
| Erase one person | `Waitlist::forget($email)` | `Waitlist::project('anvil')->forget($email)` |
| Register wording | `Waitlist::registerWording(...)` | `Waitlist::project('anvil')->registerWording(...)` |

Tokens need no project: `Waitlist::confirm($token)`, `Waitlist::unsubscribe($token)`
and the token routes act for the entry the token belongs to, whichever project
that is.

Without `project()`, the facade acts on the default project, never on all of
them. `Waitlist::allProjects()` is the explicit way across, for requests about a
person:

```php
Waitlist::allProjects()->personalData($email);   // access request
Waitlist::allProjects()->forget($email);         // erasure request
Waitlist::allProjects()->report();               // every project
```

A project that is not defined is refused with `UnknownProjectException` rather
than taken for the default one.

### The default project when every product has a name

Keep your main product in the default project and give the others a name, or name
every product and leave the default project without lists. The second way is
safer when the products are equals: a signup that forgets `project()` fails with
`UnknownWaitlistException` instead of landing on whichever product happens to be
the default, and recipients and reports without `project()` come back empty
instead of showing one product's people.

The default project exists even when you never define it, with no lists. Define
it with pages only, so a link with an unknown token still lands somewhere:

```php
Waitlist::define(fn (ProjectDefinition $project) => $project->urls(
    invalid: 'https://example.com/waitlist/oops',
));

Waitlist::define('rocket', function (ProjectDefinition $project): void { /* ... */ });
Waitlist::define('anvil', function (ProjectDefinition $project): void { /* ... */ });
```

### Over HTTP: tell the package which project a signup is for

The token links need nothing, but a signup, the wording for a form and a manage
link requested by address arrive without a token. A `ProjectResolver` picks the
project for them. **The default resolver always picks `default`**, so with
several projects and the package routes, bind your own, for example by the site
the form is on:

```php
// app/Waitlist/SiteResolver.php
namespace App\Waitlist;

use Illuminate\Http\Request;
use Taldres\Waitlist\Contracts\ProjectResolver;

class SiteResolver implements ProjectResolver
{
    public function resolve(Request $request): string
    {
        // A site on its own domain sends its Origin; a page this app serves
        // itself is recognised by the host it was requested on.
        $host = parse_url((string) $request->headers->get('Origin'), PHP_URL_HOST) ?: $request->getHost();

        return match ($host) {
            'rocket.example', 'www.rocket.example' => 'rocket',
            'anvil.example' => 'anvil',
            default => abort(403),
        };
    }
}
```

```php
// app/Providers/WaitlistServiceProvider.php, in register()
$this->app->bind(\Taldres\Waitlist\Contracts\ProjectResolver::class, \App\Waitlist\SiteResolver::class);
```

A signup form is public, so the resolver needs no secret: it only decides which
lists, fields and wording apply, which anyone can read on the site anyway. It may
reject a request with `abort()`. It runs once per request, first, then the
[`useWaitlist` gate](#who-may-call-the-usewaitlist-gate), both before the body is
validated: the project decides which fields are accepted, and a refused request
answers without its body being looked at. A project it returns that is not
defined is treated like an unknown list: the signup answers `422`, the wording
`404`. The package ships two resolvers: the
[`PublishableKeyResolver`](#pages-that-call-from-the-browser-publishablekeyresolver)
takes the project from a key the page sends, and when the projects' servers
authenticate, the
[`AuthenticatedProjectResolver`](#servers-with-credentials-authenticatedprojectresolver)
takes it from their credentials.

Two more things per project:

- **`list` in the request.** Without it, a signup goes to the list named in
  `waitlist.default_list` (`default`) of the resolved project, so either give
  each project a list of that name, as above, or always post `list`.
- **Pages.** Each project's mail links point at its own pages. A project without
  them uses the package routes, rooted at `APP_URL`; with the routes off, its
  links are `null`.

Your own controllers need no resolver: they name the project themselves with
`Waitlist::project('anvil')`.

### Pages that call from the browser: `PublishableKeyResolver`

When the sites' pages call the API straight from the browser, give each project
a publishable key and let the page send it in the `X-Waitlist-Key` header:

```php
Waitlist::define('anvil', function (ProjectDefinition $project): void {
    // ...
    $project->publishableKey(config('services.anvil.waitlist_key'));
    $project->origins(['https://anvil.example']);
});
```

```php
// app/Providers/WaitlistServiceProvider.php, in register()
$this->app->bind(\Taldres\Waitlist\Contracts\ProjectResolver::class, \Taldres\Waitlist\Support\PublishableKeyResolver::class);
```

**The key is public**: it ships in the page, and anyone can copy it. It only
picks the project, the way the site's address would, and authorizes nothing.
Who may call is still decided by the
[`useWaitlist` gate](#who-may-call-the-usewaitlist-gate) and
`authentication.required`, and from which websites a browser may call by the
project's [`origins()`](#websites-that-may-use-a-project), so list them: without
origins, any website can use a copied key. A script outside a browser can send
any key and any `Origin`; bots are the
[spam protector's](securing-the-endpoints.md#bot-protection-via-spamprotector)
job. Never use a secret, such as an API token, as a publishable key.

- A request without a key, or with one no project has, answers `403`
  ("Unknown waitlist key."), before the body is validated. Keys are compared in
  constant time and case-sensitively, and two projects that share a key are a
  configuration error rather than one of them winning. A definition that throws
  is reported and skipped, so the other projects keep taking signups and its key
  answers `403` ("Unknown waitlist key."), also when the cause is passing, such as
  a database outage in its callback, so that `403` can mean "try again later" too; while it is broken, a key it shares with another project goes
  to that one. `waitlist:check` names both, a definition that cannot be built
  and projects that share a key, by project, never by key.
- A key is 1 to 255 visible ASCII characters without spaces; whitespace around
  it, such as the line break a secret file ends with, is dropped. Null, an
  empty or a blank value sets none, so a project whose environment variable is
  unset is not reachable by key. To change a key, deploy the pages with the new one as you
  switch it: a project has one key at a time.
- A server that calls with credentials of its own and acts for a project
  (`HasWaitlistProject`) sends the key too: the key picks the project, and the
  default gate keeps the server to its own, answering `404` for another
  project's key. A server that should not need the key takes the
  [`AuthenticatedProjectResolver`](#servers-with-credentials-authenticatedprojectresolver).
- The header is a custom one, so a page on another website needs it in
  `allowed_headers` of [`config/cors.php`](securing-the-endpoints.md#cors).
- With a [`ProjectCatalog` of your own](#when-the-projects-live-in-a-database),
  the definitions hold no keys: extend the resolver and override
  `project(string $key): ?string`, see
  [A central waitlist API](examples/central-waitlist-api.md#3-signups-from-the-sites).

### Mail per project

Listeners see the project on the entry, `$event->entry->project`, and pick the
sender, template and mailer from it. See [Mail](mail.md#several-projects).

### Limits per project

A project's definition has no rate limits: the numbers under `routes.rate_limits`
are the same for every project. A limiter of your own that asks which project a
request is for sets them apart, see "Different limits per project" under
[Rate limits](securing-the-endpoints.md#rate-limits).

## Who may call: the `useWaitlist` gate

Three setups cover most apps. Signing in and acting for a project are separate
requirements: Laravel's `auth` middleware decides the first, the package's
`authentication.required` and resolver the second. The settings below each do one
job in them, and every other combination is possible.

| | A public form (architecture A) | Only your app's signed-in users | Only your projects' servers (architecture C) |
| --- | --- | --- | --- |
| Who calls | anyone, as a guest; a server with credentials may too, and is recognised | your users, signed in with the app's own login | each site's server, with a token of its own |
| `ProjectResolver` binding | the default (one project), `PublishableKeyResolver`, or your own, from the Origin for example | the default (one project), or your own; your users act for no project | `AuthenticatedProjectResolver`: the project follows from the caller |
| `authentication.guards` | not needed; name a guard to recognise servers that call with credentials | leave it at `[null]`: the guard `auth:` signed the user in with becomes the default | the guard that authenticates the server, such as `sanctum` |
| `routes.group_middleware.signup` | your form checks only | `auth:sanctum`: guests get `401`; a session login also needs `web` here, which brings CSRF | your form checks only; or `auth:sanctum` instead of `required` |
| `authentication.required` | off | off: it would refuse your users with `403`, as they act for no project | on: guests get `401`, callers without a project `403` |
| `useWaitlist` gate | the default lets everyone through; define it only to restrict | define it: which users may join which project, and deny `RegisterWording` | the default keeps a caller to its own project; define it for abilities per action or list |
| Wording from the site | with `wordingFromCallers()`, from a recognised server for its project, never from a guest | never, as long as your gate denies `RegisterWording` | with `wordingFromCallers()`, `RegisterWording` for a caller acting for the project |
| Rate limit | per IP; per server for a recognised server | per IP | per server, and per visitor when the server forwards the address (`authentication.client_ip_header`) |

For signed-in users, your users must not implement `HasWaitlistProject`: a caller
that does counts as a project's server, limited per server, trusted with the
visitor address it forwards and, by the default gate, allowed to send wording.
Your gate replaces the default one, so it decides wording too:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Taldres\Waitlist\Enums\WaitlistAction;

Gate::define('useWaitlist', fn (User $user, string $project, WaitlistAction $action) => $action !== WaitlistAction::RegisterWording
    && $user->can('join-beta', $project));
```

For servers only, refusing a signed-in caller without a project is part of the
setup, not a side effect: with `authentication.required`, the default gate
answers it `403`; with `auth:sanctum` in the signup group instead, it is
`AuthenticatedProjectResolver` that does. A resolver of your own must then refuse
it itself, or the gate you define must.

Four requests act for a project: the signup, the wording for a form, the count of
a list and a manage link requested by address. For each, once the resolver has picked the project and
before the body is validated, the package asks Laravel's gate `useWaitlist`. Token
links never reach it: the token is the proof there, and the mail it comes from
carries no credentials.

```php
Gate::define('useWaitlist', function (?Authenticatable $caller, string $project, WaitlistAction $action, ?string $list = null) {
    // ...
});
```

| Argument | |
| --- | --- |
| `$caller` | the user of the first guard in `waitlist.authentication.guards` that authenticates the request; `null` for a guest |
| `$project` | the project the resolver picked |
| `$action` | `WaitlistAction::Subscribe`, `ViewPurposes`, `ViewCount`, `RequestManageLink`, or `RegisterWording` for a signup that brings its wording, asked after `Subscribe` |
| `$list` | the list the request acts on; `waitlist.default_list` when it names none |

**The package's default lets everyone through**, as a public form needs, with
one exception: a caller acting for a project, one implementing
`HasWaitlistProject`, keeps to its own project, and another project answers
`404`. With `waitlist.authentication.required` on, it also refuses guests with
`401` and callers without a project with `403`: for an API that only your
projects' servers may call. For one that only your signed-in users may call, use
`auth` middleware instead and define the gate, see the table above. On a public form, the gate keeps nobody out; bots
are the [spam protector's](securing-the-endpoints.md#bot-protection-via-spamprotector)
job. `RegisterWording` is the exception the other way round: only a caller acting
for the project gets it, never a guest, even on a public form
([Wording sent by your servers](purposes-and-wording.md#wording-sent-by-your-servers)).

**Your own gate replaces the default.** Define the ability in a service provider,
the published `WaitlistServiceProvider` for example; it wins whichever provider
boots first. Return a bool or a gate response, whose status and message reach the
client:

```php
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Taldres\Waitlist\Enums\WaitlistAction;

Gate::define('useWaitlist', function (?Project $caller, string $project, WaitlistAction $action, ?string $list = null) {
    return match (true) {
        $caller === null => Response::denyWithStatus(401),
        $caller->key !== $project => Response::denyAsNotFound(),
        $action === WaitlistAction::Subscribe && $list === 'teams' => $caller->tokenCan('waitlist:teams'),
        default => $caller->tokenCan("waitlist:{$action->value}"),
    };
});
```

Type the caller as what your guards return, nullable so that guests reach the
gate at all. `tokenCan()` reads the abilities of a Sanctum token; Laravel's own
`can()` asks gates and policies instead. For a guard of your own, one for JWTs for
example, hand the model its scopes with Sanctum's `withAccessToken()`, and
`tokenCan()` answers for both.

### Servers with credentials: `AuthenticatedProjectResolver`

When the projects' servers call the API with credentials of their own, a Sanctum
token for example, the project follows from the caller and requests no longer
name it. Let the authenticatable say which project it acts for:

```php
// app/Models/Project.php
use Taldres\Waitlist\Contracts\HasWaitlistProject;

class Project extends Model implements Authenticatable, HasWaitlistProject
{
    use HasApiTokens;

    public function waitlistProject(): string
    {
        return $this->key;
    }
}
```

```php
// app/Providers/WaitlistServiceProvider.php, in register()
$this->app->bind(\Taldres\Waitlist\Contracts\ProjectResolver::class, \Taldres\Waitlist\Support\AuthenticatedProjectResolver::class);
```

```php
// config/waitlist.php
'authentication' => [
    'guards' => ['sanctum'],   // in this order; the first that authenticates wins
    'required' => true,
],
```

The resolver answers `401` without a caller, and `403` for a caller without a
project or with a project that is not defined. How the servers authenticate, with
Sanctum, Passport or a guard of your own, and how you issue and revoke their
credentials, is up to your application; the package only reads the caller.

Gate the credentials themselves as well, so a token of a project you removed
stops working on your own routes too:

```php
Sanctum::authenticateAccessTokensUsing(
    fn (PersonalAccessToken $token, bool $isValid): bool => $isValid
        && $token->tokenable instanceof HasWaitlistProject
        && Waitlist::hasProject($token->tokenable->waitlistProject()),
);
```

`Waitlist::hasProject()` counts the default project, which always exists.
`Waitlist::caller($request)` hands your own code, a rate limiter for example, the
caller the resolver and the gate see.

Such a caller is a server whose visitors all share its address. The default rate
limits therefore cap it as a whole and leave the limit per visitor to it, unless
it forwards the visitor's address
([Servers calling for a project](securing-the-endpoints.md#servers-calling-for-a-project)),
and it may send the wording along with a signup
([Wording sent by your servers](purposes-and-wording.md#wording-sent-by-your-servers)).

## When the projects live in a database

Definitions in code suit a handful of products that ship with the app. When
projects come and go at runtime, or their teams manage their own lists and
wording, implement a `ProjectCatalog` that reads your tables, and a resolver
that maps a publishable key to a project, a `PublishableKeyResolver` that reads
the keys from your tables. [A central waitlist API](examples/central-waitlist-api.md)
shows both. A catalog of your own replaces the definitions entirely, the fields
included: its `fields($project, $list)` returns the rules the HTTP signup applies,
`urlPattern($project, $action)` is asked for a page by its
`Taldres\Waitlist\Enums\Page` value, such as `Page::Confirm->value`, and
`manageLinks($project)` says whether the project mails manage links,
`origins($project)` the websites that may use it from a browser (empty for any)
and `periods($project)` the retention periods and the confirm link lifetime that
differ from the configuration (`new ProjectPeriods` for none).

## Privacy across projects

All projects belong to one controller, so a person's request for access or
erasure concerns every one of them: `Waitlist::allProjects()->personalData($email)`
and `Waitlist::allProjects()->forget($email)`. The commands follow the same
rule: without `--project`, `waitlist:show`, `waitlist:forget`, `waitlist:prune`
and `waitlist:privacy` cover every project; a `--list` without `--project`
narrows the first three to that list of the default project, see
[Commands](reference/commands.md). Your privacy notice names every product the
waitlist collects for.

The self-service preference page covers the one list its link belongs to, and a
withdrawal never reaches another project: leaving Rocket's newsletter keeps
Anvil's.

Projects do not give you tenant isolation. If you run waitlists for other
companies, assess the controller and processor roles and build the contracts,
separation and per-customer keys that needs; project keys do not provide them.

## Testing

In a test, `Waitlist::define()` and `Gate::define('useWaitlist', ...)` replace the
project or the gate for that test only; the next test boots the app again with
your providers' definitions:

```php
it('asks teams for a phone number', function () {
    Waitlist::define('acme', function (ProjectDefinition $project): void {
        $project->purpose('waitlist', ['v1' => 'Email me.']);
        $project->list('teams', purpose: 'waitlist')->fields(['contact_phone' => ['required']]);
    });

    $this->postJson('/waitlist', [/* ... */])->assertJsonValidationErrors('metadata.contact_phone');
});
```

A test that switches between a server caller and a guest calls `Auth::forgetGuards()`
in between. A guard built with `Auth::viaRequest()` keeps its user for the rest of
the test, so the guest request would still see the server and a missing `401`
looks like a bug in the package.
