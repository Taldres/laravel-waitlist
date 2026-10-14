# Commands

```bash
php artisan waitlist:install [--projects]                  # publish the provider, config and migrations; register the provider
php artisan waitlist:privacy [--project=]                  # input for your record of processing
php artisan waitlist:check [--strict]                       # settings and tables, for a deploy
php artisan waitlist:show {email} [--list=] [--project=] [--json|--pretty]  # right of access
php artisan waitlist:forget {email} [--list=] [--project=] # right to erasure
php artisan waitlist:forget --list= [--project=] --all [--force]  # erase a whole list
php artisan waitlist:prune [--list=] [--project=]          # apply the retention periods
php artisan waitlist:export {list} [--project=] [--status=] [--path=]  # streaming CSV export
php artisan waitlist:rekey                                 # move everything onto the current key
php artisan waitlist:wording {file} | --from-definitions [--project=] [--retire-missing]  # register wording, e.g. on deploy
php artisan waitlist:import {file} --source= --with-consent|--request-confirmation [--list=] [--project=] [--limit=] [--from-line=]  # import existing subscribers
```

## Which project a command covers

A request from a person concerns the whole controller, so without `--project`,
`waitlist:show`, `waitlist:forget`, `waitlist:prune` and `waitlist:privacy`
cover **every** project. A `--list` without `--project` means that list of the
default project; `waitlist:forget`, and `waitlist:show` when it finds nothing,
say where they looked.

`waitlist:forget --all`, `waitlist:export`, `waitlist:wording` and
`waitlist:import` act on the **default** project unless `--project` is given.

## Notes

- **`waitlist:install`** publishes `app/Providers/WaitlistServiceProvider.php`
  (tag `waitlist-provider`), `config/waitlist.php` (`waitlist-config`) and the
  migrations (`waitlist-migrations`), and adds the provider to
  `bootstrap/providers.php`. It never overwrites a published file and registers
  the provider only once, so it can run again. An app namespace other than `App`
  is kept. With `--projects`, the provider starts with two named projects and the
  default project with pages only, see
  [Several products in one app](../projects.md#several-products-in-one-app); the
  command warns that the package routes, once enabled, answer every signup with
  `422` until you bind a `ProjectResolver`. This provider is copied, not published under a tag,
  and an existing provider is left as it is, which the command says.
- **`waitlist:prune`** applies the retention periods. Schedule it yourself, for
  example `Schedule::command('waitlist:prune')->daily()`, and keep Laravel's
  scheduler running; `waitlist:privacy` warns while no scheduled task runs it. See
  [GDPR in practice](../gdpr.md#retention).
- **`waitlist:forget --all`** asks before erasing a list; without a terminal to
  ask, it needs `--force`. Use it once a list's purpose is fulfilled, after the
  launch for example.
- **`waitlist:export`** writes the columns in `waitlist.export.columns`, limited
  to `CsvExporter::EXPORTABLE`, so a stray config entry can never write tokens
  into a file. With `waitlist.export.spreadsheet_safe` on, it prefixes cells
  starting with `=`, `+`, `-`, `@`, tab or CR so spreadsheets treat them as text.
  Without `--path` it writes `waitlist-{list}.csv` to the current directory. The
  file holds decrypted addresses: treat it as personal data.
- **`waitlist:rekey`** writes every value and lookup hash again under the current
  key, after an `APP_KEY` rotation. See
  [Encryption and keys](../encryption-and-keys.md).
- **`waitlist:wording`** registers wording from a file or, with
  `--from-definitions`, the purposes a project's definition holds, see
  [Purposes and wording](../purposes-and-wording.md#registering-wording-where-it-is-written).
- **`waitlist:import`** reads a CSV with a header row and the columns `email`,
  `purposes` (`purpose:version`, several separated by commas or spaces), and
  optionally `list`, `locale` and `consented_at`; any other column is refused.
  Pass `--with-consent` to put every address on its list without a mail, at the
  time in `consented_at`, or `--request-confirmation` to let each wait for the
  confirmation mail. An address already on its list is skipped. `--limit` stops
  after that many imports and prints the command that resumes with
  `--from-line`; resume with it, as running the file from the start again brings
  back every address erased since, and keep the file unchanged until the import is finished. A setup
  error, such as a list without wording, stops the run and prints the same
  resume command. `consented_at` is a date, optionally with a
  time and an offset (`2026-10-01`, `2026-10-01 12:00:00`,
  `2026-10-01T12:00:00+02:00`); anything else, a date that rolls over included,
  is refused. A row that fails is reported by its line, the column and a fixed
  reason, never a value from the file, and the exit code is then 1; retry those
  rows with a file that holds only them. See [Importing existing subscribers](../gdpr.md#importing-existing-subscribers).
- **`waitlist:check`** reads every setting and reports all that do not read at
  once, by key and never by value, so a typo in `.env` is found on deploy and not
  by the first request that needs it. It also warns of settings in a published
  `config/waitlist.php` that the package does not read (a typo, say, which stays
  ignored otherwise). It resolves the five
  contracts as well and fails on one the container cannot build, or builds as
  something that does not implement it, naming the contract and the error,
  such as a binding to a class that does not exist; the spam protector checked
  is the one the endpoints use, and it is never asked to check a request.
  Resolving builds the bound classes, so run the check where the application can
  boot with its database and secrets. It
  hints while no scheduled task of your application runs
  `waitlist:prune` (only Laravel's scheduler is in sight, so `--strict` leaves
  the hint alone for an app that runs it from cron), compares the tables and
  columns with the database, naming
  what your migrations lack, and compares the registered package routes with
  `routes.enabled`: a group missing although the routes are on, or routes still
  registered although they are off, as when a route cache was built under other
  settings. Run it again after `route:cache`. The exit code is 1 for a setting, a
  binding or a table, and with `--strict` for a warning too. `php artisan about` shows a
  Laravel Waitlist section with the same state.
- **`waitlist:privacy`** describes the configured package storage, the fields
  and purposes of every list, retention, the rate limits (including those of
  servers calling for a project), the sources of imported entries still held
  and the listeners registered for package events. It also warns, on stderr so a redirected record stays clean, about
  what a launch trips over: a mail link that is not https or points at
  localhost (a page's URL, or `APP_URL` for a page left on the package routes),
  `APP_DEBUG` on while the routes are enabled, and `authentication.guards` set
  while neither `authentication.required` nor auth middleware in
  `routes.group_middleware.signup` keeps guests out. Run it on the production host. It does not audit
  your infrastructure or find every recipient; complete it yourself.
