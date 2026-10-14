# Subscription lifecycle

An address on a list is an **entry**. What happens to it is a series of
**cycles**: a cycle starts with an opt-in, is confirmed, and ends when the person
leaves (`unsubscribed`) or the confirmation expires (`expired`). Consents belong
to one cycle and nothing else. At most one cycle per entry is open at a time,
which the database enforces with a unique index, not the application.

## What a signup does

| Situation | What happens | Outcome |
| --- | --- | --- |
| unknown address | a first cycle starts, consents recorded | `started` |
| waiting for confirmation | another confirmation request, no new consent | `confirmation_resent` |
| waiting, inside the cooldown | nothing at all | `resend_suppressed` |
| already confirmed | nothing at all | `already_confirmed` |
| left earlier | a new cycle starts, new consents recorded | `resubscribed` |
| starting a cycle while waiting on too many other lists of the project | the cycle starts, its confirmation request is held back | `confirmation_deferred` |

`$result->outcome` is that `SubscribeOutcome`. Over HTTP every case answers the
same `202`, so the form reveals nobody.

- **Metadata is only taken when a cycle starts.** A repeat comes from anyone who
  knows the address and never changes what is stored.
- **A signup on a running cycle never adds purposes.** That needs proof of the
  mailbox, which the preference page provides.
- **Nobody can be mailed repeatedly through a public form.** A cooldown per
  cycle (`resend_cooldown`, 5 minutes) and a cap per cycle
  (`max_confirmations`, 5) hold, also for requests that arrive together. Once
  the last link has expired, one more confirmation may go out, so nobody can
  lock an address out of confirming.
- **The cap per address is checked request by request.** A cap on unconfirmed
  lists of a project per address and day (`max_pending_per_address`, 5) stops
  made-up list names on a `'*'` list. Each signup or resend counts what was
  committed before it (inside your own transaction, as of its snapshot), so
  requests for different lists that arrive at the same moment can each pass
  the check, and the cap can be exceeded by as many as arrive together. The
  rate limits bound how many one source can send at once, not how many reach
  one address. An application that needs the cap strict serializes its own
  calls per address until the package's step has committed, or its own
  transaction around it.

## States

A cycle has no status column. Its state is which timestamps are set:

| `confirmed_at` | `ended_at` | Meaning |
| --- | --- | --- |
| null | null | waiting for confirmation |
| set | null | on the list |
| any | set | gone; `end_reason` says whether they left or the confirmation expired |

The entry carries a `status` projection of its latest cycle, so ordinary queries
and exports never need a join.

## Transitions

Every transition is a conditional update that restates the expected state:

```sql
UPDATE waitlist_subscriptions SET confirmed_at = ?
WHERE id = ? AND confirmed_at IS NULL AND ended_at IS NULL
```

Only an update that affected exactly one row records activity, moves the
projection and dispatches an event. So:

- **Events fire exactly once per state change.** Two requests arriving together
  produce one event, whichever wins.
- **A confirm cannot overwrite an unsubscribe** that landed a moment earlier, and
  an expiry loses to a confirmation that landed first.
- **Events wait for the waitlist's own commit.** They are dispatched inside the
  package's transaction on `waitlist.connection`, so a transaction your
  application has open on another connection does not discard them. Inside
  your own transaction on the same connection, they wait for yours. What your
  listeners defer themselves (`ShouldHandleEventsAfterCommit`,
  `ShouldQueueAfterCommit`, a queue with `after_commit`) waits for whatever
  transaction your application has open, as Laravel decides.
- **Only the newest confirm link confirms.** The confirm condition also names the
  link it was looked up with and its expiry, so a link that a resend replaced or
  that expired after the lookup changes nothing.

Every step locks the entry row before it touches a cycle, and a withdrawal or a
preference change locks all entries of the address in the project in key order,
so steps on one address wait for each other rather than deadlock, an erasure
included. Where the package opens the outermost transaction of a signup, a
transition, a withdrawal or an erasure, it tries up to three times, with fresh
data, when the database reports a deadlock or (MySQL, MariaDB) a lock wait
timeout. Inside your own transaction it cannot: Laravel then throws a
`DeadlockException` to you, and the retry is yours (`DB::transaction($callback,
attempts: 3)`, with models loaded inside it). MariaDB's snapshot isolation
reports a row another request changed after your transaction's first read the
same way. A signup or confirmation that loses a race inside your transaction
reads the winner's rows with a locking read, which holds those rows, and on
MySQL and MariaDB the index gap of an address not found, until you commit: keep
such transactions short. On SQLite with several workers, give the connection a
`busy_timeout` and WAL, and on PHP 8.4 `'transaction_mode' => 'IMMEDIATE'`, or a
write that waits fails with "database is locked".

These guarantees assume that `waitlist.connection` reads what was just
committed. Read replicas are your infrastructure, and the package does not
route around them: a replica that lags can let a signup miss the one before it,
so the cap per address or the recovery of a lost race may fail by that lag.
Point `waitlist.connection` at the primary; `'sticky' => true` covers only
what the same request wrote, not lag between requests. Reads inside a
transaction and locking reads always go to the primary.

The resend condition matches the expected `confirmation_count` rather than a
timestamp. Two resends inside the same second would both pass a timestamp
comparison; they cannot both pass a counter.

## Tokens

| | Confirm token | Unsubscribe token | Manage token |
| --- | --- | --- | --- |
| Belongs to | one cycle | the entry | the entry |
| Can | confirm | unsubscribe, withdraw a purpose, ask for a manage link, read the place in line where the list [publishes it](reference/http-api.md#count-and-position) | read the data, set the purposes, leave, erase |
| Issued | when a cycle starts, rotated on every resend | once, on the first signup | on request, mailed to the address |
| Plain form | `EntrySubscribed` | every mail: event payloads, `unsubscribeToken()`, `unsubscribeUrl()` | `ManageLinkRequested`, `manageLink()` |
| Stored as | SHA-256 hash | SHA-256 hash plus an encrypted copy | SHA-256 hash only |
| Ends | when the cycle ends, or after `double_opt_in.token_ttl` (seven days) | when the entry is erased | after `manage.token_ttl` minutes (60), or when a new one is issued |

Three consequences worth knowing:

- **An unsubscribe link from the first mail still works years later.** Nothing
  rotates it, not a resend, not a re-subscribe; only a token that cannot be
  decrypted is replaced, see [Encryption and keys](encryption-and-keys.md#rotating-app_key). A broken unsubscribe link is
  worse than a long-lived one, and the link never changes anything but removing
  someone. It can ask
  for a manage link, but that goes to the mailbox, never to whoever holds the
  link. On a list that publishes positions, it also reads the person's place in
  line, which names nobody else.
- **The preference page needs fresh proof of the mailbox.** A manage token is
  never part of an ordinary mail, so a forwarded newsletter or a mail provider
  reading the `List-Unsubscribe` header never gets access to the data.
- **A confirm link cannot undo an unsubscribe.** When the cycle ended, its token
  is cleared and the link reports as invalid rather than bringing the address
  back.

Confirming is idempotent: a second click reports success rather than a
confusing error, and the link degrades to a status link that can never change
state again. An expired confirm link answers `410`; a resend issues a fresh one.

## Consent in force

A purpose is in force while its cycle is open and confirmed and it was not
withdrawn. That is what `recipients()`, `$entry->hasConsentFor()` and
`whereConsentedTo()` check, so ask them before you send.

Within an open cycle, a manage token can add an optional purpose, and the
unsubscribe token or a manage token can withdraw one. Both first lock the entry
and then the cycle row, which ending the cycle updates too, so a grant can
never land after an unsubscribe it crossed.

## What a withdrawal reaches

One rule: **lists that share a primary purpose are separate waitlists; an
optional purpose is one consent for the whole project.**

- Withdrawing a list's primary purpose leaves that list, and withdraws the purpose
  wherever else it is only an add-on.
- Withdrawing an optional purpose withdraws it on every list of the project, and
  ends the lists that exist only for it, a list just for the newsletter for
  example.
- A plain unsubscribe is the same as withdrawing the list's primary purpose.
- Nothing ever reaches another project.

So someone who leaves the newsletter, from any list, stops receiving it through
all of them, while leaving one waitlist never leaves another. Which case applies
is read from the list's stored consents, open cycle or not, so a purpose
removed from the catalog can still be withdrawn, and repeating a withdrawal
changes nothing. Lists that need independent consents use different purpose
keys. [Getting started](getting-started.md#7-withdraw-a-purpose) shows the rule
on an example.

Every mail can carry a link that withdraws exactly the purpose it was sent for:

```php
Waitlist::unsubscribeUrl($entry, 'newsletter');          // .../unsubscribe/{token}?purpose=newsletter
Waitlist::listUnsubscribeHeaders($entry, 'newsletter');  // RFC 8058 one-click, per purpose
```

## Events per transition

| Transition | Activity logged | Event |
| --- | --- | --- |
| cycle starts, first time | `subscribed`, `confirmation_requested` | `EntrySubscribed` (`isNewCycle: true`) |
| cycle starts after leaving | `resubscribed`, `confirmation_requested` | `EntrySubscribed` (`isNewCycle: true`) |
| another confirmation request | `confirmation_requested` | `EntrySubscribed` (`isNewCycle: false`) |
| the held-back first request of a deferred cycle | `confirmation_requested` | `EntrySubscribed` (`isNewCycle: true`) |
| your listener reports the mail, `Waitlist::confirmationMailed()` | `confirmation_mailed` (with reference), once per request | — |
| your listener reports that it could not send the mail, `Waitlist::confirmationFailed()` | `confirmation_failed` (with reference), once per request; cooldown and count taken back | — |
| confirmed | `confirmed` | `EntryConfirmed` |
| optional purpose granted | `consent_granted` (with purpose) | `ConsentGranted` |
| optional purpose withdrawn | `consent_withdrawn` (with purpose) | `ConsentWithdrawn` |
| unsubscribed, or primary purpose withdrawn | `unsubscribed` | `EntryUnsubscribed` |
| confirmation abandoned (retention) | `expired` | `SubscriptionExpired` |
| erased | `erased` | `EntryForgotten` |

With double opt-in disabled a cycle starts confirmed: `subscribed` and
`confirmed` are logged together, no confirm token is issued, and both
`EntrySubscribed` and `EntryConfirmed` fire.

When the address already got `max_pending_per_address` confirmation requests
for unconfirmed lists of the project within the last day, a new cycle starts
without one: only `subscribed` or `resubscribed` is logged and nothing fires. A
later signup or resend sends it once the day has passed or one of the other
lists is settled.

The payload of each event is in the [Events reference](reference/events.md).
