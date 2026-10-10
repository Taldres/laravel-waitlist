---
name: laravel-waitlist-privacy-operations
description: >
  Operate a taldres/laravel-waitlist installation: answer access and erasure
  requests, withdraw a purpose on request, apply retention, erase a list once its
  purpose is fulfilled, rotate APP_KEY with waitlist:rekey, export a list, and
  check the setup before going live.
license: MIT
metadata:
  author: Dennis Petersmann
---

# Laravel Waitlist: privacy operations

Use this skill for day-to-day data protection tasks around `taldres/laravel-waitlist`:
requests from people on the list, retention, key rotation, exports and the go-live
check.

## Primary Goal

- carry out each request with the package's own APIs and commands, across every
  project where the request concerns the person, and say what stays outside the package

## Workflow

### Access request

```bash
php artisan waitlist:show jane@example.com --pretty   # every project; --json for a machine-readable copy
```

```php
Waitlist::allProjects()->personalData('jane@example.com'); // Collection<PersonalData>
```

### Erasure request

```bash
php artisan waitlist:forget jane@example.com          # every project
```

```php
Waitlist::allProjects()->forget('jane@example.com');  // number of entries erased
```

Erasure deletes the address, its cycles and consents, and clears identifying
fields in its log rows; it fires `EntryForgotten` per entry, which listeners use
to delete copies at providers. Backups, queues, logs, exports and provider copies
need separate handling.

### Withdrawal that arrives by mail

```php
Waitlist::for('beta')->withdraw('jane@example.com', 'newsletter'); // optional purpose: every list of the project
Waitlist::for('beta')->unsubscribe('jane@example.com');            // leave this list
```

### Erase a list once its purpose is fulfilled

```bash
php artisan waitlist:forget --list=beta --all          # asks first; --force without a terminal
```

```php
Waitlist::for('beta')->forgetAll();
```

### Retention

`waitlist:prune` runs on the package's schedule (`waitlist.retention.schedule`,
`15 3 * * *`; `null` leaves scheduling to you, a cron expression that can never run
is refused); Laravel's scheduler must run (`php artisan schedule:run` every
minute). Periods in `waitlist.retention`: `pending_days` (30), `unsubscribed_days`
(1095), `request_metadata_days` (30); `null` keeps data, while a period reaching
back before 1970 (a 36500-day "forever") is refused. `waitlist:prune` applies every
period that reads, then reports the ones that do not and fails the run: watch the
logs and the exit code. These are technical defaults, not legal recommendations.
Active confirmed entries and the remaining reporting rows do not expire
automatically.

### Rotate `APP_KEY`

1. Put the old key into `APP_PREVIOUS_KEYS`, set the new `APP_KEY`, deploy.
2. `php artisan waitlist:rekey`
3. Remove the old key once no queued job or backup needs it.

Without the old key, addresses can neither be read nor found by email, and each
unsubscribe token is replaced the next time it is needed, so links sent earlier
stop working from then on.

### Export

```bash
php artisan waitlist:export beta --status=confirmed --path=storage/app/beta.csv
```

Columns come from `waitlist.export.columns`, limited to `CsvExporter::EXPORTABLE`
(no tokens). The file holds addresses in plain text; delete it when done.

### Record of processing

```bash
php artisan waitlist:privacy [--project=]
```

It describes configured package storage, purposes, the metadata fields of each
list, retention and listeners registered for package events. It is input, not a
complete record: it does not audit infrastructure or find every recipient.

### Go-live check

- `APP_KEY` backed up apart from the database
- double opt-in on; IP and user agent off unless needed
- fields per project and list (`->fields()`) as few as possible, none if in doubt
- scheduler running; retention periods chosen and justified
- config reads: no empty `WAITLIST_*=` line in `.env` (an empty value is refused;
  delete the line for the default), `php artisan config:cache` run again after a
  package update (and `route:cache` after a routes setting changed), no
  `InvalidConfigurationException` in the logs
- signup form renders `Waitlist::purposes()` or `GET /waitlist/purposes`; optional
  purposes unticked
- confirmation listener records `Waitlist::confirmationMailed()`
- every mail has a per-purpose unsubscribe link and one-click headers
- `ManageLinkRequested` mailed only to the address
- queued listeners implement `ShouldBeEncrypted`; `EntryForgotten`,
  `EntryUnsubscribed` and `ConsentWithdrawn` reach every provider
- bot protection and trusted proxies configured when the routes are on

## Rules, References, and Templates

- Commands without `--project` cover every project for `show`, `forget`, `prune`
  and `privacy`; a `--list` without `--project` means that list of the default
  project.
- Withdrawing, leaving, erasing and pruning do not wait for settings they only pass
  by (privacy, guards or client IP header on token links, the email normalizer's
  sweep over the address's other lists, the catalog behind a redirect, link rate
  limits): they fall back and report the mistake once per request, job or
  command, so read the logs after a config change.
- The operator is responsible for lawful processing, identity checks, deadlines
  and complete answers; the package offers no legal advice or compliance
  warranty: https://github.com/Taldres/laravel-waitlist/blob/main/docs/responsibility.md
- Guides: https://github.com/Taldres/laravel-waitlist/blob/main/docs/gdpr.md,
  https://github.com/Taldres/laravel-waitlist/blob/main/docs/encryption-and-keys.md

## Examples

- "A user asked us to delete their data": verify the request, then
  `php artisan waitlist:forget <email>`, and check providers and exports.
- "We launched, the beta list has served its purpose": `waitlist:forget --list=beta --all`.
- "We rotated APP_KEY and lookups fail": restore the old key in
  `APP_PREVIOUS_KEYS`, then `waitlist:rekey`.

## Anti-patterns

- Deleting rows with SQL or `WaitlistEntry::query()->delete()`; erasure must go
  through the package so the log is stripped and `EntryForgotten` fires.
- Searching `where('email', ...)`: addresses are encrypted; use `forEmail()` or the
  facade.
- Erasing with `Waitlist::forget()` (default project only) for a person on several
  projects.
- Rotating `APP_KEY` without `APP_PREVIOUS_KEYS`.
- Presenting `waitlist:privacy` output as a complete Art. 30 record.
