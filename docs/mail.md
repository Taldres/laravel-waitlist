# Mail

The package sends no mail and talks to no mail provider. It tells you when a
mail is due (an event), which links that mail must carry, and who may receive
it. Your application sends it, through whatever Laravel mailer it already uses.

## Two kinds of provider

| | What it does | Examples | How it connects |
| --- | --- | --- | --- |
| **Sending service** | delivers the mails your app sends: the confirmation, the preference page link, and, if you like, launch mails and newsletters | Resend, Postmark, Amazon SES, Mailgun, any SMTP server | a [Laravel mailer](https://laravel.com/docs/mail); the package never sees it |
| **Newsletter tool** | keeps its own contact list and sends campaigns from there | Brevo, Mailchimp, Mailcoach | a sync driven by the package's events, see [Sync to your email provider](email-provider-sync.md) |

With double opt-in you need a sending service, for the confirmation mail. A newsletter tool
is optional: you can send launch mails and newsletters from Laravel too, to
`recipients()`, and then the waitlist is the only list there is. Nothing needs
syncing, and a withdrawal takes effect with the next mail. A newsletter tool
gives you its editor, scheduling and statistics instead, in exchange for keeping
a second list in step, in both directions.

## Which mail, when

| Mail | Due when | Carries | Required |
| --- | --- | --- | --- |
| [Confirmation](#the-confirmation-mail) | `EntrySubscribed` with `requiresConfirmation` | `confirmUrl`, `unsubscribeUrl` | with double opt-in |
| [Preference page link](#the-preference-page-link) | `ManageLinkRequested` | `manageUrl`, only ever to the address itself | when you offer the preference page |
| [Welcome](#a-welcome-mail) | `EntryConfirmed` | `unsubscribeUrl`, one-click headers | no |
| [Launch, updates about a list](#launch-mails-and-newsletters) | you decide | a link that leaves the list | no |
| [Newsletter, an optional purpose](#launch-mails-and-newsletters) | you decide | a link that withdraws the newsletter | no |

Every listener below is queued and implements `ShouldBeEncrypted`; see
[Queued listeners](#queued-listeners) for why.

## Set up a mailer

Any [Laravel mail driver](https://laravel.com/docs/mail#driver-prerequisites)
works; the package does not care which. With Resend, for example:

```bash
composer require resend/resend-php
```

```dotenv
MAIL_MAILER=resend
RESEND_KEY=re_xxxxxxxx
MAIL_FROM_ADDRESS=hello@example.com
MAIL_FROM_NAME="Example"
```

Postmark, SES and Mailgun work the same way with their own driver and package;
Laravel's mail documentation lists what each needs. Send from a domain with SPF,
DKIM and DMARC records, or confirmation mails end up in spam.

## The confirmation mail

The double opt-in mail. Without it, nobody on a list with double opt-in ever
reaches `confirmed`.

```php
// app/Mail/ConfirmWaitlistMail.php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ConfirmWaitlistMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<string>  $agreedTo  the wording of each purpose being confirmed
     */
    public function __construct(
        public string $confirmUrl,
        public ?string $unsubscribeUrl,
        public array $agreedTo,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Please confirm your spot on the waitlist');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.waitlist.confirm');
    }
}
```

```blade
{{-- resources/views/mail/waitlist/confirm.blade.php --}}
<x-mail::message>
# One more step

Please confirm that you asked us to:

@foreach ($agreedTo as $text)
- {{ $text }}
@endforeach

<x-mail::button :url="$confirmUrl">Confirm</x-mail::button>

If you didn't sign up, ignore this mail and nothing happens.
@if ($unsubscribeUrl)
You can also [unsubscribe]({{ $unsubscribeUrl }}) right away.
@endif
</x-mail::message>
```

```php
// app/Listeners/SendWaitlistConfirmationMail.php
namespace App\Listeners;

use App\Mail\ConfirmWaitlistMail;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Taldres\Waitlist\Events\EntrySubscribed;
use Taldres\Waitlist\Facades\Waitlist;

class SendWaitlistConfirmationMail implements ShouldQueue, ShouldBeEncrypted
{
    public function handle(EntrySubscribed $event): void
    {
        if (! $event->requiresConfirmation) {
            return;
        }

        // Null only while the project sets no confirm page and the package
        // routes are off: fail loudly rather than mail a confirmation without a link.
        $confirmUrl = $event->confirmUrl ?? throw new LogicException('Set the confirm page in urls() or enable the package routes.');

        Mail::to($event->entry->email)->send(new ConfirmWaitlistMail(
            $confirmUrl,
            $event->unsubscribeUrl,
            $event->subscription->consents->pluck('text')->all(),
        ));

        // The reference of what you sent, e.g. template and version.
        Waitlist::confirmationMailed($event->subscription, 'confirm-mail@2026-10');
    }
}
```

Laravel discovers the listener in `app/Listeners` by itself.

- **Resends.** The same event carries every later confirmation request, with
  `isNewCycle` false. Do not throttle in the listener: a repeat signup only
  reaches you once the package's cooldown and caps allow it.
- **`requiresConfirmation` false.** The list has no double opt-in, the cycle
  started confirmed, and `EntryConfirmed` follows right away.
- **The reference.** `confirmationMailed()` records the reference your listener
  reports next to the consent wording; keep the text of each version of the mail.
  See [GDPR in practice](gdpr.md#proof-of-the-double-opt-in).
- **A mail that could not be sent.** A request counts against the resend cooldown
  and the caps as soon as it is issued, before any mail exists. When your queued
  listener gives up, report it from its `failed()` method:

  ```php
  public function failed(EntrySubscribed $event, Throwable $exception): void
  {
      // The provider's error code, never the address or its message.
      Waitlist::confirmationFailed($event->subscription, 'http-429');
  }
  ```

  The request stops counting, so the person's own retry gets a mail, and the log
  shows `confirmation_failed`. It answers `false` and changes nothing when a
  newer request was issued since, the cycle ended or the entry was erased.
- **The link.** `confirmUrl` points at your confirm page, the `confirm` page of
  the project's [`urls()`](projects.md#pages), or at the package route. A plain
  GET must never confirm, since mail scanners follow every link: the page posts
  the token back. See [HTTP API](reference/http-api.md#where-mail-links-point).

## The preference page link

The link in every mail can only remove someone. To change purposes, download or
erase their data, people use the preference page, which opens with a short-lived
manage link. It is mailed to the address on request, so it stands in for a login:
whoever asks for it never sees it.

```php
// app/Listeners/SendManageLinkMail.php
namespace App\Listeners;

use App\Mail\ManageLinkMail;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Taldres\Waitlist\Events\ManageLinkRequested;

// Queued, so the response time does not tell whether a mail went out.
class SendManageLinkMail implements ShouldQueue, ShouldBeEncrypted
{
    public function handle(ManageLinkRequested $event): void
    {
        Mail::to($event->entry->email)->send(new ManageLinkMail($event->manageUrl, $event->expiresAt));
    }
}
```

Send it to `$event->entry->email` and nowhere else. The link expires after
`manage.token_ttl` minutes (60), so the mail says until when it works.

Someone asks for it from your unsubscribe page, with the unsubscribe token
(`POST /waitlist/manage-link`, `Waitlist::requestManageLink($token)`), or by
address (`Waitlist::for($list)->requestManageLink($email)`). By address, only an
address that confirmed at least once gets one, at most once per
`manage.request_cooldown`. Where you have identified the person yourself,
`Waitlist::manageLink($entry)` mints one directly.

A project with one list and one purpose has nothing to manage beyond leaving,
which the unsubscribe link already does. Without a preference page, turn manage
links off with [`$project->manageLinks(false)`](projects.md#pages): the three
calls above throw `ManageLinksDisabledException`, `POST /waitlist/manage-link`
answers `404` with `manage_links_disabled`, and `ManageLinkRequested` never
fires. A link mailed before the switch works until it expires. Requests for
access or erasure then reach you another way, such as the contact in your
privacy notice, and `waitlist:export` and `waitlist:forget` answer them.

## A welcome mail

Optional: a mail once the double opt-in is completed. Check the rules that apply
to your messages; this example is no statement that sending them is lawful.

```php
// app/Listeners/SendWaitlistWelcomeMail.php
namespace App\Listeners;

use App\Mail\WaitlistWelcomeMail;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Taldres\Waitlist\Events\EntryConfirmed;
use Taldres\Waitlist\Facades\Waitlist;

class SendWaitlistWelcomeMail implements ShouldQueue, ShouldBeEncrypted
{
    public function handle(EntryConfirmed $event): void
    {
        Mail::to($event->entry->email)->send(new WaitlistWelcomeMail(
            $event->unsubscribeUrl,
            Waitlist::listUnsubscribeHeaders($event->entry),
        ));
    }
}
```

```php
// in WaitlistWelcomeMail
use Illuminate\Mail\Mailables\Headers;

/**
 * @param  array<string, string>  $listUnsubscribe
 */
public function __construct(public ?string $unsubscribeUrl, public array $listUnsubscribe = []) {}

// RFC 8058 one-click: mail clients show their own button and POST to the package.
public function headers(): Headers
{
    return new Headers(text: $this->listUnsubscribe);
}
```

`EntryConfirmed` carries a ready-made `unsubscribeUrl`. The unsubscribe token is
not rotated, so a retried job builds the same link.

## Launch mails and newsletters

Ask the package who may get a mail before you send it. `recipients()` streams
everyone a mail for a purpose may go to, once per address, with the links that
mail must carry:

```php
use Taldres\Waitlist\Support\Recipient;

// The launch of one list: everyone on it, for its primary purpose.
// Its links leave that list.
Waitlist::for('beta')->recipients()->each(
    fn (Recipient $recipient) => Mail::to($recipient->email)->locale($recipient->locale)->queue(new LaunchMail($recipient)),
);

// The newsletter: everyone who agreed to it, on any list of the project.
// Its links withdraw the newsletter only.
Waitlist::recipients('newsletter')->each(
    fn (Recipient $recipient) => Mail::to($recipient->email)->locale($recipient->locale)->queue(new NewsletterMail($recipient)),
);
```

```php
// in NewsletterMail
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Headers;
use Taldres\Waitlist\Support\Recipient;

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

`locale` is the language the consent was given in, so the mail can match it.
The one-click headers need the package routes and an `https` `APP_URL`; without
the routes they are empty. For queries of your own,
`WaitlistEntry::query()->whereConsentedTo('newsletter')` applies the same rule,
one row per list.

### A link outside an event

For a mail you build from an entry you already hold:

```php
Waitlist::unsubscribeUrl($entry);                // ?string, leaves the list
Waitlist::unsubscribeUrl($entry, 'newsletter');  // ?string, withdraws only the newsletter
Waitlist::listUnsubscribeHeaders($entry, 'newsletter');
Waitlist::unsubscribeToken($entry);              // UnsubscribeToken: ->token, ->url
```

The URL is `null` only when the package routes are off and the project's `urls()`
sets no `unsubscribe` page; then build it yourself from the plain token. Links
sent earlier stay valid: the token only changes when it cannot be decrypted,
after an `APP_KEY` rotation without `APP_PREVIOUS_KEYS`.

Never put a manage link into these mails. It opens the person's data, and mails
get forwarded; your unsubscribe page offers to mail one instead.

## Unsubscribe headers and your provider

The one-click headers are mail headers like any other, and a provider may
restrict custom headers by plan: its API can refuse a mail that carries its own
`List-Unsubscribe` header, with an error that never mentions the header.
MailerSend, for example, answered such a mail with a `422` on a plan below its
Professional plan. Check what your provider allows before you rely on them.

The confirmation mail needs none of them, so it cannot fail for this reason. To
switch them off for a welcome mail, a launch mail or a newsletter, leave
`headers()` out of its mailable. The unsubscribe link in the body stays. Some
providers offer their own unsubscribe handling instead; see their documentation.

## Several projects

Each project is its own product, usually with its own sender. Listeners see the
project on the entry and pick sender, template and mailer from it:

```php
// config/products.php, a file of your own
return [
    'rocket' => ['name' => 'Rocket', 'from' => 'hello@rocket.example', 'mailer' => 'resend'],
    'anvil' => ['name' => 'Anvil', 'from' => 'hello@anvil.example', 'mailer' => 'postmark'],
];
```

```php
// in SendWaitlistConfirmationMail
public function handle(EntrySubscribed $event): void
{
    if (! $event->requiresConfirmation) {
        return;
    }

    $product = config("products.{$event->entry->project}");
    $confirmUrl = $event->confirmUrl ?? throw new LogicException("Set the confirm page of {$event->entry->project} in urls() or enable the package routes.");

    $mail = (new ConfirmWaitlistMail($confirmUrl, $event->unsubscribeUrl, $event->subscription->consents->pluck('text')->all()))
        ->from($product['from'], $product['name']);

    Mail::mailer($product['mailer'])->to($event->entry->email)->send($mail);

    Waitlist::confirmationMailed($event->subscription, "confirm-mail@{$event->entry->project}-2026-10");
}
```

Send from each product's own domain, with its own SPF and DKIM records. `ManageLinkRequested`,
`EntryConfirmed` and `recipients()` give you the entry, and so the project, the
same way. The mail links already point at each project's own pages.

## Queued listeners

Queue every listener that sends mail, and make it implement `ShouldBeEncrypted`:

- **Timing.** The signup and the manage link request answer identically for new
  and known addresses. A response that takes longer when a mail goes out tells
  what the identical body hides.
- **Payload.** The events carry the plain tokens, and `EntryForgotten` the
  address. Serialized onto your queue, they sit in its storage until processed;
  `ShouldBeEncrypted` keeps them unreadable there.

All package events are dispatched after the surrounding transaction commits, so a
listener never mails about a state that was rolled back. A dispatched event does
not prove the mail went out: monitor failed jobs.
