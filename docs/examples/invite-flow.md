# Example: an invite flow

Goal: let people in from the waitlist in batches, first come, first served, and
remember who was invited. The package deliberately has no `invited` state; this
builds one on top with a column and a macro.

## 1. A column for the invitation

```php
// database/migrations/xxxx_add_invited_at_to_waitlist_entries.php
Schema::table('waitlist_entries', function (Blueprint $table) {
    $table->timestamp('invited_at')->nullable();
});
```

```php
// app/Models/WaitlistEntry.php
namespace App\Models;

use Taldres\Waitlist\Models\WaitlistEntry as BaseEntry;

class WaitlistEntry extends BaseEntry
{
    public function __construct(array $attributes = [])
    {
        $this->mergeFillable(['invited_at']);

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return parent::casts() + ['invited_at' => 'datetime'];
    }
}
```

```php
// config/waitlist.php
'model' => App\Models\WaitlistEntry::class,
```

See [Extending](../extending.md#your-own-models) for what to keep in mind with
your own models.

## 2. The macro

```php
// app/Providers/AppServiceProvider.php
use App\Mail\YouAreInMail;
use Illuminate\Support\Facades\Mail;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\ScopedWaitlist;

public function boot(): void
{
    ScopedWaitlist::macro('inviteNext', function (int $count = 10) {
        /** @var ScopedWaitlist $this */
        return $this->entries()
            ->whereConsentedTo('waitlist')   // ask before you send
            ->whereNull('invited_at')
            ->oldest()                       // first come, first served
            ->limit($count)
            ->get()
            ->each(function ($entry) {
                $entry->forceFill(['invited_at' => now()])->save();
                Mail::to($entry->email)->queue(new YouAreInMail($entry, Waitlist::unsubscribeUrl($entry)));
            });
    });
}
```

`whereConsentedTo('waitlist')` keeps out everyone who never confirmed or has left
since; use the list's primary purpose there.

## 3. Invite

```php
Waitlist::for('beta')->inviteNext(25);
```

From a scheduled command, an admin button or a Tinker session. With
[several projects](../projects.md): `Waitlist::project('anvil')->for('default')->inviteNext(25)`.
