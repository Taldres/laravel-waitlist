<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Taldres\Waitlist\Contracts\SpamProtector;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\ClosureSpamProtector;

beforeEach(function () {
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::RoutesMiddleware->value, []);

    require __DIR__.'/../../routes/waitlist.php';
});

function denyAllProtector(): SpamProtector
{
    return new class implements SpamProtector
    {
        public function passes(Request $request): bool
        {
            return false;
        }
    };
}

class DenyAllSpamProtector implements SpamProtector
{
    public function passes(Request $request): bool
    {
        return false;
    }
}

it('rejects the http subscribe with 422 when the protector fails', function () {
    $this->app->instance(SpamProtector::class, denyAllProtector());

    $this->postJson('/waitlist', ['email' => 'user@example.com', 'purposes' => waitlistConsent()])
        ->assertStatus(422)
        ->assertExactJson(['message' => 'Spam check failed.', 'error' => 'spam_check_failed']);

    expect(WaitlistEntry::query()->count())->toBe(0);
});

it('accepts subscribes by default via the null protector', function () {
    $this->postJson('/waitlist', ['email' => 'user@example.com', 'purposes' => waitlistConsent()])->assertStatus(202);

    expect(WaitlistEntry::query()->count())->toBe(1);
});

it('never affects the action path — custom controllers stay unprotected by design', function () {
    $this->app->instance(SpamProtector::class, denyAllProtector());

    $result = Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());

    expect($result->entry->exists)->toBeTrue()
        ->and(WaitlistEntry::query()->count())->toBe(1);
});

it('rejects the http subscribe when a verifySpamUsing closure denies', function () {
    Waitlist::verifySpamUsing(fn (Request $request): bool => false);

    $this->postJson('/waitlist', ['email' => 'user@example.com', 'purposes' => waitlistConsent()])
        ->assertStatus(422)
        ->assertExactJson(['message' => 'Spam check failed.', 'error' => 'spam_check_failed']);

    expect(WaitlistEntry::query()->count())->toBe(0);
});

it('accepts the http subscribe when a verifySpamUsing closure allows', function () {
    Waitlist::verifySpamUsing(fn (Request $request): bool => true);

    $this->postJson('/waitlist', ['email' => 'user@example.com', 'purposes' => waitlistConsent()])->assertStatus(202);

    expect(WaitlistEntry::query()->count())->toBe(1);
});

class CountingSpamProtector implements SpamProtector
{
    public static int $built = 0;

    public function __construct()
    {
        self::$built++;
    }

    public function passes(Request $request): bool
    {
        return false;
    }
}

/**
 * Binds a protector that refuses everyone, the ways an app may bind one.
 */
function bindDenyingProtector(string $how): void
{
    match ($how) {
        'bind' => app()->bind(SpamProtector::class, DenyAllSpamProtector::class),
        'instance' => app()->instance(SpamProtector::class, new DenyAllSpamProtector),
        'singleton' => app()->singleton(SpamProtector::class, DenyAllSpamProtector::class),
    };

    app(SpamProtector::class);
}

/**
 * The status of a signup and of a manage link requested by address.
 *
 * @return array{0: int, 1: int}
 */
function spamCheckedStatuses(mixed $test, string $email): array
{
    return [
        $test->postJson('/waitlist', ['email' => $email, 'list' => 'beta', 'purposes' => waitlistConsent()])->status(),
        $test->postJson('/waitlist/manage-link', ['email' => $email, 'list' => 'beta'])->status(),
    ];
}

it('lets a verifySpamUsing closure take precedence over the bound protector on both endpoints, however it is bound', function (string $how) {
    bindDenyingProtector($how);
    Waitlist::verifySpamUsing(fn (Request $request): bool => true);

    expect(spamCheckedStatuses($this, 'user@example.com'))->toBe([202, 202]);

    Waitlist::verifySpamUsing(null);

    expect(spamCheckedStatuses($this, 'other@example.com'))->toBe([422, 422])
        ->and(WaitlistEntry::query()->count())->toBe(1);
})->with(['bind', 'instance', 'singleton']);

it('builds no bound protector while a verifySpamUsing closure stands in for it', function () {
    CountingSpamProtector::$built = 0;
    app()->bind(SpamProtector::class, CountingSpamProtector::class);
    Waitlist::verifySpamUsing(fn (Request $request): bool => true);

    expect(spamCheckedStatuses($this, 'user@example.com'))->toBe([202, 202])
        ->and(CountingSpamProtector::$built)->toBe(0);

    Waitlist::verifySpamUsing(null);

    expect(spamCheckedStatuses($this, 'other@example.com'))->toBe([422, 422])
        ->and(CountingSpamProtector::$built)->toBe(2);
});

it('leaves the binding alone: the container resolves the bound protector while a closure is registered', function () {
    app()->bind(SpamProtector::class, DenyAllSpamProtector::class);
    Waitlist::verifySpamUsing(fn (Request $request): bool => true);

    expect(app(SpamProtector::class))->toBeInstanceOf(DenyAllSpamProtector::class);
});

it('hands your own controller the protector the endpoints use', function () {
    app()->bind(SpamProtector::class, DenyAllSpamProtector::class);

    expect(Waitlist::spamProtector())->toBeInstanceOf(DenyAllSpamProtector::class);

    Waitlist::verifySpamUsing(fn (Request $request): bool => true);

    expect(Waitlist::spamProtector())->toBeInstanceOf(ClosureSpamProtector::class)
        ->and(Waitlist::spamProtector()->passes(request()))->toBeTrue();
});

it('passes the actual request to the verifySpamUsing closure', function () {
    $seen = null;

    Waitlist::verifySpamUsing(function (Request $request) use (&$seen): bool {
        $seen = $request->input('email');

        return true;
    });

    $this->postJson('/waitlist', ['email' => 'user@example.com', 'purposes' => waitlistConsent()])->assertStatus(202);

    expect($seen)->toBe('user@example.com');
});
