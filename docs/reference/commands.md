# Commands

```bash
php artisan waitlist:install                               # publish the provider, config and migrations; register the provider
php artisan waitlist:privacy [--project=]                  # input for your record of processing
php artisan waitlist:check [--strict]                       # settings, tables and leftovers of an older version, for a deploy
php artisan waitlist:show {email} [--list=] [--project=] [--json|--pretty]  # right of access
php artisan waitlist:forget {email} [--list=] [--project=] # right to erasure
php artisan waitlist:forget --list= [--project=] --all [--force]  # erase a whole list
php artisan waitlist:prune [--list=] [--project=]          # apply the retention periods
php artisan waitlist:export {list} [--project=] [--status=] [--path=]  # streaming CSV export
php artisan waitlist:rekey                                 # move everything onto the current key
php artisan waitlist:wording {file} | --from-definitions [--project=] [--retire-missing]  # register wording, e.g. on deploy
```

## Which project a command covers

A request from a person concerns the whole controller, so without `--project`,
`waitlist:show`, `waitlist:forget`, `waitlist:prune` and `waitlist:privacy`
cover **every** project. A `--list` without `--project` means that list of the
default project; `waitlist:forget`, and `waitlist:show` when it finds nothing,
say where they looked.

`waitlist:forget --all`, `waitlist:export` and `waitlist:wording` act on the
**default** project unless `--project` is given.

## Notes

- **`waitlist:install`** publishes `app/Providers/WaitlistServiceProvider.php`
  (tag `waitlist-provider`), `config/waitlist.php` (`waitlist-config`) and the
  migrations (`waitlist-migrations`), and adds the provider to
  `bootstrap/providers.php`. It never overwrites a published file and registers
  the provider only once, so it can run again. An app namespace other than `App`
  is kept.
- **`waitlist:prune`** is scheduled by the package (`retention.schedule`, 03:15
  daily); Laravel's scheduler must run. See [GDPR in practice](../gdpr.md#retention).
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
- **`waitlist:check`** reads every setting and reports all that do not read at
  once, by key and never by value, so a typo in `.env` is found on deploy and not
  by the first request that needs it. It also warns of settings in a published
  `config/waitlist.php` that this version does not read (a removed key stays
  ignored otherwise), and compares the tables and columns of this version with the
  database, naming what your migrations lack. The exit code is 1 for a setting or
  a table, and with `--strict` for a warning too. `php artisan about` shows a
  Laravel Waitlist section with the same state.
- **`waitlist:privacy`** describes the configured package storage, the fields
  and purposes of every list, retention, the rate limits (including those of
  servers calling for a project) and the listeners registered for package
  events. It also warns, on stderr so a redirected record stays clean, about
  what a launch trips over: a mail link that is not https or points at
  localhost (a page's URL, or `APP_URL` for a page left on the package routes),
  `APP_DEBUG` on while the routes are enabled, and `authentication.guards` set
  without `authentication.required`. Run it on the production host. It does not audit
  your infrastructure or find every recipient; complete it yourself.
