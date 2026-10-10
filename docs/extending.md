# Extending

Add your own columns, methods and integrations without forking the package.

| You want to | Use |
| --- | --- |
| point mail links at a page per list or locale | `ConfirmationUrlGenerator` (`waitlist.url_generator`), see [the SPA example](examples/landing-page-spa.md#2-point-the-mail-links-at-your-pages); one page per project needs only [`urls()`](projects.md#pages) |
| decide which project an HTTP signup is for | `ProjectResolver` (`waitlist.project_resolver`), see [Projects, lists and fields](projects.md#over-http-tell-the-package-which-project-a-signup-is-for) |
| decide who may sign up, read the wording or request a manage link, per project and list | the `useWaitlist` gate, see [Who may call](projects.md#who-may-call-the-usewaitlist-gate) |
| keep projects, lists, fields, pages and wording in your database | `ProjectCatalog` (`waitlist.catalog`), see [A central waitlist API](examples/central-waitlist-api.md) |
| check signups for bots | `SpamProtector` (`waitlist.spam_protector`) or `Waitlist::verifySpamUsing()`, see [Securing the endpoints](securing-the-endpoints.md#bot-protection-via-spamprotector) |
| decide which spellings are the same address | `EmailNormalizer` (`waitlist.email_normalizer`), see [Encryption and keys](encryption-and-keys.md#the-email-normalizer) |
| encrypt with a key of your own | `Model::encryptUsing()` for the whole app, `Waitlist::encryptUsing()` for the package only, see [Encryption and keys](encryption-and-keys.md#your-own-encrypter) |
| put the tables on another database | `waitlist.connection` |
| add columns | [your own models](#your-own-models) |
| add methods to the API | [macros](#macros) |

## Your own models

Extend the package model and point the config at it:

```php
// app/Models/WaitlistEntry.php
namespace App\Models;

use Taldres\Waitlist\Models\WaitlistEntry as BaseEntry;

class WaitlistEntry extends BaseEntry
{
    public function __construct(array $attributes = [])
    {
        // The base model uses an explicit $fillable; add your columns before
        // the parent constructor fills the model, or it drops them.
        $this->mergeFillable(['invited_at']);

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return parent::casts() + [
            'invited_at' => 'datetime',
        ];
    }

    public function scopeInvited($query)
    {
        return $query->whereNotNull('invited_at');
    }
}
```

```php
// config/waitlist.php
'model' => App\Models\WaitlistEntry::class,
```

Add your columns with a regular migration in your app:

```php
Schema::table('waitlist_entries', function (Blueprint $table) {
    $table->timestamp('invited_at')->nullable();
});
```

All actions, commands and the fluent API return your model.

> If you override `booted()` on your subclass, call `parent::booted()`. The
> package's hooks strip the log and fire `EntryForgotten` when an entry is
> erased; without them, an erasure would leave request metadata and exact times
> in the log, and your listeners would never hear of it.

Cycles, consents and the log use their own models, swappable the same way:

```php
// config/waitlist.php
'subscription_model' => App\Models\WaitlistSubscription::class,
'consent_model' => App\Models\WaitlistConsent::class,
'activity_model' => App\Models\WaitlistActivity::class,
```

Your classes must extend the package models of the same name and keep their
numeric primary keys. If you override `booted()`, call `parent::booted()`: those
guards are what keep consent evidence from being rewritten and the log from
being edited.

A subscription is one opt-in cycle, a consent is agreement to one purpose within
it, and activity is the log behind reporting, whose rows are never deleted. None
of these should be written directly: go through the actions, so transitions stay
conditional and events fire once.

## Macros

`WaitlistManager`, `ScopedWaitlist`, `ProjectWaitlist` and `AllProjectsWaitlist`
are macroable:

```php
// app/Providers/AppServiceProvider.php
use Taldres\Waitlist\ScopedWaitlist;

ScopedWaitlist::macro('confirmedCount', function (): int {
    /** @var ScopedWaitlist $this */
    return $this->snapshot()->confirmed;
});
```

```php
Waitlist::for('beta')->confirmedCount();
```

[An invite flow](examples/invite-flow.md) puts both together: a column for the
invitation and a macro that invites the next people in line.
