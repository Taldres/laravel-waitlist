# Sync to your email provider

Goal: keep a newsletter tool (Brevo, Mailcoach, Mailchimp, …) in step with the
waitlist, per purpose, including removal on erasure, and carry unsubscribes made
at the provider back into the waitlist.

Do you need this? Only if you send campaigns from the tool. Mails your Laravel
app sends, through Resend, Postmark, SES or any other mailer, need no sync: send
them to `recipients()`, see [Mail](mail.md#two-kinds-of-provider).

The operator must configure and monitor this integration and handle other copies
separately; firing a package event does not confirm that a provider has
completed erasure.

## From the waitlist to the provider

| Event | Sync action |
| --- | --- |
| `EntryConfirmed` | Add the contact, with a list or tag per purpose in force |
| `ConsentGranted` | Add the contact to that purpose's list |
| `ConsentWithdrawn` | Remove the contact from that purpose's list, unless another list still holds it |
| `EntryUnsubscribed` | Remove the contact from the list of every purpose the cycle held, unless another list still holds it |
| `EntryForgotten` | Delete the contact once the address is on no list at all; otherwise drop the purposes the remaining entries do not hold |

> Subscribe-time (`EntrySubscribed`) is too early to sync: with double opt-in the
> address is unconfirmed and must not receive anything but the confirmation mail.
> That also means an abandoned signup (`SubscriptionExpired`) was never synced.
> On a list without double opt-in, `EntryConfirmed` fires right away.

Three things decide what a sync may remove:

- **Leaving a list ends its cycle, with every purpose it held.** `EntryUnsubscribed`
  fires for each list a person leaves; a newsletter agreed to on that list ends
  with it, without a `ConsentWithdrawn` of its own. `ConsentWithdrawn` fires for an
  optional purpose withdrawn while the list goes on.
- **Leaving one list never leaves another.** Lists that share a primary purpose are
  separate waitlists, and the newsletter may be held on another list too.
- **An erasure can concern one list only.** `EntryForgotten` fires for every
  erased entry: a person's erasure request, but also a list erased once its
  purpose is fulfilled (`forgetAll()`) and an old entry removed by retention,
  while the same address may still be on another list.

The example therefore removes a contact from a purpose's list only when no list
of the project still holds that purpose, and deletes the contact only when no
entry is left.

```php
// config/services.php
'brevo' => [
    'key' => env('BREVO_API_KEY'),
    'lists' => ['waitlist' => 3, 'newsletter' => 7],   // the Brevo list id per purpose
],
```

```php
// app/Listeners/SyncWaitlistToBrevo.php
namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Taldres\Waitlist\Events\ConsentGranted;
use Taldres\Waitlist\Events\ConsentWithdrawn;
use Taldres\Waitlist\Events\EntryConfirmed;
use Taldres\Waitlist\Events\EntryForgotten;
use Taldres\Waitlist\Events\EntryUnsubscribed;
use Taldres\Waitlist\Models\WaitlistEntry;

class SyncWaitlistToBrevo implements ShouldQueue, ShouldBeEncrypted
{
    public function onConfirmed(EntryConfirmed $event): void
    {
        $this->brevo()->post('contacts', [
            'email' => $event->entry->email,
            'listIds' => array_map($this->listFor(...), $event->entry->purposes),
            'updateEnabled' => true,
        ]);
    }

    public function onGranted(ConsentGranted $event): void
    {
        // A purpose can be added while the address is still unconfirmed; it is
        // synced with the rest on EntryConfirmed then.
        if (! $event->subscription->isConfirmed()) {
            return;
        }

        $this->brevo()->post("contacts/lists/{$this->listFor($event->consent->purpose)}/contacts/add", [
            'emails' => [$event->entry->email],
        ]);
    }

    public function onWithdrawn(ConsentWithdrawn $event): void
    {
        $this->removeUnlessHeld($event->entry->project, $event->entry->email, $event->consent->purpose);
    }

    public function onUnsubscribed(EntryUnsubscribed $event): void
    {
        // Leaving ends the cycle, and with it every purpose it held.
        $event->subscription->consents()->pluck('purpose')->unique()->each(
            fn (string $purpose) => $this->removeUnlessHeld($event->entry->project, $event->entry->email, $purpose),
        );
    }

    public function onForgotten(EntryForgotten $event): void
    {
        // The entry is gone; the event carries scalars. The address is null only
        // when it could not be decrypted.
        if ($event->email === null) {
            return;
        }

        if (! WaitlistEntry::query()->forEmail($event->email)->exists()) {
            $this->brevo()->delete('contacts/'.urlencode($event->email));

            return;
        }

        // Still on another list: keep the contact, drop what the remaining entries do not hold.
        foreach (array_keys(config('services.brevo.lists')) as $purpose) {
            $this->removeUnlessHeld($event->project, $event->email, $purpose);
        }
    }

    private function removeUnlessHeld(string $project, string $email, string $purpose): void
    {
        // The events fire after the commit, so the purpose is only still in
        // force when another list of the project holds it.
        $held = WaitlistEntry::query()
            ->inProject($project)
            ->forEmail($email)
            ->whereConsentedTo($purpose)
            ->exists();

        if (! $held) {
            $this->brevo()->post("contacts/lists/{$this->listFor($purpose)}/contacts/remove", [
                'emails' => [$email],
            ]);
        }
    }

    private function listFor(string $purpose): int
    {
        return (int) config("services.brevo.lists.{$purpose}");
    }

    private function brevo(): PendingRequest
    {
        return Http::baseUrl('https://api.brevo.com/v3')->withHeaders(['api-key' => config('services.brevo.key')]);
    }
}
```

`ShouldBeEncrypted` keeps the payload encrypted while it waits in your queue: it
holds the entry and, for `EntryForgotten`, the address in plain text.

Register the listener methods:

```php
// app/Providers/AppServiceProvider.php
use App\Listeners\SyncWaitlistToBrevo;
use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Events\ConsentGranted;
use Taldres\Waitlist\Events\ConsentWithdrawn;
use Taldres\Waitlist\Events\EntryConfirmed;
use Taldres\Waitlist\Events\EntryForgotten;
use Taldres\Waitlist\Events\EntryUnsubscribed;

public function boot(): void
{
    Event::listen(EntryConfirmed::class, [SyncWaitlistToBrevo::class, 'onConfirmed']);
    Event::listen(ConsentGranted::class, [SyncWaitlistToBrevo::class, 'onGranted']);
    Event::listen(ConsentWithdrawn::class, [SyncWaitlistToBrevo::class, 'onWithdrawn']);
    Event::listen(EntryUnsubscribed::class, [SyncWaitlistToBrevo::class, 'onUnsubscribed']);
    Event::listen(EntryForgotten::class, [SyncWaitlistToBrevo::class, 'onForgotten']);
}
```

The methods do not start with `handle` on purpose. Laravel's event discovery
registers every public `handle*` method in `app/Listeners` by itself, so with
the calls above each sync would run twice.

With [several projects](projects.md), keep one provider list per project and
purpose: `$event->entry->project` and `$event->project` (on `EntryForgotten`)
tell you which, and the `inProject()` check above already stays within one. One
contact across projects is deleted only once the address is on no list of any
project, which is what the `forEmail()` check without `inProject()` does.

## From the provider back to the waitlist

Campaigns sent from the tool carry the tool's own unsubscribe link. When someone
uses it, the waitlist does not know, and `recipients('newsletter')` would still
return them. Most providers report unsubscribes through a webhook; withdraw the
purpose there too:

```php
// routes/web.php (exclude it from CSRF protection) or routes/api.php
use Illuminate\Http\Request;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;

Route::post('/webhooks/newsletter', function (Request $request) {
    // Verify the request as your provider documents it, e.g. a signature or
    // a secret in the URL, before trusting anything in it.

    $email = (string) $request->input('email');   // where the address is depends on the provider

    // From every list the address is on: lists that have the newsletter as
    // their primary purpose are separate waitlists, and a repeat changes nothing.
    Waitlist::findByEmail($email)->each(
        fn (WaitlistEntry $entry) => Waitlist::for($entry->list)->withdraw($email, 'newsletter'),
    );

    return response()->noContent();
});
```

With several projects, map the provider list the unsubscribe came from to its
project and use `Waitlist::project($project)->findByEmail()` and
`Waitlist::project($project)->for($entry->list)->withdraw()`. The withdrawal fires `ConsentWithdrawn`, so
the listener above asks the provider to remove the contact once more; that call
may find nothing left to do.

Prefer it the other way round? Put the waitlist's own link, `unsubscribeUrl($entry,
'newsletter')`, into a contact attribute and use it in your campaign template.
Many providers insist on their own link as well, so keep the webhook either way.

## Your record of processing

`php artisan waitlist:privacy` lists this listener as a recipient. The
provider is a processor: you need an agreement with it, and it belongs in your
record of processing.
