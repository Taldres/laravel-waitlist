# Getting started

One waitlist with an optional newsletter, end to end: show the wording, let
people choose, sign them up, confirm, mail only those who agreed, and let them
withdraw. One product, so everything lives in the default project and no step
names one; for several products, see [Projects, lists and fields](projects.md).

Each step works two ways:

- **Over HTTP**, with the package routes: for an SPA, a static site or a
  frontend on another domain.
- **In your own controllers**, with the `Waitlist` facade: for Blade, Livewire or
  Inertia. The package routes stay off.

## 1. Install

```bash
composer require taldres/laravel-waitlist
php artisan waitlist:install
php artisan migrate
```

`waitlist:install` publishes the migrations, `config/waitlist.php` and
`app/Providers/WaitlistServiceProvider.php`, and registers the provider in
`bootstrap/providers.php`. Back up `APP_KEY` apart from the database: addresses
are encrypted with it.

## 2. Define the purposes and the list

Describe the waitlist in the published provider:

```php
// app/Providers/WaitlistServiceProvider.php
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Facades\Waitlist;

public function boot(): void
{
    Waitlist::define(function (ProjectDefinition $project): void {
        $project->purpose('waitlist', ['2026-10' => 'Email me when early access opens.']);
        $project->purpose('newsletter', ['2026-10' => 'Also send me the monthly newsletter.']);

        $project->list('default', purpose: 'waitlist')->optional('newsletter');
        $project->list('beta', purpose: 'waitlist')->optional('newsletter');
    });
}
```

`waitlist` is the list's primary purpose: required, so it can never be bundled
with the newsletter. `newsletter` is chosen on its own. Only defined lists accept
signups, and steps 6 and 7 use a second list, `beta`. A version can hold a text
per locale, see [Languages](purposes-and-wording.md#languages); wording written
in a CMS or your frontend can be
[registered](purposes-and-wording.md#registering-wording-where-it-is-written)
instead of kept here. Fields beyond the address, lists made up at runtime and
everything else a definition takes are in [Projects, lists and fields](projects.md).

Point the links in your mails at your pages, in the same definition. Read the
environment through `config()`: with a cached config, `env()` returns `null`
here.

```php
$project->urls(
    confirm: config('app.frontend_url').'/waitlist/confirm/{token}',
    unsubscribe: config('app.frontend_url').'/waitlist/leave/{token}',
    manage: config('app.frontend_url').'/waitlist/preferences/{token}',
);
```

Over HTTP, also enable the routes: `WAITLIST_ROUTES_ENABLED=true`.

## 3. Show the wording

The form shows exactly the text that will be stored as consent, taken from the
package, never typed into the template.

**Over HTTP:**

```http
GET /waitlist/purposes?list=default&locale=de
```

```json
{"data": [
  {"purpose": "waitlist", "version": "2026-10", "locale": null, "text": "Email me when early access opens.", "hash": "…", "required": true},
  {"purpose": "newsletter", "version": "2026-10", "locale": null, "text": "Also send me the monthly newsletter.", "hash": "…", "required": false}
]}
```

**In your own controllers:**

```php
return view('waitlist.signup', [
    'purposes' => Waitlist::purposes('default', locale: app()->getLocale()),
]);
```

```blade
@foreach ($purposes as $wording)
    <input type="hidden" name="purposes[{{ $wording->purpose }}][version]" value="{{ $wording->version }}">
    <input type="hidden" name="purposes[{{ $wording->purpose }}][locale]" value="{{ $wording->locale }}">

    @if ($wording->required)
        <input type="hidden" name="agreed[]" value="{{ $wording->purpose }}">
        <p>{{ $wording->text }}</p>
    @else
        <label>
            <input type="checkbox" name="agreed[]" value="{{ $wording->purpose }}">
            {{ $wording->text }}
        </label>
    @endif
@endforeach
```

Render optional purposes as separate, unticked checkboxes. `purposes` says what
was shown, `agreed` what the person agreed to: the primary purpose by sending the
form, the others by ticking them. Version and locale travel with the form, so a
wording change between showing and posting still records what was shown.

## 4. Sign up with the purposes chosen

Post back what was shown, for every purpose the person chose; leave out the
newsletter if its box stayed empty.

**Over HTTP:**

```http
POST /waitlist
Content-Type: application/json
Accept: application/json

{"email": "jane@example.com", "list": "default", "purposes": {"waitlist": {"version": "2026-10", "locale": null}}}
```

The answer is `202` for new and known addresses alike. A `422` names the field
(`email`, `list`, `purposes`).

**In your own controllers:**

```php
use Illuminate\Support\Arr;
use Taldres\Waitlist\Exceptions\WaitlistException;
use Taldres\Waitlist\Http\Rules\PurposeChoice;
use Taldres\Waitlist\Support\RequestContext;

public function store(Request $request)
{
    $validated = $request->validate([
        'email' => ['required', 'email:filter'],
        'purposes' => ['required', 'array'],
        'purposes.*' => ['required', new PurposeChoice],
        'agreed' => ['required', 'array'],
        'agreed.*' => ['string'],
    ]);

    $agreed = Arr::only($validated['purposes'], $validated['agreed']);

    try {
        Waitlist::for('default')->add($validated['email'], $agreed, context: RequestContext::fromRequest($request));
    } catch (WaitlistException $exception) {
        return back()->withErrors(['purposes' => $exception->getMessage()]);
    }

    return view('waitlist.check-your-inbox');
}
```

Say the same for a new and a known address, so the form reveals nobody.

## 5. Send the confirmation mail and confirm

The package sends no mail. A listener sends the confirmation and records a mail
reference alongside the consent wording:

```php
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Taldres\Waitlist\Events\EntrySubscribed;

class SendWaitlistConfirmationMail implements ShouldQueue, ShouldBeEncrypted
{
    public function handle(EntrySubscribed $event): void
    {
        if (! $event->requiresConfirmation) {
            return;
        }

        Mail::to($event->entry->email)->send(new ConfirmWaitlistMail(
            $event->confirmUrl,
            $event->unsubscribeUrl,
            $event->subscription->consents->pluck('text')->all(),   // what is being confirmed
        ));
        Waitlist::confirmationMailed($event->subscription, 'confirm-mail@2026-10');
    }
}
```

The Mailable, its template and the mailer setup are in
[Mail](mail.md#the-confirmation-mail). The link leads to your confirm page, which
posts the token back. A plain GET on it must not confirm: mail scanners follow
every link.

**Over HTTP:** `POST /waitlist/confirm/{token}` answers `200`, `404` for an
unknown token, `410` for an expired one.

**In your own controllers:**

```php
use Taldres\Waitlist\Exceptions\ExpiredTokenException;
use Taldres\Waitlist\Exceptions\InvalidTokenException;

public function confirm(string $token)
{
    try {
        Waitlist::confirm($token);
    } catch (ExpiredTokenException) {
        return view('waitlist.expired');
    } catch (InvalidTokenException) {
        abort(404);
    }

    return view('waitlist.confirmed');
}
```

## 6. Mail only those who agreed

Ask the package who may get a mail. A purpose is in force only while its cycle is
confirmed and open and it was not withdrawn, and each address comes once, even if
it is on several lists:

```php
use Taldres\Waitlist\Support\Recipient;

// The newsletter: everyone who agreed to it, on any list of the project
Waitlist::recipients('newsletter')->each(
    fn (Recipient $recipient) => Mail::to($recipient->email)->locale($recipient->locale)->queue(new NewsletterMail($recipient)),
);

// The launch of one list: everyone on it, for its primary purpose
Waitlist::for('beta')->recipients()->each(
    fn (Recipient $recipient) => Mail::to($recipient->email)->queue(new LaunchMail($recipient)),
);
```

Each recipient knows the links its mail must carry: for the newsletter they
withdraw the newsletter, for the launch mail they leave `beta`. `locale` is the
language the consent was given in, so the mail can match it.

```php
use Illuminate\Mail\Mailables\Headers;

// in NewsletterMail
public function __construct(public Recipient $recipient) {}

public function content(): Content
{
    return new Content(markdown: 'mail.newsletter', with: [
        'unsubscribeUrl' => $this->recipient->unsubscribeUrl(),
    ]);
}

public function headers(): Headers
{
    return new Headers(text: $this->recipient->listUnsubscribeHeaders());
}
```

The one-click headers need the package routes and an `https` `APP_URL`; without
the routes they are empty. For queries of your own, `WaitlistEntry::query()
->whereConsentedTo('newsletter')` applies the same rule, one row per list.

## 7. Withdraw a purpose

One rule decides what a withdrawal reaches: **lists that share a primary purpose
are separate waitlists; an optional purpose is one consent for the whole
project.** So:

- Withdrawing a list's primary purpose leaves that list, and withdraws the
  purpose wherever else it is only an add-on.
- Withdrawing an optional purpose withdraws it on every list of the project, and
  ends the lists that exist only for it.
- A plain unsubscribe is the same as withdrawing the list's primary purpose.

Nothing ever reaches another project.

Jane is on `beta` and `launch`, both with the primary purpose `waitlist`, agreed
to the newsletter on both, and also joined `news`, a list just for the
newsletter:

| Jane clicks | `beta` | `launch` | `news` |
| --- | --- | --- | --- |
| nothing yet | waitlist, newsletter | waitlist, newsletter | newsletter |
| the newsletter link in a `beta` mail (`?purpose=newsletter`) | waitlist | waitlist | gone |
| the unsubscribe link in a `news` mail, plain or `?purpose=newsletter` | waitlist | waitlist | gone |
| the unsubscribe link in a `beta` mail, plain or `?purpose=waitlist` | gone | waitlist, newsletter | newsletter |

Either way out of the newsletter stops it everywhere, so `recipients('newsletter')`
does not return her; leaving a waitlist never leaves another one. Clicking a
link again changes nothing, and the preference page and `Waitlist::for($list)`
follow the same rule. Lists that need independent consents use different purpose
keys, `beta-updates` and `launch-updates` for example.

A newsletter mail therefore carries `unsubscribeUrl($entry, 'newsletter')`; a mail
about one list carries the plain `unsubscribeUrl($entry)`. `recipients()` picks
the right one.

**Over HTTP:** your unsubscribe page posts the token back:
`POST /waitlist/unsubscribe/{token}`, with `?purpose=newsletter` for one purpose.

**In your own controllers:**

```php
Waitlist::withdrawConsent($token, 'newsletter');        // the newsletter, on every list
Waitlist::unsubscribe($token);                          // the list's primary purpose
Waitlist::for('beta')->withdraw($email, 'newsletter');  // a request that came by mail
```

Listeners hear `ConsentWithdrawn` for each list the purpose was withdrawn on and
`EntryUnsubscribed` for each list that was left; pass both on to your mail
provider, see [Sync to your email provider](email-provider-sync.md).

## 8. Let people see, change and erase their data

The link in every mail can only remove. For the preference page, with the
person's data, purposes and erasure, your unsubscribe page offers to mail a
short-lived manage link:

```http
POST /waitlist/unsubscribe/<the unsubscribe token>/manage-link
```

or `Waitlist::requestManageLink($token)`. A listener for `ManageLinkRequested`
mails `$event->manageUrl` to `$event->entry->email`, see
[Mail](mail.md#the-preference-page-link). The preference page then uses
`GET /waitlist/manage/{token}`, `PUT …/purposes`, `POST …/data`,
`…/unsubscribe` and `…/erase`; see
[the SPA example](examples/landing-page-spa.md#5-the-preference-page).

## 9. Before you go live

- Laravel's scheduler runs: `waitlist:prune` applies the retention periods.
- Trusted proxies are set, or every client looks like your proxy to the rate
  limiter.
- Bot protection guards the signup: [Securing the endpoints](securing-the-endpoints.md).
- `php artisan waitlist:privacy` gives you the facts for your record of
  processing.

The full checklist is in [GDPR in practice](gdpr.md#before-you-go-live).

## Next

- [Mail](mail.md): every mail the flow needs, and which provider does what
- [Projects, lists and fields](projects.md): when a second product joins
- [Lifecycle](lifecycle.md): cycles, tokens, and what fires when
- [Reporting](reporting.md): daily counts and the confirmed count over time
