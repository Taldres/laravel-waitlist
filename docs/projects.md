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
`bootstrap/providers.php`. The definition can live in any provider's `boot()`,
your `AppServiceProvider` included; the published one keeps it in one place.

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
product: double opt-in, retention, rate limits, routes and the bindings, see
[Configuration](reference/configuration.md).

**Read the environment through `config()`, never `env()`.** Once the config is
cached, `env()` returns `null` outside the config files, so a URL built from it
would silently fall back to the package routes. Laravel's default
`config/app.php` has `frontend_url` (`FRONTEND_URL`); for anything else, add a
key to a config file of your own.

## One product: the default project

Without a name, `Waitlist::define()` describes the default project:

```php
Waitlist::define(function (ProjectDefinition $project): void {
    $project->purpose('waitlist', ['2026-10' => 'Email me when early access opens.']);
    $project->purpose('newsletter', ['2026-10' => 'Also send me the monthly newsletter.']);

    $project->list('default', purpose: 'waitlist')->optional('newsletter');
    $project->list('beta', purpose: 'waitlist')->optional('newsletter');

    $project->urls(
        confirm: config('app.frontend_url').'/waitlist/confirm/{token}',
        unsubscribe: config('app.frontend_url').'/waitlist/leave/{token}',
        manage: config('app.frontend_url').'/waitlist/preferences/{token}',
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

Defining a purpose again replaces it. Names and versions are at most 100
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

### Periods a project promises itself

`waitlist.retention` and `waitlist.double_opt_in.token_ttl` are global. In a
central API, one site may tell its visitors that unconfirmed signups are deleted
within seven days, while another keeps the thirty days of the configuration. A
project says so in its definition:

```php
$project
    ->retention(pendingDays: 7, unsubscribedDays: 365, requestMetadataDays: 14)
    ->confirmLinkLifetime(60 * 24 * 7);   // minutes
```

Each period is optional and falls back to the configuration when left out. A
project cannot say "never" for one; to keep data longer than the configuration
does, give a longer period. The values are checked when the project is defined,
against the same bounds as the configuration (no earlier than 1970, no later
than 2038 for a link).

`waitlist:prune` applies a project's own periods to that project and the
configured ones to every other, also with `--project`. `waitlist:privacy` lists
the periods that differ, and a confirm link issued for the project lives as long
as the project says. A catalog of your own answers with `periods($project)`, a
`ProjectPeriods`. A catalog that does not read is reported and `waitlist:prune`
falls back to the configured periods for every project.

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
// config/waitlist.php
'project_resolver' => App\Waitlist\SiteResolver::class,
```

A signup form is public, so the resolver needs no secret: it only decides which
lists, fields and wording apply, which anyone can read on the site anyway. It may
reject a request with `abort()`. It runs once per request, first, then the
[`useWaitlist` gate](#who-may-call-the-usewaitlist-gate), both before the body is
validated: the project decides which fields are accepted, and a refused request
answers without its body being looked at. A project it returns that is not
defined is treated like an unknown list: the signup answers `422`, the wording
`404`. When the projects' servers authenticate, the
[`AuthenticatedProjectResolver`](#servers-with-credentials-authenticatedprojectresolver)
takes the project from their credentials instead.

Two more things per project:

- **`list` in the request.** Without it, a signup goes to the list named in
  `waitlist.default_list` (`default`) of the resolved project, so either give
  each project a list of that name, as above, or always post `list`.
- **Pages.** Each project's mail links point at its own pages. A project without
  them uses the package routes, rooted at `APP_URL`; with the routes off, its
  links are `null`.

Your own controllers need no resolver: they name the project themselves with
`Waitlist::project('anvil')`.

### Mail per project

Listeners see the project on the entry, `$event->entry->project`, and pick the
sender, template and mailer from it. See [Mail](mail.md#several-projects).

## Who may call: the `useWaitlist` gate

Two setups cover most apps; the settings below each do one job in them, and every
other combination is possible.

| | A public form (architecture A) | Your sites' servers with credentials (architecture C) |
| --- | --- | --- |
| Who calls | anyone, as a guest | each site's server, with a token of its own |
| `project_resolver` | the default (one project), or your own from a publishable key or the Origin | `AuthenticatedProjectResolver`: the project follows from the caller |
| `authentication.guards` | not needed | the guard that authenticates the server, such as `sanctum` |
| `authentication.required` | off | on: guests get `401`, callers without a project `403` |
| `useWaitlist` gate | the default lets everyone through; define it only to restrict | the default keeps a caller to its own project; define it for abilities per action or list |
| Wording from the site | never: a guest cannot send text | `RegisterWording`, only for a caller acting for the project |
| Rate limit | per IP | per server, and per visitor when the server forwards the address (`authentication.client_ip_header`) |

Three requests act for a project: the signup, the wording for a form and a manage
link requested by address. For each, once the resolver has picked the project and
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
| `$action` | `WaitlistAction::Subscribe`, `ViewPurposes`, `RequestManageLink`, or `RegisterWording` for a signup that brings its wording, asked after `Subscribe` |
| `$list` | the list the request acts on; `waitlist.default_list` when it names none |

**The package's default lets everyone through**, as a public form needs, with
one exception: a caller acting for a project, one implementing
`HasWaitlistProject`, keeps to its own project, and another project answers
`404`. With `waitlist.authentication.required` on, it also refuses guests with
`401` and callers without a project with `403`: for an API that only your
projects' servers may call. On a public form, the gate keeps nobody out; bots
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
// config/waitlist.php
'project_resolver' => Taldres\Waitlist\Support\AuthenticatedProjectResolver::class,

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
that maps a publishable key to a project. [A central waitlist API](examples/central-waitlist-api.md)
shows both. A catalog of your own replaces the definitions entirely, the fields
included: its `fields($project, $list)` returns the rules the HTTP signup applies,
`urlPattern($project, $action)` is asked for a page by its
`Taldres\Waitlist\Enums\Page` value, such as `Page::Confirm->value`, and
`manageLinks($project)` says whether the project mails manage links, and
`periods($project)` the retention periods and the confirm link lifetime that
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
