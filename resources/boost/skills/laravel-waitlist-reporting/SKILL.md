---
name: laravel-waitlist-reporting
description: >
  Build waitlist statistics with taldres/laravel-waitlist: signups and
  confirmations per day for charts, totals and confirmation rates for a period,
  the confirmed count on any past day, and how many people are on a list right
  now, for dashboards, admin pages and API endpoints.
license: MIT
metadata:
  author: Dennis Petersmann
---

# Laravel Waitlist: reporting

Use this skill when an application using `taldres/laravel-waitlist` needs numbers
about its waitlists: a dashboard, a chart, an admin widget, a weekly report.

## Primary Goal

- answer each question from the right source: the activity log for "what
  happened", `snapshot()` for "who is on the list now"

## Workflow

### 1. Pick the source

```php
Waitlist::for('beta')->report()->between('2026-08-01', '2026-08-31')->totals();
// "230 confirmed in August": the activity log, a fact about the past

Waitlist::for('beta')->snapshot();
// "197 on the list right now": the addresses, a fact about the present
```

A later erasure never changes the first number. Counts are steps, not people:
someone who leaves and comes back is confirmed twice.

### 2. Now: `snapshot()`

```php
$snapshot = Waitlist::for('beta')->snapshot();

$snapshot->pending;       // waiting for confirmation
$snapshot->confirmed;     // on the list
$snapshot->unsubscribed;  // left, still within retention
$snapshot->active();      // pending + confirmed
$snapshot->total();
$snapshot->toArray();     // JSON-ready, with project and list
```

### 3. A period: totals

```php
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\EntryStatus;

$totals = Waitlist::for('beta')->report()->since(30)->totals();   // the last 30 days

$totals->signups();                       // subscribed + resubscribed
$totals->of(ActivityType::Confirmed);
$totals->of(ActivityType::Unsubscribed, from: EntryStatus::Confirmed);  // left after confirming
$totals->confirmationRate();              // ?float, null when nobody signed up
$totals->confirmedChange();               // net change of the confirmed count
```

### 4. A chart: daily series

```php
$series = Waitlist::for('beta')->report()->since(30)->daily()->fillGaps();

foreach ($series as $day) {
    $day->date;                       // CarbonImmutable
    $day->signups();
    $day->of(ActivityType::Confirmed);
}

$series->toArray();   // [{date, subscribed, resubscribed, confirmation_requested, confirmation_mailed, confirmation_failed, confirmed, unsubscribed, expired, erased, consent_granted, consent_withdrawn}]
```

`fillGaps()` adds days without activity, which a chart needs.

### 5. The confirmed count on a past day

```php
Waitlist::for('beta')->report()->confirmedOn('2026-09-30');   // at the end of that day
```

Exact as far as the log reaches back, to the day. Today's value equals
`snapshot()->confirmed`.

### 6. Scope

- `Waitlist::report()`: every list of the default project; `->forList('beta', 'launch')` narrows it.
- `Waitlist::for('beta')->report()`: one list.
- `Waitlist::project('acme')->report()`: one project; `Waitlist::allProjects()->report()`: all.
- `->forPurpose('newsletter')`: grants and withdrawals of an optional purpose only;
  signups, confirmations and departures carry no purpose and drop out.
- Without `since()` or `between()`, everything stored.

## Rules, References, and Templates

- A day always ends in `app.timezone`: each row stores its day (`occurred_on`)
  when written, and reports group on it and resolve their periods in that zone.
  An erasure clears the exact time, so set `app.timezone` before the first
  signup; changing it later does not redate existing rows, only later ones. With
  UTC, a signup at 23:30 in Berlin in summer belongs to the next UTC day: a
  defined boundary, not a miscount.
- Reports are one grouped query; cache them in the application if a dashboard
  polls often.
- The remaining log is minimized, not anonymous: keep reports for the people who
  need them.
- Guide: https://github.com/Taldres/laravel-waitlist/blob/main/docs/reporting.md

## Examples

- "Show signups per day for the last month in the admin": step 4 as JSON for the
  chart library.
- "How many confirmed people did we have at the end of each month?":
  `confirmedOn()` per month end.
- "Conversion rate of the beta list this week": `report()->since(7)->totals()->confirmationRate()`.

## Anti-patterns

- Counting `WaitlistEntry` rows for past periods; erasures and retention change them.
- Counting `Waitlist::for($list)->count()` as "on the list": it includes people who
  left; use `snapshot()->confirmed` or `active()`.
- Computing dates in SQL with engine-specific functions; the log stores the
  reporting day already.
- Expecting a purpose-filtered report to contain signups or confirmations.
