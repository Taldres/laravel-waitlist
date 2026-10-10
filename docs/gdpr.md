# GDPR in practice

The package provides technical storage, consent records, access, withdrawal and
erasure APIs. It does not provide legal advice, certification or a warranty that
your processing complies with the GDPR. The application operator is responsible
for its processing and deployment. Read [Operator responsibilities and
limitations](responsibility.md) before using these features; the software is
provided under the [MIT License](../LICENSE.md), subject to mandatory law.

## What the package supports

These features can support your implementation; they do not establish that a
legal requirement is satisfied.

| GDPR topic | Supporting technical feature |
| --- | --- |
| Purpose limitation, no bundling (Art. 5(1)(b), 7(4)) | One primary purpose per list, optional purposes recorded and withdrawn separately |
| Proof of consent (Art. 7(1)) | Registered wording and version stored per purpose and cycle, plus lifecycle records; these do not verify what was displayed or whether consent is legally valid |
| Withdrawal as easy as consent (Art. 7(3)) | Per-purpose links and RFC 8058 one-click support; the application must deliver working links and implement an accessible withdrawal flow |
| Data minimization (Art. 5(1)(c)) | The HTTP signup accepts only the fields a project or list defines; optional activity-log IP and user agent off by default; server-side calls and infrastructure need their own controls |
| Storage limitation (Art. 5(1)(e)) | Retention periods applied by a scheduled `waitlist:prune` |
| Self-service rights (Art. 12(2), 15, 17, 20) | Preference page behind a manage link mailed to the address: change purposes, download a JSON copy, erase; a project can turn it off and answer these requests itself |
| Access and erasure on request (Art. 15, 17) | `waitlist:show`, `waitlist:forget`, `Waitlist::allProjects()->personalData()`, `Waitlist::allProjects()->forget()` |
| Privacy by default (Art. 25) | Encryption always on, double opt-in on, request metadata off, routes off until enabled |
| Record of processing (Art. 30) | `waitlist:privacy` describes configured package storage and registered listeners; the operator must complete the processing record |
| Security of processing (Art. 32) | Address, metadata, IP and user agent always encrypted at rest; lookups on a keyed hash, never on the address |

## What is stored where

| Data | Table | Protection |
| --- | --- | --- |
| Email address | `waitlist_entries.email` | Encrypted with `APP_KEY` |
| Lookup hash of the address | `waitlist_entries.email_hash` | HMAC-SHA256 with a subkey of `APP_KEY` |
| Metadata (the fields a project or list defines) | `waitlist_entries.metadata` | Encrypted with `APP_KEY` |
| Consent per purpose: wording, version, granted and withdrawn at | `waitlist_consents` | Frozen once written |
| Cycle: started, confirmed, ended, end reason | `waitlist_subscriptions` | Forward-only state machine |
| Log: step, purpose, date, the reference your listener reports, optionally IP and user agent | `waitlist_activity` | Reference, IP and user agent encrypted; stripped to project, list, step, purpose, the status a departure left and date on erasure |
| Tokens | hashed, the unsubscribe token also encrypted | Never exported |

`php artisan waitlist:privacy` prints this for your actual configuration, with the
purposes, the fields of each list, retention periods and the listeners data flows to.

## The key

`APP_KEY` protects everything: it encrypts addresses, metadata, IP and user
agent, and a subkey derived from it makes the lookup hash. Back it up apart from
the database. Rotate it with `APP_PREVIOUS_KEYS` and `php artisan waitlist:rekey`;
without the old key, addresses can neither be read nor found by email, though
retention periods and `forgetAll()` still erase them. The `EmailNormalizer` is
part of the hash too: choose it before the first signup and keep it.

A keyed hash is pseudonymous, not anonymous. Its job is that a leaked dump or
backup without the key holds no readable address. With `Waitlist::encryptUsing()`
the same applies to your own encrypter's keys, and so does the responsibility for
them. See [Encryption and keys](encryption-and-keys.md).

## Proof of the double opt-in

To document a double opt-in flow, retain the consent wording and a reference to
the confirmation mail. These records do not by themselves establish valid consent
or guarantee that a legal burden of proof is met. The package records each
step it takes part in: a snapshot of the wording per purpose, the confirmation
request and the confirmation itself. The mail is yours, so record it from your
listener once it went out:

```php
Waitlist::confirmationMailed($event->subscription, 'confirm-mail@2026-10');
```

The reference names what you sent, typically your template and its version;
keep each version's text, as you do with the consent wording. A provider's
message id works too and is cleared on erasure. An IP address alone proves
little and stays off unless you need it (`privacy.store_ip`).

## Purposes and wording

Every list has one primary purpose, which is required, and optional purposes
that are agreed to and withdrawn on their own. This supports separate choices;
your application must actually offer those choices and assess whether consent is
freely given, specific, informed and unambiguous.

- The wording is yours. It comes from the `ProjectCatalog`: the project's
  definition (`Waitlist::define()`), or, with `StoredWordingCatalog`, what your
  frontend or CMS registers on deploy (`waitlist:wording`), or, with
  `wordingFromCallers()`, what the project's own servers send with a signup, so
  it is written in one place only.
- The form posts back the versions shown. The package stores a snapshot of the
  registered wording for that version; a visitor can never write consent wording.
  Wording a server sends is registered with that server, and a version never
  changes once registered.
- A version can hold a text per locale. The form posts back the locale it showed,
  and the consent stores it, so you can tell in which language someone agreed.
- Never edit a version's wording. Add a new version: the last one is current, and
  older ones stay accepted, so a form loaded before the change still records what
  it showed. Leave a version out of the catalog to stop accepting it; stored
  consents keep their snapshot.
- The snapshot proves which wording was registered for the version, not that the
  form displayed it. Render the form from the same source, for example from
  `GET /waitlist/purposes`. A form with its own copy of the text posts back its
  hash, and a mismatch is refused; `WAITLIST_REQUIRE_WORDING_HASH=true` makes the
  hash mandatory for every consent that is newly recorded, never for keeping or
  withdrawing one.
- Retiring every version of an optional purpose stops offering it; its list keeps
  working.
- Removing a purpose from the catalog does not strand anyone: withdrawal works from
  the stored record.
- Before mailing for a purpose, check it: send to `Waitlist::recipients('newsletter')`,
  or check `$entry->hasConsentFor('newsletter')`.

How to configure all of this: [Purposes and wording](purposes-and-wording.md).

## Retention

`waitlist:prune` applies three periods from `waitlist.retention`, and the package
schedules it (`15 3 * * *` by default). Laravel's scheduler must run. These are
technical defaults, not statutory periods or a recommendation for your use case.
The operator must choose and justify them and monitor cleanup failures.

- **Unconfirmed signups** (30 days): counted from the start of the cycle, so
  resends never extend it. The cycle is closed as expired first, so the abandoned
  signup still shows in reporting.
- **People who left** (1095 days): counted from when they left. Keeping them for a
  while retains consent records in case a mail is disputed. Agree on the
  period with your data protection officer; set `null` to keep them until erased.
- **IP and user agent** (30 days): cleared from the log. Both are off by default.

When the purpose of a list is fulfilled, for example after the launch, erase it:
`Waitlist::for('beta')->forgetAll()` or `php artisan waitlist:forget --list=beta --all`.

Confirmed entries that remain subscribed are not expired by these rules. The
remaining reporting log is not automatically deleted and is not guaranteed to be
anonymous. The operator must define its lawful handling and retention, as well as
cleanup for backups, queues, logs, exports and external services. See
[Reporting](reporting.md#what-an-erasure-leaves-behind).

## Projects

Projects organize the waitlists of several products of one controller. A
request for access or erasure therefore covers all of them:
`Waitlist::allProjects()->personalData($email)` and
`Waitlist::allProjects()->forget($email)`, or the commands without `--project`.
Without `project()`, the facade only ever touches the default project. Projects
do not provide tenant isolation. If you process data for other companies, assess
the actual controller and processor roles and implement the necessary contracts
and isolation; project keys do not determine those roles. See
[Projects, lists and fields](projects.md#privacy-across-projects).

## Rights of the people on your list

| Right | Self-service (preference page) | On request |
| --- | --- | --- |
| Access, portability (Art. 15, 20) | `POST /waitlist/manage/{token}/data` | `waitlist:show`, `Waitlist::allProjects()->personalData()` |
| Withdrawal (Art. 7(3)) | `PUT /waitlist/manage/{token}/purposes`, per-purpose unsubscribe links | `Waitlist::for($list)->withdraw()` |
| Erasure (Art. 17) | `POST /waitlist/manage/{token}/erase` | `waitlist:forget`, `Waitlist::allProjects()->forget()` |

The preference page belongs to one list: its copy of the data and its erasure
cover that list's entry. Someone on several lists uses each list's link; a
request that reaches you another way is answered across all of them with
`Waitlist::allProjects()->personalData($email)` and
`Waitlist::allProjects()->forget($email)`. Withdrawals of an optional purpose are
the exception: they apply to every list of the project.

Every mail carries the unsubscribe link; it can only remove, so forwarding a mail
or a provider reading its headers exposes nothing. The preference page opens with
a manage link that is mailed to the address on request (`ManageLinkRequested`)
and expires after an hour. It only reaches the person through their mailbox,
which is what lets it stand in for a login. Offer the request on your unsubscribe
page: `POST /waitlist/manage-link` with the token from the link. A project
without a preference page turns manage links off (`manageLinks(false)`); access
and erasure then go through you, with the commands above, and your privacy
notice says how to reach you.

## Your listeners

The package makes no outbound requests. Your application can disclose data through
listeners, API responses, exports and other integrations. Review every destination
and its actual role, and arrange processor agreements where required.

- Queued listeners: implement `ShouldBeEncrypted`. The payload holds tokens and,
  for `EntryForgotten`, the address in plain text, and it sits in your queue
  storage until processed.
- On `EntryForgotten`, delete what you copied for that entry. It also fires when
  one list is erased or pruned while the address is on another, so delete a copy
  shared across lists only once no entry is left, see
  [Sync to your email provider](email-provider-sync.md).
- On `ConsentWithdrawn`, stop that purpose at the provider too, and on
  `EntryUnsubscribed`, everything for that list.

## What stays your job

`php artisan waitlist:privacy` gives you the facts: data, purposes, retention,
technical measures and registered listeners, generated from your configuration.
It does not discover every recipient or inspect your infrastructure. Review and
complete it; producing accurate legal documents is your responsibility:

- your record of processing (Art. 30) and privacy notice (Art. 13)
- agreements with your mail provider and anyone else your listeners send data to
- the wording of your purposes and of the confirmation mail

## Before you go live

Setup

- [ ] `APP_KEY` backed up apart from the database; on rotation, the old key kept in `APP_PREVIOUS_KEYS`
- [ ] One primary purpose per list; newsletters and the like as optional purposes
- [ ] Double opt-in on
- [ ] IP and user agent stored only if you need them
- [ ] [Fields](projects.md#fields) per project and list as few as possible, none if in doubt
- [ ] Retention periods agreed with your data protection officer
- [ ] Laravel's scheduler running, so `waitlist:prune` applies
- [ ] `php artisan waitlist:privacy` run on the production host prints no warnings: mail links are https and not localhost, `APP_DEBUG` is off, and guests are meant to call the signup

Signup form

- [ ] Wording rendered from `GET /waitlist/purposes`, versions posted back
- [ ] Optional purposes as separate, unticked checkboxes
- [ ] Bot protection on the endpoint (see [Securing the endpoints](securing-the-endpoints.md))

Mails

- [ ] The confirmation mail shows what is being confirmed: the purposes from
      `$event->subscription->consents`
- [ ] The listener records it with `Waitlist::confirmationMailed()`, and each
      version of the mail's text is kept
- [ ] Every mail carries an unsubscribe link and `List-Unsubscribe` headers for its own purpose
- [ ] Your unsubscribe page offers to mail a link to the preference page
- [ ] A listener mails `ManageLinkRequested` links to the address, and nowhere else
- [ ] Recipients selected with `recipients($purpose)`, or `whereConsentedTo($purpose)` in queries of your own

Listeners

- [ ] Queued listeners implement `ShouldBeEncrypted`
- [ ] `EntryForgotten`, `EntryUnsubscribed` and `ConsentWithdrawn` propagate to every provider you sync to
- [ ] Each listener's destination documented as a recipient

After the launch

- [ ] The list erased once its purpose is fulfilled: `php artisan waitlist:forget --list=… --all`
