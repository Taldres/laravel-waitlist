# Contribution Guide

Thank you for considering contributing to Laravel Waitlist! Please review the following guidelines before submitting a pull request.

For significant changes, please open an issue first so we can discuss the approach.

## Where to start

Bug reports with a reproduction, documentation, examples and small features that fit the scope below are all welcome. The issues labeled [`good first issue`](https://github.com/Taldres/laravel-waitlist/labels/good%20first%20issue) are meant for a first pull request, and [`help wanted`](https://github.com/Taldres/laravel-waitlist/labels/help%20wanted) marks work that needs someone with a free evening. If you have an idea of your own, open a [feature request](https://github.com/Taldres/laravel-waitlist/issues/new?template=feature.yml) first; it saves you building something that belongs elsewhere.

## Scope

This package deliberately stays small: entry storage, subscription cycles, consent
per purpose, tokens, retention, export, and GDPR-relevant data handling. Features
like referrals, invite workflows, dashboards, mail delivery, or marketing
integrations are out of scope for the core; build them as separate packages on top
of the events and contracts, see [Extending](../docs/extending.md).

Privacy is a design constraint: new features must not store additional personal
data by default, and anything that does must be stored with Laravel's `encrypted`
casts, covered by the access (`waitlist:show`) and erasure (`waitlist:forget`)
paths and by a retention period, and listed by `waitlist:privacy`.

## How the code is organized

- `src/Actions`: one class per step a person or an operator takes (subscribe, confirm, withdraw, erase, prune, import, ...), invoked directly.
- `src/Support/SubscriptionLifecycle.php`: subscription cycles, consents and activity are written only through the actions and this class. A transition is a conditional update inside a transaction, so it happens once and its event fires once. Never write those tables from anywhere else.
- `src/Http` and `routes/waitlist.php`: the JSON API. The routes that name a project use a form request that asks the project resolver and the `useWaitlist` gate before its rules; the token routes find their entry by the token. Refusals the routes answer themselves come from `ApiError`.
- `src/Console/Commands`: the Artisan commands.
- `src/Config`: typed, checked reads of `config/waitlist.php`; a setting that does not read stops only the feature that needs it.
- `src/Definitions` and `src/Contracts`: projects, lists and purposes, and the extension points documented in [Extending](../docs/extending.md).
- `src/Models`, `database/migrations`: the tables. Migrations are published into applications, so a schema change is a new migration that upgrades an existing table, never an edit of a released one.
- `src/Events`: what the application listens to, for example to send mail.
- `docs/`, `resources/boost/` and `CHANGELOG.md`: documentation and the bundled Boost skills and guideline. A behavior change updates them in the same pull request.

[Lifecycle](../docs/lifecycle.md), [GDPR in practice](../docs/gdpr.md) and [Securing the endpoints](../docs/securing-the-endpoints.md) are the binding contracts. A change that touches tokens, consent, personal data, erasure, project isolation or the lifecycle gets a closer review against them.

## Process

1. Fork the project
2. Create a new branch
3. Code, test, commit, and push
4. Open a pull request detailing your changes

The title of a pull request becomes the commit message when it is squash-merged, so write it like one. We use
[Conventional Commits](https://www.conventionalcommits.org/): `type: lowercase imperative`,
for example `fix: sign up again when a prune ends the cycle a signup resends for`.
The types are `feat`, `fix`, `docs`, `refactor`, `test`, `ci` and `chore`; `!` marks a
breaking change. Describe what was wrong or missing, what changes for people using the
package, and what a reviewer should look at twice. A maintainer sets the label.

## Guidelines

- Ensure the coding style passes by running `composer lint`.
- Add tests for behavior changes.
- Update the documentation and add a line to `CHANGELOG.md` under "Unreleased" when users can notice the change.
- Keep code, comments, and docs in English.
- Send a coherent commit history, making sure each commit in your pull request is meaningful.
- You may need to [rebase](https://git-scm.com/book/en/v2/Git-Branching-Rebasing) to avoid merge conflicts.
- Please remember that we follow [SemVer](http://semver.org/).

## Setup

Clone your fork, then install the dev dependencies:

```bash
composer install
```

## Lint

```bash
composer lint
```

## Tests

Run everything CI runs, static analysis, lint, type coverage and Pest:

```bash
composer test
```

The tests drive the package through its public surface: the facade, the commands and
the HTTP routes. `tests/Pest.php` has the helpers you will want, such as
`waitlistConsent()`, `subscribeAndCapture()` and `enableWaitlistRoutes()`, and
`tests/ArchTest.php` states what each namespace may contain.

The concurrency tests need two connections to one server database and are
skipped on SQLite:

```bash
DB_CONNECTION=mysql vendor/bin/pest
DB_CONNECTION=mariadb vendor/bin/pest
DB_CONNECTION=pgsql vendor/bin/pest
```

## Workbench

Try the HTTP API in a running app:

```bash
composer serve
```

The workbench writes a `.env` and a SQLite database into the Testbench skeleton under
`vendor/`, which the tests would then pick up. Run `composer clear` after you stop it.
