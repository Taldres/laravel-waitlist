<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Taldres\Waitlist\Contracts\SpamProtector;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\TimeTrapSpamProtector;
use Taldres\Waitlist\Tests\Fixtures\ProjectCaller;

beforeEach(function () {
    enableWaitlistRoutes();

    $this->freezeTime();
    app()->bind(SpamProtector::class, TimeTrapSpamProtector::class);
});

function timeTrapChallenge(): string
{
    $challenge = test()->getJson('/waitlist/purposes?list=beta')->assertOk()->json('challenge');

    expect($challenge)->toBeString()->not->toBe('');

    return $challenge;
}

/**
 * @param  array<string, mixed>  $extra
 */
function timeTrapSignup(?string $challenge, array $extra = [], string $email = 'user@example.com'): TestResponse
{
    return test()->postJson('/waitlist', ['email' => $email, 'list' => 'beta', 'purposes' => waitlistConsent(), ...$extra], array_filter([
        'X-Waitlist-Challenge' => $challenge,
    ]));
}

function assertSpamRefused(TestResponse $response): void
{
    $response->assertStatus(422)->assertExactJson(['message' => 'Spam check failed.', 'error' => 'spam_check_failed']);
}

it('hands out no challenge while another protector is bound', function () {
    app()->bind(SpamProtector::class, fn () => new class implements SpamProtector
    {
        public function passes(Request $request): bool
        {
            return true;
        }
    });

    $response = $this->getJson('/waitlist/purposes?list=beta')->assertOk();

    expect($response->json())->toHaveKeys(['data'])->not->toHaveKey('challenge')
        ->and($response->headers->hasCacheControlDirective('no-store'))->toBeFalse();
});

it('keeps an answer with a challenge out of every cache', function () {
    $response = $this->getJson('/waitlist/purposes?list=beta')->assertOk();

    expect($response->json('challenge'))->toBeString()
        ->and($response->headers->getCacheControlDirective('no-store'))->toBeTrue();
});

it('accepts a signup that sends the challenge back after a person could fill in the form', function () {
    $challenge = timeTrapChallenge();

    $this->travel(3)->seconds();

    timeTrapSignup($challenge)->assertStatus(202);

    expect(WaitlistEntry::query()->count())->toBe(1);
});

it('accepts the challenge at both ends of its window', function (int $seconds) {
    $challenge = timeTrapChallenge();

    $this->travel($seconds)->seconds();

    timeTrapSignup($challenge)->assertStatus(202);
})->with([2, 3600]);

it('refuses a signup sent faster than a person fills in a form', function () {
    $challenge = timeTrapChallenge();

    $this->travel(1999)->milliseconds();

    assertSpamRefused(timeTrapSignup($challenge));

    expect(WaitlistEntry::query()->count())->toBe(0);
});

it('refuses a challenge that went stale', function () {
    $challenge = timeTrapChallenge();

    $this->travel(3601)->seconds();

    assertSpamRefused(timeTrapSignup($challenge));
});

it('refuses a signup without a challenge it issued', function (?string $challenge) {
    $this->travel(3)->seconds();

    assertSpamRefused(timeTrapSignup($challenge));
})->with([
    'none' => [null],
    'not encrypted' => ['1700000000000'],
    'encrypted by the app for something else' => fn () => Crypt::encryptString((string) (now()->getTimestampMs() - 5000)),
    'a challenge from the future' => fn () => Crypt::encryptString('waitlist-challenge|'.(now()->getTimestampMs() + 60000)),
]);

it('refuses a signup whose hidden field is filled, and accepts an empty one', function () {
    $challenge = timeTrapChallenge();

    $this->travel(3)->seconds();

    assertSpamRefused(timeTrapSignup($challenge, ['website' => 'https://spam.example']));
    assertSpamRefused(timeTrapSignup($challenge, ['website' => ['x']]));
    timeTrapSignup($challenge, ['website' => ''])->assertStatus(202);
    timeTrapSignup($challenge, ['website' => null], 'other@example.com')->assertStatus(202);

    expect(WaitlistEntry::query()->count())->toBe(2);
});

it('checks a manage link requested by address the same way', function () {
    $challenge = timeTrapChallenge();

    assertSpamRefused($this->postJson('/waitlist/manage-link', ['email' => 'user@example.com', 'list' => 'beta'], ['X-Waitlist-Challenge' => $challenge]));

    $this->travel(3)->seconds();

    assertSpamRefused($this->postJson('/waitlist/manage-link', ['email' => 'user@example.com', 'list' => 'beta', 'website' => 'x'], ['X-Waitlist-Challenge' => $challenge]));
    $this->postJson('/waitlist/manage-link', ['email' => 'user@example.com', 'list' => 'beta'], ['X-Waitlist-Challenge' => $challenge])->assertStatus(202);
});

it('keeps accepting a challenge issued before the app key was rotated', function () {
    $old = config('app.key');
    $challenge = timeTrapChallenge();

    rotateAppKey([$old]);
    $this->travel(3)->seconds();

    timeTrapSignup($challenge)->assertStatus(202);
});

it('leaves the token links in mails alone', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');

    $this->postJson("/waitlist/confirm/{$tokens['confirm']}")->assertOk();
    $this->postJson("/waitlist/unsubscribe/{$tokens['unsubscribe']}/manage-link")->assertStatus(202);
    $this->postJson("/waitlist/unsubscribe/{$tokens['unsubscribe']}")->assertOk();
});

it('does not check a server calling for its project', function () {
    Auth::viaRequest('waitlist-server', fn (Request $request) => is_string($project = $request->header('X-Server')) ? new ProjectCaller($project) : null);
    config()->set('auth.guards.server', ['driver' => 'waitlist-server']);
    config()->set(ConfigKey::AuthenticationGuards->value, ['server']);

    $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => waitlistConsent()], ['X-Server' => 'default'])
        ->assertStatus(202);

    Auth::forgetGuards();

    assertSpamRefused($this->postJson('/waitlist', ['email' => 'guest@example.com', 'list' => 'beta', 'purposes' => waitlistConsent()]));
});

it('gives way to another protector, which needs no challenge', function () {
    useSpamCheck(fn (Request $request): bool => true);

    expect($this->getJson('/waitlist/purposes?list=beta')->assertOk()->json())->not->toHaveKey('challenge');

    timeTrapSignup(null)->assertStatus(202);
});
