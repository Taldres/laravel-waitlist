# Example: a central waitlist API

Goal: one Laravel app collects the waitlists of all your products. Each product
keeps owning its lists and wording, and its site talks to the API over HTTP. One
controller, several projects.

This is the [several projects](../projects.md) setup with the projects in your
database instead of definitions in code: for products that come and go at
runtime, or teams that manage their own lists and wording. For a handful of
products that ship with the app, [several products in one app](several-products.md)
is simpler.

Four things stay apart:

| What | Where |
| --- | --- |
| Technical settings: connection, retention, which catalog | `config/waitlist.php` |
| Projects, lists, fields, wording versions, frontend URLs | your database, behind a `ProjectCatalog` |
| Which project a signup is for | a `ProjectResolver`, e.g. from a publishable key |
| The wording a person agreed to | the consent snapshot the package stores |

## 1. A catalog in your database

Model projects and lists as you like, then bind a catalog that reads them. Extend
`StoredWordingCatalog`, and the wording comes from the package's
`waitlist_wordings` table, which the projects fill themselves (section 2). Every
other method answers from `Waitlist::define()`, which knows none of your database
projects, so override each one that should read your tables:

```php
// config/waitlist.php
'catalog' => App\Waitlist\DatabaseCatalog::class,
```

```php
use Taldres\Waitlist\Support\ListPolicy;
use Taldres\Waitlist\Support\ProjectPeriods;
use Taldres\Waitlist\Support\StoredWordingCatalog;

class DatabaseCatalog extends StoredWordingCatalog
{
    public function policy(string $project, string $list): ?ListPolicy
    {
        $row = $this->row($project, $list);

        return $row === null ? null : new ListPolicy($project, $list, $row->purpose, $row->optional, $row->double_opt_in);
    }

    public function fields(string $project, string $list): array
    {
        return $this->row($project, $list)?->fields ?? [];
    }

    public function urlPattern(string $project, string $action): ?string
    {
        return Project::query()->where('key', $project)->value("{$action}_url");
    }

    public function manageLinks(string $project): bool
    {
        return (bool) (Project::query()->where('key', $project)->value('manage_links') ?? true);
    }

    public function periods(string $project): ProjectPeriods
    {
        // What the site promised in its privacy notice, null where the
        // configuration applies.
        $row = Project::query()->where('key', $project)->first();

        return new ProjectPeriods(pendingDays: $row?->pending_days, confirmLinkMinutes: $row?->confirm_link_minutes);
    }

    public function projects(): array
    {
        // The facade acts on "default" when no project() is named.
        return ['default', ...Project::query()->pluck('key')->all()];
    }

    public function lists(string $project): array
    {
        return WaitlistList::query()->where('project', $project)->pluck('name')->all();
    }

    private function row(string $project, string $list): ?WaitlistList
    {
        return WaitlistList::query()->where('project', $project)->where('name', $list)->first();
    }
}
```

`versions()` comes from `StoredWordingCatalog`: only what new signups may still
use, so a retired version drops out, while every consent already given keeps its
own snapshot. `urlPattern()` is asked for the project's pages: `confirm`,
`unsubscribe` and `manage`, where mail links point, and `confirmed`, `expired`,
`invalid`, `unsubscribed` and `erased`, where browsers land; return `null` for
any a project does not have.

`fields()` returns the rules the HTTP signup applies to `metadata`, field =>
rules, for each validation. Here `fields` is a JSON column on the list, cast to
`array`, such as `{"company": ["required", "string", "max:120"]}`. **Rules stored in
a database can only be strings** (`max:120`, `in:a,b`), never rule objects. A
list without fields takes no metadata at all, see
[Fields](../projects.md#fields).

## 2. Projects register their wording

Each project keeps its wording where its team works, in its CMS, frontend repo or
backend, and registers every version with your API through an authenticated
endpoint. Visitors never reach it.

```php
use Taldres\Waitlist\Exceptions\WordingConflictException;
use Taldres\Waitlist\Facades\Waitlist;

Route::middleware('auth.project')->put('/purposes/{purpose}/versions/{version}', function (Request $request, string $purpose, string $version) {
    $wording = $request->input('wording'); // one text, or {locale: text}

    abort_unless(is_string($wording) || (is_array($wording) && $wording === array_filter($wording, 'is_string')), 422);

    try {
        $added = Waitlist::project($request->project())->registerWording($purpose, $version, $wording);
    } catch (WordingConflictException $exception) {
        return response()->json(['message' => $exception->getMessage()], 409);
    }

    return response()->noContent($added > 0 ? 201 : 204);
});
```

- Register automatically on publish, from a CMS webhook or a deploy step. By hand,
  displayed and registered wording drift apart. A project deployed with your API
  can run `php artisan waitlist:wording wording.json --project=acme` instead.
- A registered version never changes: other text is refused, a new locale is
  added. Retire a version with `retireWording()` instead of deleting it, so you
  can still tell which wording was in use when.
- The form shows the same text it registered, or posts back its hash, so a
  drift is refused instead of recorded.

`auth.project` is your middleware that resolves an API key to a project.

Do the sites' servers call the API for their visitors (section 3)? Then they can
skip the endpoint and send the wording with each signup: a project whose
definition says `wordingFromCallers()`, or whose catalog's `policy()` sets
`wordingFromCallers`, registers a version the first time one of its servers
sends it. See [Wording sent by your servers](../purposes-and-wording.md#wording-sent-by-your-servers).

## 3. Signups from the sites

Enable the package routes and tell the package which project a request is for.
A `ProjectResolver` maps whatever identifies a site to a project. A signup form
is public, so a publishable key is enough: it only decides which lists, fields and
wording apply, which anyone could learn by visiting the site anyway.

```php
use Illuminate\Http\Request;
use Taldres\Waitlist\Contracts\ProjectResolver;

class PublishableKeyResolver implements ProjectResolver
{
    public function resolve(Request $request): string
    {
        $key = (string) $request->header('X-Waitlist-Key');

        return Project::query()->where('publishable_key', $key)->value('key') ?? abort(401);
    }
}
```

```php
// config/waitlist.php
'project_resolver' => App\Waitlist\PublishableKeyResolver::class,
```

```dotenv
WAITLIST_ROUTES_ENABLED=true
```

Do the sites call from their servers instead, with a secret token per project?
Then the project follows from the token: let your project model implement
`HasWaitlistProject`, use `AuthenticatedProjectResolver` with your token guard in
`waitlist.authentication.guards`, and turn `waitlist.authentication.required` on,
so the `useWaitlist` gate refuses every caller without a project. See
[Servers with credentials](../projects.md#servers-with-credentials-authenticatedprojectresolver).
All of a site's visitors then come from its server's address: the package caps
each server as a whole and leaves the limit per visitor to the site, unless the
server forwards the visitor's address, see
[Servers calling for a project](../securing-the-endpoints.md#servers-calling-for-a-project).

Every site then uses the same endpoints: `GET /waitlist/purposes?list=beta`,
`POST /waitlist` with `email`, `list`, `purposes` (`{purpose: version}`) and
`metadata` for the fields its list defines, and `POST /waitlist/manage-link`.
The browser never sends wording, only versions; a site's server may send the
text along when its project takes wording from its servers.

Confirm, unsubscribe and the preference page work by token. A token belongs to
an entry and its project, so these need no key, and the one-click requests mail
providers send, which carry no credentials, keep working. Each project's pages,
and where browsers land afterwards, come from its `urlPattern()`; the
`List-Unsubscribe` header points at the API itself, rooted at its `APP_URL`,
since the one-click request has to reach the package.

Keep the key check out of `routes.middleware`: it applies to the token links
too. Allow each site's origin in CORS, and configure trusted proxies so the rate
limiter sees real client IPs.

## 4. Mails per project

Listeners see the project on the entry and pick sender, template and links from
it:

```php
public function handle(EntrySubscribed $event): void
{
    $project = Project::query()->where('key', $event->entry->project)->firstOrFail();

    Mail::mailer($project->mailer)->to($event->entry->email)->send(
        new ConfirmWaitlist($project, $event->confirmUrl, $event->unsubscribeUrl),
    );
}
```

Send from each product's own domain, with its SPF and DKIM records. The same
goes for `ManageLinkRequested`, which carries the link to the preference page.
See [Mail](../mail.md#several-projects).

## 5. Privacy

All projects belong to one controller, so a request for erasure or access covers
all of them: `Waitlist::allProjects()->forget($email)` and
`Waitlist::allProjects()->personalData($email)`. Without `project()` or
`allProjects()`, the facade only ever touches the default project. Your privacy
notice names every product the service collects for;
`php artisan waitlist:privacy --project=acme` describes one.

Serving other companies' waitlists makes you their processor instead. That needs
contracts, strict separation and per-customer keys, which projects do not provide.

## 6. Deploying and updating

Two things cost debugging time on a first deploy and on updates:

- **A site on the same host.** A container that calls the API through its public
  domain on its own host can time out: a firewall that lets only the SSH port
  through, or container networks isolated from each other, stop a request that
  leaves the host and comes back. Give the site an internal address instead, such
  as the API container's name on a network both share, and use it as the site's
  API URL. The request IP is then the site container's, so a site that forwards
  its visitors' addresses needs `authentication.client_ip_header`, see
  [Securing the endpoints](../securing-the-endpoints.md). When a signup never
  shows in the API's access log, look at the site's own log first: the JS client
  reports the reason there, such as `timeout`.
- **Workers and the scheduler after an update.** Queue workers and the scheduler
  run the code they started with until they restart, so jobs dispatched before an
  update still run against the old classes. Run `php artisan queue:restart` or
  recreate the containers after every update. Jobs queued before the restart carry
  their event as the old code serialized it, so check the changelog for changed
  event payloads before you update with a full queue.
