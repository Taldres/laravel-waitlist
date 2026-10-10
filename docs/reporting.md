# Reporting

Two questions, two sources. Mixing them up is how a dashboard ends up lying.

```php
Waitlist::for('beta')->report()->between('2026-08-01', '2026-08-31')->totals();
// "230 confirmed in August" — from the activity log, a fact about the past

Waitlist::for('beta')->snapshot()->active();
// "197 on the list right now" — from the addresses, a fact about the present
```

A later erasure must not change the first number. That is the whole reason
reporting reads a log instead of counting rows in a table someone can leave.

## Daily counts

```php
use Taldres\Waitlist\Enums\ActivityType;

$series = Waitlist::for('beta')->report()->since(30)->daily()->fillGaps();

foreach ($series as $day) {
    $day->date;                          // CarbonImmutable
    $day->signups();                     // subscribed + resubscribed
    $day->of(ActivityType::Confirmed);
    $day->total();
}

$series->totals();          // ActivityTotals over the period
$series->forDate('2026-08-04');
$series->toArray();         // JSON-ready
```

Days with no activity are absent until `fillGaps()` adds them, which is what a
chart needs and what a table usually does not.

```php
$totals = Waitlist::report()->forList('beta', 'launch')->since(7)->totals();

$totals->signups();
$totals->of(ActivityType::Unsubscribed);
$totals->confirmationRate();   // ?float — null when nobody signed up
```

Counts are steps, not people: someone who leaves and comes back is confirmed
twice. `snapshot()` counts addresses.

`forPurpose('newsletter')` narrows a report to the grants and withdrawals of an
optional purpose. Signups, confirmations and departures concern a whole cycle
and are logged without a purpose, so they are not in such a report.

Which lists a report covers depends on where you start it. `Waitlist::report()`
is the default project, `Waitlist::project('acme')->report()` that project, and
`Waitlist::allProjects()->report()` spans every project. Without `forList()` the
report covers every list of its project, or of all projects for
`allProjects()`; with `forList('beta')` it counts `beta` in each of those
projects. Without a period it covers everything stored.

## Confirmed over time

"We had 500, 30 left, 470 are on the list": the log answers that for any day,
not only today. Every departure (`unsubscribed`, `expired`, `erased`) records
the status the entry left, so the number of confirmed entries at the end of a
day is

```
confirmations up to that day
− unsubscribes from confirmed
− erasures from confirmed
```

```php
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\EntryStatus;

$report = Waitlist::for('beta')->report();

$report->confirmedOn('2026-09-30');    // confirmed at the end of that day
$report->confirmedOn(now());           // today; the same as snapshot()->confirmed

$month = $report->between('2026-10-01', '2026-10-31')->totals();
$month->of(ActivityType::Confirmed);                              // joined
$month->of(ActivityType::Unsubscribed, from: EntryStatus::Confirmed);   // left
$month->of(ActivityType::Unsubscribed, from: EntryStatus::Pending);     // never confirmed
$month->of(ActivityType::Erased, from: EntryStatus::Confirmed);         // erased while on the list
$month->of(ActivityType::Erased, from: EntryStatus::Unsubscribed);      // retention clean-up
$month->confirmedChange();                                        // net change
```

Erasing someone who left already, or an expired signup, is clean-up, not a
second departure: it does not lower the number again. An unsubscribe before
confirming never counted as confirmed in the first place.

The figure is exact as far as the log reaches back, and to the day: the log
keeps the date, not the time, of an erased entry's steps. For "right now",
`snapshot()` stays the source.

## How it stays cheap and portable

Every row carries the reporting day it belongs to as a stored date, so the whole
report is one grouped query with no engine-specific date function, no strict
mode trouble, and an index that covers it:

```sql
SELECT occurred_on, type, previous_status, count(*) FROM waitlist_activity
WHERE project = ? AND list IN (?) AND occurred_on BETWEEN ? AND ?
GROUP BY occurred_on, type, previous_status
```

Set `waitlist.reporting.timezone` to decide where a day ends. It defaults to
`app.timezone`. Changing it later does not rewrite rows that are already dated,
so pick it before you start collecting.

## What an erasure leaves behind

Log rows are never deleted. As models they cannot be changed at all; erasure
and the retention period clear fields in them through the query builder.
Erasing an address deletes it and its cycles, and rewrites its log rows:

| Field | After erasure |
| --- | --- |
| entry and subscription references | null |
| IP, user agent | null |
| reference (e.g. a message id) | null |
| exact timestamp | null |
| project, list, step, purpose, status left, date | kept |

So the counts hold and nothing identifies the person directly. The line is
deliberate: a second-precision timestamp can single out a person, a date makes
that much harder. It is not anonymity: there is still one row per step, and in a
small list a date can point to someone. Hashing an email would not make a record
anonymous either, which is why the package does not offer that as a middle
ground.

If your threat model needs more, treat the log as personal data and delete it
yourself; if it needs less, nothing here stops you from keeping your own
aggregates.
