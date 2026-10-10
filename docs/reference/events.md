# Events

The package acts through events: it sends no mail and calls no provider, your
listeners do. Which transition fires which event, and what it logs, is in the
[lifecycle](../lifecycle.md#events-per-transition).

| Event | Fired when | Payload |
| --- | --- | --- |
| `EntrySubscribed` | A cycle starts, or a confirmation request goes out later (a resend, or the held-back first one of a deferred cycle); a deferred start fires nothing | `entry`, `subscription`, `confirmToken`, `unsubscribeToken`, `confirmUrl`, `unsubscribeUrl`, `requiresConfirmation`, `isNewCycle` |
| `EntryConfirmed` | Double opt-in completed, or a cycle started on a list without it | `entry`, `subscription`, `unsubscribeToken`, `unsubscribeUrl` |
| `ConsentGranted` | An optional purpose was added to a running cycle | `entry`, `subscription`, `consent` |
| `ConsentWithdrawn` | An optional purpose was withdrawn; the cycle continues | `entry`, `subscription`, `consent` |
| `EntryUnsubscribed` | Someone left a list | `entry`, `subscription` |
| `SubscriptionExpired` | A confirmation was abandoned, just before the entry is erased | `entry`, `subscription` |
| `EntryForgotten` | An entry was erased | `entryId`, `project`, `list`, `email` (scalars; the entry is gone, and `email` is null when it cannot be decrypted) |
| `ManageLinkRequested` | Someone asked for the preference page; mail the link to the address | `entry`, `manageToken`, `manageUrl`, `expiresAt` |
| `WordingRegistered` | A project's server sent a version, or a locale of one, the project did not have yet ([Wording sent by your servers](../purposes-and-wording.md#wording-sent-by-your-servers)) | `wording` (`WaitlistWording`: `project`, `purpose`, `version`, `locale`, `text`, `registered_by`) |

All classes live in `Taldres\Waitlist\Events`. The project of an event is
`$event->entry->project`, `$event->project` on `EntryForgotten`, or
`$event->wording->project` on `WordingRegistered`.

- **After commit.** Every event implements `ShouldDispatchAfterCommit`, so a
  listener never sees a state that a surrounding transaction later rolls back.
- **Exactly once.** Each state change dispatches once, even when two requests
  race; see [Transitions](../lifecycle.md#transitions).
- **Encrypt queued listeners.** Make them implement `ShouldBeEncrypted`: their
  payload holds tokens and, for `EntryForgotten`, the address in plain text.
- **Event discovery.** Laravel registers every public `handle*` method of a class
  in `app/Listeners` by itself. Register by hand only listeners that live
  elsewhere or name their methods differently; registering a discovered one by
  hand as well runs it twice.

What to do with them:

| Listener | Events | Guide |
| --- | --- | --- |
| Confirmation mail | `EntrySubscribed` | [Mail](../mail.md#the-confirmation-mail) |
| Preference page link | `ManageLinkRequested` | [Mail](../mail.md#the-preference-page-link) |
| Welcome mail | `EntryConfirmed` | [Mail](../mail.md#a-welcome-mail) |
| Newsletter tool sync | `EntryConfirmed`, `ConsentGranted`, `ConsentWithdrawn`, `EntryUnsubscribed`, `EntryForgotten` | [Sync to your email provider](../email-provider-sync.md) |
| Copies you keep elsewhere | `EntryForgotten`: delete the copy for that entry; a copy shared across lists only once no entry of the address is left | [GDPR in practice](../gdpr.md#your-listeners) |

`php artisan waitlist:privacy` lists the listeners registered for package events,
as input for your record of processing.
