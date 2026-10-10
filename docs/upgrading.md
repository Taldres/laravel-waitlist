# Upgrading

How to move an app to a newer version, what changed since the development branch,
and how the package ships changes to the schema.

## The routine

1. Update the package and what it needs: `composer update taldres/laravel-waitlist --with-dependencies`.
2. Run `php artisan waitlist:check`. It lists the settings that no longer read, the
   settings in your published `config/waitlist.php` that this version ignores, and the
   tables and columns your migrations lack. With `--strict` it fails on the
   warnings too, for CI.
3. Compare your `config/waitlist.php` with the one in `vendor/taldres/laravel-waitlist/config/`
   and carry over the keys it gained. Merging is by key, so a key you leave out
   keeps the package default, but a key the package dropped stays in your file
   and is ignored without a word until `waitlist:check` says so.
4. Publish new migrations with `php artisan vendor:publish --tag=waitlist-migrations`
   and run them. Published files get new timestamps, so a file that is already in
   `database/migrations` is skipped by name: read the release notes for the
   migrations it names.
5. Restart queue workers and the scheduler, and run `config:cache` and `route:cache`
   again. Workers keep the code they started with, and jobs queued before the
   restart carry events as the old code serialized them.
6. If you use the bundled Boost skills, run `php artisan boost:update`; they follow
   the package version.
7. Read the **Breaking Changes** section of the release notes. A pull request that
   breaks something carries the `breaking` label, which puts it there.

## How schema changes ship

Before the first release the create migrations changed freely. **From the first release
on they never change.** A new column or table arrives in a migration of its own,
guarded so that it is safe whether or not the column exists:

```php
public function up(): void
{
    if (! Schema::hasColumn('waitlist_subscriptions', 'confirmation_outcome')) {
        Schema::table('waitlist_subscriptions', function (Blueprint $table) {
            $table->string('confirmation_outcome')->nullable();
        });
    }
}
```

A new install runs the create migrations and then these, an app that upgrades
only the new ones, and both end with the same schema. The release notes name
each such migration, and `waitlist:check` names a column that is still missing.

## From the development branch

If your app runs a commit from before the first release, you need what follows.
The first release itself has nothing to upgrade from.

**Settings that are gone.** Remove them from `config/waitlist.php` and `.env`; they
are ignored otherwise.

| Gone | Now |
| --- | --- |
| `urls`, the `WAITLIST_*_URL` variables | `$project->urls(...)` in the project definition |
| `lists`, `purposes` | `Waitlist::define()`, see [Projects](projects.md) |
| `encrypt` | addresses, metadata, IP and user agent are always encrypted |
| `reporting.timezone` | days end in `app.timezone`; rows written before keep the day they were recorded under, and `occurred_on` can be recomputed from `occurred_at` for rows that still have it |
| `double_opt_in.invalidate_confirm_token_after_confirmation` | a confirm link always reports the confirmed entry until the person has left |

**Schema.** Two columns were added to the create migrations. If you ran them earlier,
add them in a migration of your own:

```php
public function up(): void
{
    if (! Schema::hasColumn('waitlist_wordings', 'registered_by')) {
        Schema::table('waitlist_wordings', fn (Blueprint $table) => $table->string('registered_by')->nullable());
    }

    if (! Schema::hasColumn('waitlist_subscriptions', 'confirmation_outcome')) {
        Schema::table('waitlist_subscriptions', fn (Blueprint $table) => $table->string('confirmation_outcome')->nullable());
    }
}
```

**HTTP.** `POST /waitlist/manage-link` takes an `email` only. The request with the
unsubscribe token from a mail is `POST /waitlist/unsubscribe/{token}/manage-link`,
in the `links` group, so a check you put on the `signup` group no longer reaches it.
A failed bot check answers `422` with `error: spam_check_failed`. Use a matching
version of `@taldres/laravel-waitlist` for the JavaScript client.

**Code.**

- `ProjectCatalog` gained `origins()` and `periods()`. A catalog that extends
  `DefinedProjectCatalog` or `StoredWordingCatalog` inherits them; one that
  implements the contract itself returns `[]` and `new ProjectPeriods`.
- `Waitlist::confirmationMailed()` returns whether the report counted. Report with
  `$event->subscription`: each confirmation request takes one report, see
  [Mail](mail.md). Report a mail that could not be sent from the listener's
  `failed()` method with `Waitlist::confirmationFailed()`.
