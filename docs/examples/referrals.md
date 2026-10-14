# Example: referrals that move people up the line

Goal: each confirmed person gets a personal link; a signup that arrives through
it and confirms credits the person who shared it; the app invites people in the
order of their credits. The package deliberately has no growth features and
never links one person to another. This builds it on top with two tables of your
app, one signup field, listeners and a macro.

Linking people is processing of its own. Read [Privacy](#privacy) before you
ship it, not after.

## What the example decides

- **A table of the app, not a column on the entry.** The code and the credits
  live in `referral_codes` and `referral_credits`, keyed by the entry's id. An
  own model with columns would work, but personal data about who invited whom
  would then sit in the package's table, next to the address. Two small tables
  can be deleted, exported and described on their own.
- **Credit on confirmation only.** The listener of `EntryConfirmed` credits; a
  signup that never confirms credits nobody, and neither does a repeat signup.
- **The referrer sees a number.** Your app shows it; keep it to the count, with
  no address, no name and no list of the people they brought.
- **Referring is a purpose of its own.** An optional purpose, `referrals`,
  unticked: only someone who agreed gets a code and can be credited.

## 1. Two tables

```php
// database/migrations/xxxx_create_referral_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The macro below joins the waitlist's tables, so these live next to them.
    public function getConnection(): ?string
    {
        return config('waitlist.connection');
    }

    public function up(): void
    {
        Schema::create('referral_codes', function (Blueprint $table) {
            $table->id();
            $table->uuid('entry_id')->unique();
            $table->string('code', 20)->unique();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('referral_credits', function (Blueprint $table) {
            $table->id();
            $table->uuid('referrer_entry_id')->index();
            // At most one credit per referred entry at a time. Leaving the
            // list removes it (step 5); a return can book a new one.
            $table->uuid('referee_entry_id')->unique();
            $table->timestamp('created_at')->useCurrent();
        });
    }
};
```

The migration's `getConnection()` is Laravel's own hook, so the tables follow
`waitlist.connection`. No foreign key to `waitlist_entries`: the cleanup in
[step 5](#5-forget-what-the-package-forgets) is the contract, and it keeps
working when the tables are on another connection.

A small class gives the listeners and the macro one place for the connection:

```php
// app/Referrals/ReferralTables.php
namespace App\Referrals;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReferralTables
{
    public static function codes(): Builder
    {
        return DB::connection(config('waitlist.connection'))->table('referral_codes');
    }

    public static function credits(): Builder
    {
        return DB::connection(config('waitlist.connection'))->table('referral_credits');
    }
}
```

## 2. The purpose and the field

```php
// app/Providers/WaitlistServiceProvider.php
use Illuminate\Validation\Rule;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Facades\Waitlist;

Waitlist::define(function (ProjectDefinition $project): void {
    $project->purpose('waitlist', [
        '2026-10' => 'Email me when early access opens. I can unsubscribe at any time.',
    ]);
    $project->purpose('referrals', [
        '2026-10' => 'Give me a personal link to share. When someone signs up and confirms through it, '
            .'my place in the line moves up. They never see my address, and I never see theirs.',
    ]);

    $project->list('default', purpose: 'waitlist')->optional('referrals');

    // The code someone arrived with. It is checked when the signup confirms,
    // not here: a signup that answered "unknown code" would let anyone probe
    // which codes exist.
    $project->fields(fn () => [
        'ref' => ['nullable', 'string', 'alpha_num', 'max:20'],
    ]);
});
```

The wording is an example; replace it with your own. A new version of it is a
new version, see [Purposes and wording](../purposes-and-wording.md#versions).

Your signup page reads `?ref=` from the link and posts it with the signup, next
to the wording of both purposes and a line that tells the visitor what the code
does (see [the notice](#what-people-are-told)):

```json
{"email": "jane@example.com", "purposes": {"waitlist": "2026-10"}, "metadata": {"ref": "k3x9a1bq7z"}}
```

With the unticked checkbox ticked, the person also asks for a link of their own:

```json
{"email": "jane@example.com", "purposes": {"waitlist": "2026-10", "referrals": "2026-10"}, "metadata": {"ref": "k3x9a1bq7z"}}
```

In your own controllers it is
`Waitlist::for('default')->add($email, ['waitlist' => '2026-10'], ['ref' => $code])`.
`add()` trusts its caller and does not apply the project's fields: validate with
`Waitlist::for('default')->fields()` first, see [Fields](../projects.md#fields).

The code stays in the entry's `metadata`, encrypted like the address. A new opt-in
of a known address merges its metadata into the entry, so a different `ref`
replaces the old one, and the credit goes to whichever code the entry carries
when it confirms. The unique `referee_entry_id` allows one credit row per entry
at a time; it does not remember an earlier credit that was removed when the
person left.

## 3. Issue a code

```php
// app/Listeners/IssueReferralCode.php
namespace App\Listeners;

use App\Mail\ReferralLinkMail;
use App\Referrals\ReferralTables;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Taldres\Waitlist\Events\EntryConfirmed;

class IssueReferralCode implements ShouldQueue, ShouldBeEncrypted
{
    public function handle(EntryConfirmed $event): void
    {
        $entry = $event->entry;

        if (! $entry->hasConsentFor('referrals')) {
            return;
        }

        $code = ReferralTables::codes()->where('entry_id', $entry->id)->value('code');

        if ($code === null) {
            // A clash with another code throws on the unique index; the queue
            // retries with a new one.
            $code = Str::lower(Str::random(10));

            ReferralTables::codes()->insert(['entry_id' => $entry->id, 'code' => $code]);
        }

        Mail::to($entry->email)->send(new ReferralLinkMail(url('/').'?ref='.$code, $event->unsubscribeUrl));
    }
}
```

The mail is yours to write, and it is mail like any other: see
[Mail](../mail.md) for the unsubscribe link and headers it needs, and
[Queued listeners](../mail.md#queued-listeners) for why the listener implements
`ShouldBeEncrypted`. Someone who agrees to `referrals` later gets a code from a
listener of `ConsentGranted` with the same body, after a check of
`$event->consent->purpose`.

## 4. Credit the referrer

```php
// app/Listeners/CreditReferrer.php
namespace App\Listeners;

use App\Referrals\ReferralTables;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;
use Taldres\Waitlist\Events\EntryConfirmed;

class CreditReferrer implements ShouldQueue, ShouldBeEncrypted
{
    // Credits beyond this count for nothing. Confirmations that run at the same
    // moment can overshoot it by a few.
    private const MAX_CREDITS = 10;

    public function handle(EntryConfirmed $event): void
    {
        $entry = $event->entry;
        $code = $entry->metadata['ref'] ?? null;

        if (! is_string($code) || $code === '') {
            return;
        }

        $referrerId = ReferralTables::codes()->where('code', Str::lower($code))->value('entry_id');

        // Unknown code, or the person's own: nothing happens, and nothing tells.
        if ($referrerId === null || $referrerId === $entry->id) {
            return;
        }

        // The referrer must still be in line, on the same list and project,
        // and still agree to referrals.
        $eligible = $entry->newQuery()
            ->whereKey($referrerId)
            ->onList($entry->list, $entry->project)
            ->whereConsentedTo('referrals')
            ->exists();

        if (! $eligible) {
            return;
        }

        if (ReferralTables::credits()->where('referrer_entry_id', $referrerId)->count() >= self::MAX_CREDITS) {
            return;
        }

        ReferralTables::credits()->insertOrIgnore([
            'referrer_entry_id' => $referrerId,
            'referee_entry_id' => $entry->id,
        ]);
    }
}
```

`EntryConfirmed` fires once per confirmation, after the commit. The listener is
queued and encrypted, as the event carries tokens: run synchronously, a database
error would turn the visitor's confirmation into a `500` and lose the credit,
while the queue retries it.

Two gaps remain. The cap is read and then written without a lock, so it is a
soft limit. And a cleanup of step 5 that commits between the eligibility check
and the insert leaves a credit row that names a person who is gone. Run a
reconcile now and then, for example from the scheduler, that deletes such rows
(a query against the entries' ids, in chunks):

```php
ReferralTables::credits()->orderBy('id')->chunkById(500, function ($credits) {
    $alive = config('waitlist.model')::query()
        ->whereIn('id', $credits->pluck('referrer_entry_id')->merge($credits->pluck('referee_entry_id'))->unique())
        ->pluck('id')->all();

    foreach ($credits as $credit) {
        if (! in_array($credit->referrer_entry_id, $alive, true) || ! in_array($credit->referee_entry_id, $alive, true)) {
            ReferralTables::credits()->where('id', $credit->id)->delete();
        }
    }
});
```

Do the same for `referral_codes` by `entry_id`.

The same address on the same list is the same entry, spelled however it is
written (see [the email normalizer](../encryption-and-keys.md#the-email-normalizer)),
so the check against `$entry->id` stops the plain self-referral. It does not
stop a second address.

## 5. Forget what the package forgets

```php
// app/Listeners/CleanUpReferrals.php
namespace App\Listeners;

use App\Referrals\ReferralTables;
use Taldres\Waitlist\Events\ConsentWithdrawn;
use Taldres\Waitlist\Events\EntryForgotten;
use Taldres\Waitlist\Events\EntryUnsubscribed;

class CleanUpReferrals
{
    // Erased, pruned or expired: the entry is gone. Only the id is left.
    public function handleForgotten(EntryForgotten $event): void
    {
        $this->remove($event->entryId);
    }

    // Left the list: the purpose of the link ends with it.
    public function handleUnsubscribed(EntryUnsubscribed $event): void
    {
        $this->remove($event->entry->id);
    }

    public function handleWithdrawn(ConsentWithdrawn $event): void
    {
        if ($event->consent->purpose === 'referrals') {
            ReferralTables::codes()->where('entry_id', $event->entry->id)->delete();
            ReferralTables::credits()->where('referrer_entry_id', $event->entry->id)->delete();
        }
    }

    private function remove(string $entryId): void
    {
        ReferralTables::codes()->where('entry_id', $entryId)->delete();
        ReferralTables::credits()
            ->where('referrer_entry_id', $entryId)
            ->orWhere('referee_entry_id', $entryId)
            ->delete();
    }
}
```

Laravel discovers the three `handle*` methods by their event types, see
[Events](../reference/events.md). `EntryForgotten` also fires when a retention
period, an expired confirmation or `forgetAll()` removes the entry, so one
listener covers every way out. Erasing a person also removes the credits they
gave to others: a referrer's count can go down, because the link no longer
exists. That is the consequence of erasure, not a defect.

## 6. The macro

```php
// app/Providers/AppServiceProvider.php
use App\Referrals\ReferralTables;
use Illuminate\Database\Eloquent\Builder;
use Taldres\Waitlist\ScopedWaitlist;

public function boot(): void
{
    // The people to invite next, most credits first, then first come, first served.
    ScopedWaitlist::macro('inLine', function (): Builder {
        /** @var ScopedWaitlist $this */
        return $this->entries()
            ->whereConsentedTo('waitlist')
            ->select('waitlist_entries.*')
            ->selectSub(
                ReferralTables::credits()
                    ->selectRaw('count(*)')
                    ->whereColumn('referral_credits.referrer_entry_id', 'waitlist_entries.id'),
                'credits',
            )
            ->orderByDesc('credits')
            ->oldest();
    });
}
```

```php
Waitlist::for('default')->inLine()->limit(25)->get();
```

With [the invite flow](invite-flow.md), start `inviteNext()` from `$this->inLine()`
instead of `$this->entries()->whereConsentedTo('waitlist')`, and leave out
its `oldest()`. The subquery needs the tables on the connection of the waitlist
tables, as in step 1.

## Privacy

Who invited whom is personal data about two people, and it is a new purpose.
None of this is legal advice; see
[Operator responsibilities and limitations](../responsibility.md).

### Purpose and lawful basis

Name the purpose in your privacy notice and your record of processing: ordering
invitations by referrals, and the data it needs: the code, who used it, the
count. Decide on which basis you process it. The example asks the referrer for
consent as an optional purpose, since it is theirs to give or not, and nobody
should lose their place for declining. The referred person gives no consent for
the credit; they only type a code. Assess that side with your data protection
officer, and keep what you store about them minimal: the credit holds two ids and
a date, no address.

`php artisan waitlist:privacy` lists the listeners registered for package events;
the three above show up there. Add the tables to your record of processing
yourself, it only describes the package's storage.

### What people are told

- **On the form, next to the field**, in the language of the form: a code from a
  friend credits the friend with a place in the line; nothing about you is shared
  with them.
- **In the wording of `referrals`**: what the link does, that the referrer is shown
  a count and nothing else, and how to withdraw. It is a separate unticked checkbox,
  never bundled with `waitlist`.
- **In the notice**: how the order is decided, so nobody finds out by being
  passed.
- **In the mail with the link**: what it is for and the unsubscribe link of its
  purpose.

### Erasure, withdrawal and leaving

- **Erasure**: `EntryForgotten` removes the code, the credits the person gave and
  the credits they earned (step 5). The code a referred person typed sits in
  their own metadata, which the package erases with the entry; a code left in
  someone else's metadata, after the referrer was erased, points to no one.
- **Withdrawing `referrals`** deletes the code and the earned credits: the link
  stops working and the purpose has ended.
- **Leaving the list** removes both sides, so people who left are not kept in
  anyone's count.
- **Backups, queues, exports and logs** need the handling the
  [GDPR guide](../gdpr.md#retention) asks for anywhere else.

### Access requests and exports

`Waitlist::allProjects()->personalData($email)` and the preference page's
download return the package's data: for a referred person that includes the code
they typed, as `metadata.ref`. They do not include your tables. Add them to the
answer you give:

```php
// app/Providers/AppServiceProvider.php
ScopedWaitlist::macro('referralData', function (string $email): ?array {
    /** @var ScopedWaitlist $this */
    $entry = $this->find($email);

    if ($entry === null) {
        return null;
    }

    return [
        'code' => ReferralTables::codes()->where('entry_id', $entry->id)->value('code'),
        'credits_earned' => ReferralTables::credits()->where('referrer_entry_id', $entry->id)->count(),
        'credited_a_referrer' => ReferralTables::credits()->where('referee_entry_id', $entry->id)->exists(),
    ];
});
```

It tells a referred person that their signup was credited, not to whom: the
referrer is another person. The preference page's download cannot include these
tables, so the person is not served completely there. Either add an endpoint of
your own, or turn the page off for the project (`manageLinks(false)`) and answer
every request yourself, as [GDPR in practice](../gdpr.md#rights-of-the-people-on-your-list)
describes, and say how to reach you in your notice.

### Retention

Keep the code and the credits only as long as invitations are being decided.
Delete the rows of both tables once the invitations are sent, or when you erase the
list (`forgetAll()` fires `EntryForgotten` for each entry, which cleans up).
The retention periods in `waitlist.retention` reach only the package's tables;
rows of yours for someone who stays confirmed forever stay unless you remove
them. Put that date in the notice.

## Abuse

Referral programmes attract people who create addresses to move themselves up.
This example makes the cheap ones less rewarding; none of it stops a determined
person with many mailboxes.

- **Credit on confirmation only.** The address must answer a confirmation mail,
  and at most one credit exists per referred entry at a time. A referred person who
  leaves loses their credit, and one who returns and confirms again books a new one.
- **Self-referral.** The same address is the same entry and credits nobody. A
  throwaway address, a plus-tag or another provider's alias is a different
  address. An `EmailNormalizer` that folds plus-tags shuts the cheapest
  variant, at the price of treating real people with such addresses as one; the
  choice is part of every hash, see
  [Encryption and keys](../encryption-and-keys.md#the-email-normalizer).
- **A cap.** `MAX_CREDITS` limits what a farm of addresses can buy; it is soft,
  see step 4. Choose it so
  that the ordering still means something.
- **A delay.** Book the credit when it happens, but count only credits older
  than a week: add `->where('created_at', '<=', now()->subWeek())` to the
  subquery of the macro. Step 5 already deletes the credit of a referred
  person who leaves in the meantime.
- **Rate limits and bots.** The signup route has the package's limits and the
  [`SpamProtector`](../securing-the-endpoints.md#bot-protection-via-spamprotector);
  a referral programme is a reason to switch the protector on.
- **Silence.** The signup answers the same for any code, and no page tells a
  visitor whether a code exists or whose it is. The listener does not either.
- **A human look before the invitations.** Check referrers near the cap, for example
  many credits within an hour, before you send. Decide by what you see in your
  tables, not by exposing the referred people's addresses to anyone.
