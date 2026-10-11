# Extending

Add your own columns, methods and integrations without forking the package.

| You want to | Use |
| --- | --- |
| point mail links at a page per list or locale | `ConfirmationUrlGenerator`, see [the SPA example](examples/landing-page-spa.md#2-point-the-mail-links-at-your-pages); one page per project needs only [`urls()`](projects.md#pages) |
| decide which project an HTTP signup is for | `ProjectResolver`, see [Projects, lists and fields](projects.md#over-http-tell-the-package-which-project-a-signup-is-for) |
| decide who may sign up, read the wording or request a manage link, per project and list | the `useWaitlist` gate, see [Who may call](projects.md#who-may-call-the-usewaitlist-gate) |
| keep projects, lists, fields, pages and wording in your database | `ProjectCatalog`, see [A central waitlist API](examples/central-waitlist-api.md) |
| check signups for bots | `SpamProtector` or `Waitlist::verifySpamUsing()`, see [Securing the endpoints](securing-the-endpoints.md#bot-protection-via-spamprotector) |
| decide which spellings are the same address | `EmailNormalizer`, see [Encryption and keys](encryption-and-keys.md#the-email-normalizer) |
| encrypt with a key of your own | `Model::encryptUsing()` for the whole app, `Waitlist::encryptUsing()` for the package only, see [Encryption and keys](encryption-and-keys.md#your-own-encrypter) |
| put the tables on another database | `waitlist.connection` |
| send the mails or react to a signup, confirmation or departure | the package's [events](reference/events.md), see [Mail](mail.md) |
| add columns | [your own models](#your-own-models) |
| add methods to the API | [macros](#macros) |

Each contract in the table is bound in the container to the package's default
class. Bind your own in the `register()` method of a service provider, the
published `WaitlistServiceProvider` for example:

```php
// app/Providers/WaitlistServiceProvider.php
use Taldres\Waitlist\Contracts\SpamProtector;

public function register(): void
{
    $this->app->bind(SpamProtector::class, \App\Waitlist\TurnstileProtector::class);
}
```

Use `bind()`. The package binds its defaults with `bindIf()` when it registers,
which Laravel does before your providers: your `bind()` then replaces the
default, while a `bindIf()` of yours would find the contract bound and leave the
default in place. A binding made before the package registers is kept. On the
signup and the manage link by address, a closure from `Waitlist::verifySpamUsing()`
wins over the bound `SpamProtector`, which is then not even built;
`app(SpamProtector::class)` still resolves the bound class.

`config/waitlist.php` no longer reads `catalog`, `url_generator`,
`project_resolver`, `email_normalizer` or `spam_protector`. Remove them from a
config published before. One that still holds anything but the package's
default, null included, would change what the package does if it were ignored, the project of a
signup or the lookup hash for example. So until you bind the contract yourself,
resolving it throws an `InvalidConfigurationException` that names the key, and
`waitlist:check` fails on the key until you remove it. One that holds the
default, as `waitlist:install` published it, changes nothing and is only warned
of.

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

The registered wording (`WaitlistWording`) is not swappable: the package reads and
writes it itself, in the actions and in `waitlist:wording`. Keep what you want to
add in a table of your own, keyed by project, purpose and version.

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

## What stays supported

The table above, with each contract bound in the container, your own models, the
events and the macros are the supported extension points. A change to one of
them is a breaking change and is called out in the changelog. `DefinedProjectCatalog` and `StoredWordingCatalog` count
among them as base classes for a catalog of your own, as
[A central waitlist API](examples/central-waitlist-api.md) shows.

Other public classes with behavior, such as the actions, `SubscriptionLifecycle`,
the controllers and the default implementations of the contracts, are not
`final` either, so a need the package does not cover can still be met by
extending one. That is not covered by the promise above: their internals may
change in any release. A subclass only takes effect where the package resolves
the class from the container or the config, by a binding of your own for
example; classes it creates with `new`, such as the fluent API objects, the
reports and the events, cannot be swapped that way.

If you replace `SubscriptionLifecycle`, keep what it guarantees: every
transition is a conditional update inside a transaction, the activity row and
the status move only when exactly one row changed, and each event is dispatched
once, after the commit.

Value objects and classes marked `@internal` are `final` and not meant to be
extended.
