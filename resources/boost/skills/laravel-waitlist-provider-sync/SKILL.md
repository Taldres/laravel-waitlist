---
name: laravel-waitlist-provider-sync
description: >
  Keep a newsletter tool such as Brevo, Mailchimp or Mailcoach in step with a
  taldres/laravel-waitlist waitlist: add confirmed contacts per purpose, remove
  them on withdrawal and erasure, and carry unsubscribes made at the provider
  back into the waitlist via a webhook.
license: MIT
metadata:
  author: Dennis Petersmann
---

# Laravel Waitlist: sync to an email provider

Use this skill when an application using `taldres/laravel-waitlist` sends
campaigns from an external newsletter tool and its contact lists must follow the
waitlist's consents.

## Primary Goal

- the provider only holds contacts whose purpose is in force, and a withdrawal on
  either side reaches the other

## Workflow

### 1. Decide whether a sync is needed

Mails the Laravel app sends itself (through any mailer) need no sync: send them to
`Waitlist::recipients($purpose)`. Sync only when campaigns are sent from the tool.

### 2. Map events to provider calls

| Event | Provider action |
| --- | --- |
| `EntryConfirmed` | add the contact, to one provider list per purpose in `$event->entry->purposes` |
| `ConsentGranted` | add to that purpose's list, once `$event->subscription->isConfirmed()` |
| `ConsentWithdrawn` | remove from `$event->consent->purpose`'s list, unless another list of the project still holds it |
| `EntryUnsubscribed` | remove from the list of **every** purpose the cycle held, unless another list still holds it |
| `EntryForgotten` | delete the contact only when no entry of the address is left; otherwise drop the purposes the remaining entries do not hold |

- Leaving a list ends its cycle with every purpose on it: a newsletter agreed to on
  that list ends too, and no `ConsentWithdrawn` fires for it.
- `EntryForgotten` fires for every erased entry, also for `forgetAll()` and
  retention pruning of one list, while the address may still be on another list.
- Do not sync on `EntrySubscribed`: with double opt-in the address is unconfirmed.

### 3. One queued, encrypted listener class

```php
namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Taldres\Waitlist\Events\ConsentGranted;
use Taldres\Waitlist\Events\ConsentWithdrawn;
use Taldres\Waitlist\Events\EntryConfirmed;
use Taldres\Waitlist\Events\EntryForgotten;
use Taldres\Waitlist\Events\EntryUnsubscribed;
use Taldres\Waitlist\Models\WaitlistEntry;

class SyncWaitlistToProvider implements ShouldQueue, ShouldBeEncrypted
{
    public function onConfirmed(EntryConfirmed $event): void
    {
        // add $event->entry->email to the provider list of each of $event->entry->purposes
    }

    public function onGranted(ConsentGranted $event): void
    {
        if ($event->subscription->isConfirmed()) {
            // add $event->entry->email to the list of $event->consent->purpose
        }
    }

    public function onWithdrawn(ConsentWithdrawn $event): void
    {
        $this->removeUnlessHeld($event->entry->project, $event->entry->email, $event->consent->purpose);
    }

    public function onUnsubscribed(EntryUnsubscribed $event): void
    {
        $event->subscription->consents()->pluck('purpose')->unique()->each(
            fn (string $purpose) => $this->removeUnlessHeld($event->entry->project, $event->entry->email, $purpose),
        );
    }

    public function onForgotten(EntryForgotten $event): void
    {
        if ($event->email === null) {   // could not be decrypted
            return;
        }

        if (! WaitlistEntry::query()->forEmail($event->email)->exists()) {
            // delete the contact $event->email
            return;
        }

        foreach (['waitlist', 'newsletter'] as $purpose) {   // the purposes you sync
            $this->removeUnlessHeld($event->project, $event->email, $purpose);
        }
    }

    private function removeUnlessHeld(string $project, string $email, string $purpose): void
    {
        // Events fire after commit: the purpose is only still in force when
        // another list of the project holds it.
        $held = WaitlistEntry::query()->inProject($project)->forEmail($email)->whereConsentedTo($purpose)->exists();

        if (! $held) {
            // remove $email from the provider list for $purpose
        }
    }
}
```

Register the methods by hand. They deliberately do not start with `handle`, so
Laravel's event discovery does not register them a second time:

```php
// AppServiceProvider::boot()
Event::listen(EntryConfirmed::class, [SyncWaitlistToProvider::class, 'onConfirmed']);
Event::listen(ConsentGranted::class, [SyncWaitlistToProvider::class, 'onGranted']);
Event::listen(ConsentWithdrawn::class, [SyncWaitlistToProvider::class, 'onWithdrawn']);
Event::listen(EntryUnsubscribed::class, [SyncWaitlistToProvider::class, 'onUnsubscribed']);
Event::listen(EntryForgotten::class, [SyncWaitlistToProvider::class, 'onForgotten']);
```

Use Laravel's `Http` client for the provider API; keep credentials and the list
id per purpose in `config/services.php`.

### 4. Carry provider unsubscribes back

Campaigns from the tool carry its own unsubscribe link. Receive its webhook and
withdraw the purpose from every list of the address; a repeat changes nothing:

```php
// routes/api.php, or routes/web.php with the route excluded from CSRF protection
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;

Route::post('/webhooks/newsletter', function (Request $request) {
    // verify the webhook as the provider documents it first
    $email = (string) $request->input('email'); // field name depends on the provider

    Waitlist::findByEmail($email)->each(
        fn (WaitlistEntry $entry) => Waitlist::for($entry->list)->withdraw($email, 'newsletter'),
    );

    return response()->noContent();
});
```

Several projects: map the provider list to its project and use
`Waitlist::project($project)->findByEmail()` and
`Waitlist::project($project)->for($entry->list)->withdraw()`.

## Rules, References, and Templates

- One provider list per project and purpose; never one list per waitlist list for
  an optional purpose, since an optional purpose is one consent per project.
- Lists that share a primary purpose are separate waitlists: leaving one never
  removes the contact while another list still holds the purpose.
- `php artisan waitlist:privacy` lists the listener as a recipient; the provider is
  a processor and needs an agreement.
- Full guide with a complete Brevo listener:
  https://github.com/Taldres/laravel-waitlist/blob/main/docs/email-provider-sync.md

## Examples

- "Sync confirmed newsletter signups to Brevo": steps 2 and 3, with
  `config('services.brevo.lists.newsletter')` as the list id.
- "People who unsubscribe in Mailchimp still get our launch mail": step 4.

## Anti-patterns

- Syncing on `EntrySubscribed`, before the double opt-in.
- Removing a contact on `EntryUnsubscribed` without checking other lists of the
  project, or removing only the list's primary purpose.
- Deleting the contact on every `EntryForgotten`: retention and `forgetAll()` erase
  one list while the address may still be on another.
- Naming the listener methods `handle*` and registering them with `Event::listen()`
  as well: every sync runs twice.
- A webhook that withdraws from one entry only, or trusts the request without
  verifying it.
- Treating a dispatched `EntryForgotten` as proof the provider deleted the contact;
  monitor failed jobs.
