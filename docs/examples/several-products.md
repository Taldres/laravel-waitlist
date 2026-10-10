# Example: several products in one app

The situation: your company builds two products, **Rocket** and **Anvil**. One
Laravel app at `api.example.com` collects both waitlists. Each product has a
landing page on its own domain, `rocket.example` and `anvil.example`, that talks
to the app over HTTP. Rocket also offers a newsletter.

Each product becomes a [project](../projects.md), defined in a service provider.
For projects that come and go at runtime, or manage their own wording, see
[A central waitlist API](central-waitlist-api.md) instead.

## 1. Define the projects

```php
// app/Providers/WaitlistServiceProvider.php
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Facades\Waitlist;

public function boot(): void
{
    // The default project: no lists, so a signup that forgets project() fails
    // instead of signing someone up for the wrong product. A link with an
    // unknown token belongs to no project and lands on its page.
    Waitlist::define(fn (ProjectDefinition $project) => $project->urls(
        invalid: 'https://example.com/waitlist/oops',
    ));

    Waitlist::define('rocket', function (ProjectDefinition $project): void {
        $project->purpose('waitlist', ['2026-10' => 'Email me when Rocket launches.']);
        $project->purpose('newsletter', ['2026-10' => 'Also send me the monthly Rocket newsletter.']);

        $project->list('default', purpose: 'waitlist')->optional('newsletter');

        $project->urls(...self::pages('https://rocket.example'));
    });

    Waitlist::define('anvil', function (ProjectDefinition $project): void {
        $project->purpose('waitlist', ['2026-10' => 'Email me when Anvil launches.']);

        $project->list('default', purpose: 'waitlist');

        $project->urls(...self::pages('https://anvil.example'));
    });
}

/**
 * @return array<string, string>
 */
private static function pages(string $site): array
{
    return [
        'confirm' => "{$site}/waitlist/confirm/{token}",
        'unsubscribe' => "{$site}/waitlist/leave/{token}",
        'manage' => "{$site}/waitlist/preferences/{token}",
        'invalid' => "{$site}/waitlist/oops",
        'expired' => "{$site}/waitlist/expired",
    ];
}
```

```dotenv
APP_URL=https://api.example.com
WAITLIST_ROUTES_ENABLED=true
```

Both projects use the purpose key `waitlist` and a list called `default`: names
only need to be unique within a project, and nothing is shared between them. A
withdrawal never reaches the other project either; leaving Rocket's waitlist
keeps Anvil's. Should Anvil's form ask for more than the address, a company size
for example, `$project->fields()` in Anvil's definition allows exactly that field
for Anvil and nothing for Rocket, see [Fields](../projects.md#fields).

## 2. Tell the package which site a signup comes from

The default resolver always answers `default`, which has no lists here, so every
signup would be refused. Resolve the project from the site the form is on:

```php
// app/Waitlist/SiteResolver.php
namespace App\Waitlist;

use Illuminate\Http\Request;
use Taldres\Waitlist\Contracts\ProjectResolver;

class SiteResolver implements ProjectResolver
{
    public function resolve(Request $request): string
    {
        $host = parse_url((string) $request->headers->get('Origin'), PHP_URL_HOST) ?: $request->getHost();

        return match ($host) {
            'rocket.example', 'www.rocket.example' => 'rocket',
            'anvil.example', 'www.anvil.example' => 'anvil',
            default => abort(403),
        };
    }
}
```

```php
// config/waitlist.php
'project_resolver' => App\Waitlist\SiteResolver::class,
```

The resolver runs for the signup, the wording and manage links requested by
address. Token links need none: a token from a Rocket mail always acts for
Rocket.

## 3. Let the sites call the app

Both sites are other origins, so allow them in CORS
(`php artisan config:publish cors` if the file does not exist yet):

```php
// config/cors.php
'paths' => ['api/*', 'waitlist', 'waitlist/*'],
'allowed_origins' => ['https://rocket.example', 'https://anvil.example'],
```

Behind a load balancer or CDN, configure
[trusted proxies](https://laravel.com/docs/requests#configuring-trusted-proxies),
or every visitor shares one rate limit bucket. Add bot protection to the signup,
see [Securing the endpoints](../securing-the-endpoints.md#bot-protection-via-spamprotector).

## 4. The signup form, the same on both sites

The form does not name a project; the resolver knows it from the Origin:

```js
const api = 'https://api.example.com/waitlist'

const { data } = await fetch(`${api}/purposes?locale=${locale}`).then((r) => r.json())
// Rocket: [{ purpose: 'waitlist', … required: true }, { purpose: 'newsletter', … required: false }]
// Anvil:  [{ purpose: 'waitlist', … required: true }]

const shown = ({ version, locale, hash }) => ({ version, locale, hash })
const chosen = data.filter((p) => p.required || ticked.includes(p.purpose))

await fetch(api, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({
    email,
    purposes: Object.fromEntries(chosen.map((p) => [p.purpose, shown(p)])),
  }),
})
```

Without `list`, the signup goes to the `default` list of the resolved project.
The confirm, unsubscribe and preference pages on each site work exactly as in
[the SPA example](landing-page-spa.md#3-the-confirmation-page), against
`https://api.example.com/waitlist/…`.

## 5. Mails per product

Every mail comes from its product's own domain. Keep the sender per project in a
config file of your own:

```php
// config/products.php
return [
    'rocket' => ['name' => 'Rocket', 'from' => 'hello@rocket.example', 'mailer' => 'resend'],
    'anvil' => ['name' => 'Anvil', 'from' => 'hello@anvil.example', 'mailer' => 'resend'],
];
```

```php
// app/Listeners/SendWaitlistConfirmationMail.php
public function handle(EntrySubscribed $event): void
{
    if (! $event->requiresConfirmation) {
        return;
    }

    $product = config("products.{$event->entry->project}");

    $mail = (new ConfirmWaitlistMail($event->confirmUrl, $event->unsubscribeUrl, $event->subscription->consents->pluck('text')->all()))
        ->from($product['from'], $product['name']);

    Mail::mailer($product['mailer'])->to($event->entry->email)->send($mail);

    Waitlist::confirmationMailed($event->subscription, "confirm-mail@{$event->entry->project}-2026-10");
}
```

`ConfirmWaitlistMail` and the queued listener around it are in
[Mail](../mail.md#the-confirmation-mail); the preference page link works the same
way with `ManageLinkRequested`. The links in each mail already point at the
product's own pages, and the one-click `List-Unsubscribe` header at the app,
since that request has to reach the package. Set up SPF and DKIM for both
domains with your sending service.

## 6. Working with the lists

Name the project in front of every call. Without it, the facade acts on the
default project, which has no lists here: `add()`, `purposes()` and
`recipients()` on `Waitlist::for()` refuse with `UnknownWaitlistException`, and
reports come back empty.

```php
// Rocket launches: everyone on its waitlist
Waitlist::project('rocket')->for('default')->recipients()->each(
    fn (Recipient $recipient) => Mail::mailer('resend')->to($recipient->email)->queue(new RocketLaunchMail($recipient)),
);

// Rocket's newsletter: only those who agreed to it
Waitlist::project('rocket')->recipients('newsletter');

// Numbers per product, and for both
Waitlist::project('anvil')->report()->since(30)->totals();
Waitlist::allProjects()->report()->since(30)->totals();
```

```bash
php artisan waitlist:export default --project=rocket
php artisan waitlist:privacy --project=anvil
```

## 7. Requests about a person

Both products belong to your company, one controller under the GDPR. A request
for access or erasure that reaches you by mail covers both:

```php
Waitlist::allProjects()->personalData('jane@example.com');
Waitlist::allProjects()->forget('jane@example.com');
```

```bash
php artisan waitlist:show jane@example.com      # every project
php artisan waitlist:forget jane@example.com    # every project
```

Your privacy notice names both products. The preference page on each site covers
the one list its link belongs to.

## Variant: the products are pages of this app

If this app renders the landing pages itself, with Blade, Livewire or Inertia,
leave the package routes off. Each product's controller names its project, and no
resolver or CORS is needed:

```php
// app/Http/Controllers/RocketWaitlistController.php
public function create()
{
    return view('rocket.waitlist', [
        'purposes' => Waitlist::project('rocket')->purposes('default', app()->getLocale()),
    ]);
}

public function store(Request $request)
{
    // validation as in Getting started, step 4
    Waitlist::project('rocket')->for('default')->add($validated['email'], $agreed, context: RequestContext::fromRequest($request));

    return view('rocket.check-your-inbox');
}
```

The confirm and unsubscribe pages call `Waitlist::confirm($token)` and
`Waitlist::unsubscribe($token)`, which need no project. See
[Getting started](../getting-started.md) for the full controller code.
