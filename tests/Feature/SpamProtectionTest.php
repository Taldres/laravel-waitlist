<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Taldres\Waitlist\Contracts\SpamProtector;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;

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
        ->assertJson(['message' => 'Spam check failed.']);

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
        ->assertJson(['message' => 'Spam check failed.']);

    expect(WaitlistEntry::query()->count())->toBe(0);
});

it('accepts the http subscribe when a verifySpamUsing closure allows', function () {
    Waitlist::verifySpamUsing(fn (Request $request): bool => true);

    $this->postJson('/waitlist', ['email' => 'user@example.com', 'purposes' => waitlistConsent()])->assertStatus(202);

    expect(WaitlistEntry::query()->count())->toBe(1);
});

it('lets a verifySpamUsing closure take precedence over the configured protector', function () {
    config()->set(ConfigKey::SpamProtector->value, DenyAllSpamProtector::class);

    Waitlist::verifySpamUsing(fn (Request $request): bool => true);

    $this->postJson('/waitlist', ['email' => 'user@example.com', 'purposes' => waitlistConsent()])->assertStatus(202);

    expect(WaitlistEntry::query()->count())->toBe(1);
});

it('restores the configured protector when verifySpamUsing receives null', function () {
    config()->set(ConfigKey::SpamProtector->value, DenyAllSpamProtector::class);

    Waitlist::verifySpamUsing(fn (Request $request): bool => true);
    Waitlist::verifySpamUsing(null);

    $this->postJson('/waitlist', ['email' => 'user@example.com', 'purposes' => waitlistConsent()])->assertStatus(422);

    expect(WaitlistEntry::query()->count())->toBe(0);
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
