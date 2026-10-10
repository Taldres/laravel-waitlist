# Contribution Guide

Thank you for considering contributing to Laravel Waitlist! Please review the following guidelines before submitting a pull request.

For significant changes, please open an issue first so we can discuss the approach.

## Scope

This package deliberately stays small: entry storage, subscription cycles, consent
per purpose, tokens, retention, export, and GDPR-relevant data handling. Features
like referrals, queue positions, dashboards, mail delivery, or marketing
integrations are out of scope for the core; build them as separate packages on top
of the events and contracts.

Privacy is a design constraint: new features must not store additional personal
data by default, and anything that does must be stored with Laravel's `encrypted`
casts, covered by the access (`waitlist:show`) and erasure (`waitlist:forget`)
paths and by a retention period, and listed by `waitlist:privacy`.

## Process

1. Fork the project
2. Create a new branch
3. Code, test, commit, and push
4. Open a pull request detailing your changes

## Guidelines

- Ensure the coding style passes by running `composer lint`.
- Add tests for behavior changes.
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
