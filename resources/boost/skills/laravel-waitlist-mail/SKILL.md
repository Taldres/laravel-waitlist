---
name: laravel-waitlist-mail
description: >
  Send the mails of a taldres/laravel-waitlist integration: the double opt-in
  confirmation, the preference page link, a welcome mail, launch mails and
  newsletters to the people who agreed, with the right unsubscribe links,
  one-click headers and a sender per project.
license: MIT
metadata:
  author: Dennis Petersmann
---

# Laravel Waitlist: mail

Use this skill when an application using `taldres/laravel-waitlist` needs to send
any mail to people on a waitlist, or set up a mail provider for it.

## Primary Goal

- every mail the flow needs goes out from a queued, encrypted listener, carries
  the link that matches its purpose, and reaches only addresses with consent in force

## Workflow

### 1. Know which mail is due when

The package sends no mail and calls no provider. It fires events; the app's
listeners send through any Laravel mailer (Resend, Postmark, SES, SMTP, …).

| Mail | Due on | Must carry |
| --- | --- | --- |
| Confirmation (double opt-in) | `EntrySubscribed` with `$event->requiresConfirmation` | `$event->confirmUrl`, `$event->unsubscribeUrl` |
| Preference page link | `ManageLinkRequested` | `$event->manageUrl`, sent only to `$event->entry->email` |
| Welcome (optional) | `EntryConfirmed` | `$event->unsubscribeUrl`, `Waitlist::listUnsubscribeHeaders($event->entry)` |
| Launch or update about one list | the app decides | per-recipient link that leaves the list |
| Newsletter (optional purpose) | the app decides | per-recipient link that withdraws only the newsletter |

### 2. Confirmation listener

```php
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

        $confirmUrl = $event->confirmUrl ?? throw new LogicException('Set the confirm page in urls() or enable the package routes.');

        Mail::to($event->entry->email)->send(new ConfirmWaitlistMail(
            $confirmUrl,
            $event->unsubscribeUrl,
            $event->subscription->consents->pluck('text')->all(), // what is being confirmed
        ));

        Waitlist::confirmationMailed($event->subscription, 'confirm-mail@2026-10');
    }
}
```

- The same event carries resends (`$event->isNewCycle === false`). Do not throttle in
  the listener; the package's cooldown and caps already did.
- `confirmationMailed()` records the reference of the mail next to the consent;
  use a template name and version, and keep each version's text.
- `$event->confirmUrl` is `null` while the project's `urls()` sets no `confirm` page
  and the package routes are off. A link that cannot be built (the routes are on
  but not registered, as after a stale route cache) throws
  `InvalidConfigurationException` and undoes the signup or confirmation: nothing is
  committed, so no event fires and no mail is due.

### 3. Preference page link listener

```php
use Taldres\Waitlist\Events\ManageLinkRequested;

class SendManageLinkMail implements ShouldQueue, ShouldBeEncrypted
{
    public function handle(ManageLinkRequested $event): void
    {
        Mail::to($event->entry->email)->send(new ManageLinkMail($event->manageUrl, $event->expiresAt));
    }
}
```

The link expires after `waitlist.manage.token_ttl` minutes (60); say so in the mail.

### 4. Launch mails and newsletters: ask `recipients()`

```php
use Taldres\Waitlist\Support\Recipient;

// one list, for its primary purpose: links leave that list
Waitlist::for('beta')->recipients()->each(
    fn (Recipient $recipient) => Mail::to($recipient->email)->locale($recipient->locale)->queue(new LaunchMail($recipient)),
);

// an optional purpose, project-wide, once per address: links withdraw only it
Waitlist::recipients('newsletter')->each(
    fn (Recipient $recipient) => Mail::to($recipient->email)->locale($recipient->locale)->queue(new NewsletterMail($recipient)),
);
```

In the Mailable, use the recipient's links:

```php
use Illuminate\Mail\Mailables\Headers;

public function __construct(public Recipient $recipient) {}

public function headers(): Headers
{
    return new Headers(text: $this->recipient->listUnsubscribeHeaders()); // RFC 8058 one-click
}

// content: $this->recipient->unsubscribeUrl()
```

`Recipient` has `entry`, `email`, `purpose`, `locale` (the consent's language, or
null) and `leavesList`. Queued Mailables carry the address and token links: let
them implement `ShouldBeEncrypted` too. One-click headers need the package routes and an `https`
`APP_URL`; without routes they are empty. A provider may refuse custom headers on
some plans (MailerSend answers a `422` below its Professional plan): the
confirmation mail needs none, and a mailable without `headers()` sends none.

### 5. A link outside events

```php
Waitlist::unsubscribeUrl($entry);                // leaves the list
Waitlist::unsubscribeUrl($entry, 'newsletter');  // withdraws only the newsletter
Waitlist::listUnsubscribeHeaders($entry, 'newsletter');
```

### 6. Several projects

The project is `$event->entry->project` (or `$recipient->entry->project`). Pick
sender and mailer from it:

```php
// config/products.php (the app's own): ['rocket' => ['name' => 'Rocket', 'from' => 'hello@rocket.example', 'mailer' => 'resend'], ...]
$product = config("products.{$event->entry->project}");

$mail = (new ConfirmWaitlistMail(...))->from($product['from'], $product['name']);
Mail::mailer($product['mailer'])->to($event->entry->email)->send($mail);
```

Mail links already point at each project's own pages (`urls()`).

## Rules, References, and Templates

- Every listener that sends mail implements `ShouldQueue` (identical response
  times for new and known addresses) and `ShouldBeEncrypted` (payloads hold
  tokens; `EntryForgotten` holds the address).
- Laravel discovers listeners in `app/Listeners` by their `handle()` type hint;
  registering them again with `Event::listen()` sends every mail twice.
- Events dispatch after commit; a dispatched event does not prove the mail went
  out, so monitor failed jobs.
- Mail driver setup: https://laravel.com/docs/mail; send from a domain with SPF,
  DKIM and DMARC.
- Full guide: https://github.com/Taldres/laravel-waitlist/blob/main/docs/mail.md

## Examples

- "Send the double opt-in mail with Resend": `composer require resend/resend-php`,
  `MAIL_MAILER=resend`, `RESEND_KEY=...`, then the listener from step 2.
- "Email everyone on the beta list that we launched": step 4 with
  `Waitlist::for('beta')->recipients()`.
- "Send the monthly newsletter": `Waitlist::recipients('newsletter')`, one-click
  headers per recipient.

## Anti-patterns

- Mailing `WaitlistEntry::all()` or `Waitlist::for($list)->entries()` without a
  consent check; use `recipients()` or `whereConsentedTo($purpose)`.
- Putting `Waitlist::manageLink($entry)` or a manage URL into ordinary mails; it
  opens the person's data and mails get forwarded.
- Sending the manage link anywhere but `$event->entry->email`, or answering the
  request with it.
- A newsletter link that unsubscribes from the whole list: use
  `unsubscribeUrl($entry, 'newsletter')` or the `Recipient`'s own link.
- Synchronous mail listeners, or queued ones without `ShouldBeEncrypted`.
