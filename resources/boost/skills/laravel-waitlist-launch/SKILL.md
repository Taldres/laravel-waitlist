---
name: laravel-waitlist-launch
description: >
  Turn a taldres/laravel-waitlist waitlist into a launch: invite people in
  batches in signup order, let invited addresses register, announce the launch to
  everyone who agreed, and erase the list once its purpose is fulfilled.
license: MIT
metadata:
  author: Dennis Petersmann
---

# Laravel Waitlist: from waitlist to launch

Use this skill when an application using `taldres/laravel-waitlist` wants to let
people in: early access invitations, a launch announcement, and cleaning up
afterwards. The package has no `invited` state on purpose; build it on top.

## Primary Goal

- invite and announce only to addresses whose purpose is in force, record the
  invitation in the app, and erase the list when it has served its purpose

## Workflow

### 1. Record invitations on your own model

```php
// migration in the app
Schema::table('waitlist_entries', function (Blueprint $table) {
    $table->timestamp('invited_at')->nullable();
});
```

```php
namespace App\Models;

use Taldres\Waitlist\Models\WaitlistEntry as BaseEntry;

class WaitlistEntry extends BaseEntry
{
    public function __construct(array $attributes = [])
    {
        $this->mergeFillable(['invited_at']);   // before the parent fills the model

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return parent::casts() + ['invited_at' => 'datetime'];
    }
}
```

```php
// config/waitlist.php
'model' => App\Models\WaitlistEntry::class,
```

If the subclass overrides `booted()`, it must call `parent::booted()`.

### 2. Invite the next batch, first come first served

```php
use Illuminate\Support\Facades\Mail;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\ScopedWaitlist;

// AppServiceProvider::boot()
ScopedWaitlist::macro('inviteNext', function (int $count = 10) {
    /** @var ScopedWaitlist $this */
    return $this->entries()
        ->whereConsentedTo('waitlist')   // the list's primary purpose: confirmed and still on the list
        ->whereNull('invited_at')
        ->oldest()
        ->limit($count)
        ->get()
        ->each(function ($entry) {
            $entry->forceFill(['invited_at' => now()])->save();
            Mail::to($entry->email)->queue(new YouAreInMail($entry, Waitlist::unsubscribeUrl($entry)));
        });
});
```

```php
Waitlist::for('beta')->inviteNext(25);   // from a scheduled command, an admin action or Tinker
```

`YouAreInMail` carries an unsubscribe URL with a token; let it implement
`ShouldBeEncrypted` so the queued mail is stored encrypted.

### 3. Let invited addresses in

```php
$entry = Waitlist::for('beta')->find($request->string('email')->value());

abort_unless($entry?->invited_at !== null && $entry->hasConsentFor('waitlist'), 403);
```

`find()` also returns addresses that left; check `hasConsentFor()` or the status.

### 4. Announce the launch

```php
use Taldres\Waitlist\Support\Recipient;

Waitlist::for('beta')->recipients()->each(
    fn (Recipient $recipient) => Mail::to($recipient->email)->locale($recipient->locale)->queue(new LaunchMail($recipient)),
);
```

`recipients()` returns everyone whose primary purpose is in force, once each, with
an unsubscribe link and one-click headers for that list
(`$recipient->unsubscribeUrl()`, `$recipient->listUnsubscribeHeaders()`).

### 5. Erase the list once its purpose is fulfilled

When the waitlist's purpose ("email me when early access opens") is fulfilled,
erase it:

```bash
php artisan waitlist:forget --list=beta --all     # asks first; --force without a terminal
```

or `Waitlist::for('beta')->forgetAll()`. It erases every entry of the list with
all its consents, optional ones such as a newsletter included. Consents cannot be
moved: someone keeps the newsletter only through another list they joined
themselves, a `news` list with `newsletter` as its primary purpose for example.
Invite them to join it before erasing.

## Rules, References, and Templates

- Never write the package's subscriptions, consents or activity yourself; your own
  columns on the entry are fine.
- Several projects: `Waitlist::project('anvil')->for('default')->inviteNext(25)`.
- Reports keep counting after the erasure: the log keeps the step and the date,
  not the person.
- Guides: https://github.com/Taldres/laravel-waitlist/blob/main/docs/examples/invite-flow.md,
  https://github.com/Taldres/laravel-waitlist/blob/main/docs/extending.md

## Examples

- "Invite 50 people from the beta waitlist every Monday": the macro from step 2 in
  a scheduled command.
- "Only waitlisted people may register during early access": step 3 in the
  registration controller.
- "We launched, clean up the waitlist": step 4, then step 5.

## Anti-patterns

- Inviting from `entries()` without `whereConsentedTo()`: it includes unconfirmed
  addresses and people who left.
- Keeping the list forever after the launch "just in case".
- Adding `invited` or `rejected` to the package's status or end reasons.
- Putting a manage link into the invitation mail.
