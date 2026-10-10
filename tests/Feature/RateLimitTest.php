<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Tests\Fixtures\ProjectCaller;

beforeEach(function () {
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::RoutesMiddleware->value, []);
    config()->set(ConfigKey::SignupPerMinute->value, 2);
    config()->set(ConfigKey::LinkPerMinute->value, 2);

    $this->loadRoutes = fn () => require __DIR__.'/../../routes/waitlist.php';
    ($this->loadRoutes)();

    $this->subscribe = fn (string $ip = '10.0.0.1', string $email = 'user@example.com') => $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/waitlist', ['email' => $email, 'purposes' => waitlistConsent()]);

    $this->oneClick = fn (string $token, string $ip = '66.249.0.1') => $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post("/waitlist/unsubscribe/{$token}", ['List-Unsubscribe' => 'One-Click']);
});

it('limits signups per client and endpoint', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->getJson('/waitlist/purposes?list=beta')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->getJson('/waitlist/purposes?list=beta')->assertOk();

    ($this->subscribe)()->assertStatus(202);
    ($this->subscribe)()->assertStatus(202);
    ($this->subscribe)()->assertStatus(429);
    ($this->subscribe)('10.0.0.2')->assertStatus(202);
});

it('limits a token link per token, so a mail provider\'s shared address cannot block one-click requests', function () {
    $tokens = collect(range(1, 3))->map(fn (int $n) => subscribeAndCapture('beta', "user{$n}@example.com")['unsubscribe']);

    foreach ($tokens as $token) {
        ($this->oneClick)($token)->assertOk();
    }

    ($this->oneClick)($tokens[0])->assertOk();
    ($this->oneClick)($tokens[0])->assertStatus(429);
});

it('still bounds how many token requests one client can send', function () {
    config()->set(ConfigKey::LinksPerIpPerMinute->value, 3);

    foreach (range(1, 3) as $n) {
        $this->getJson("/waitlist/confirm/guess{$n}")->assertNotFound();
    }

    $this->getJson('/waitlist/confirm/guess4')->assertStatus(429);
});

it('lets a group use a limiter of your own and leaves the other one alone', function () {
    RateLimiter::for('signup-strict', fn (Request $request) => Limit::perMinute(1)->by($request->ip()));
    config()->set(ConfigKey::SignupLimiter->value, 'signup-strict');
    ($this->loadRoutes)();

    ($this->subscribe)()->assertStatus(202);
    ($this->subscribe)('10.0.0.1', 'other@example.com')->assertStatus(429);

    $tokens = collect(range(1, 3))->map(fn (int $n) => subscribeAndCapture('beta', "user{$n}@example.com")['unsubscribe']);

    foreach ($tokens as $token) {
        ($this->oneClick)($token)->assertOk();
    }
});

it('switches a group off, e.g. behind a WAF that limits already', function () {
    config()->set(ConfigKey::SignupLimiter->value, null);
    ($this->loadRoutes)();

    foreach (range(1, 5) as $n) {
        ($this->subscribe)('10.0.0.1', "user{$n}@example.com")->assertStatus(202);
    }
});

it('keeps limiting with the default limits when the published config has no limiters key', function () {
    config()->set('waitlist.routes', array_diff_key(config('waitlist.routes'), ['limiters' => true]));
    ($this->loadRoutes)();

    ($this->subscribe)('10.0.0.1', 'one@example.com')->assertStatus(202);
    ($this->subscribe)('10.0.0.1', 'two@example.com')->assertStatus(202);
    ($this->subscribe)('10.0.0.1', 'three@example.com')->assertStatus(429);
});

it('registers its limiters for your own routes too, also with the package routes off', function () {
    expect(RateLimiter::limiter('waitlist'))->not->toBeNull()
        ->and(RateLimiter::limiter('waitlist-links'))->not->toBeNull();
});

describe('a server calling for its project', function () {
    beforeEach(function () {
        Auth::viaRequest('waitlist-server', fn (Request $request) => $request->header('X-Server') === 'site' ? new ProjectCaller('default') : null);
        config()->set('auth.guards.server', ['driver' => 'waitlist-server']);
        config()->set(ConfigKey::AuthenticationGuards->value, ['server']);
        config()->set(ConfigKey::CallerSignupPerMinute->value, 3);

        $this->fromServer = fn (string $email, string $ip = '192.0.2.10', ?string $visitor = null) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/waitlist', ['email' => $email, 'purposes' => waitlistConsent()], array_filter([
                'X-Server' => 'site',
                'X-Waitlist-Client-Ip' => $visitor,
            ]));
    });

    it('caps the server as a whole instead of per address, since all its visitors share one', function () {
        foreach (range(1, 3) as $n) {
            ($this->fromServer)("user{$n}@example.com")->assertStatus(202);
        }

        ($this->fromServer)('user4@example.com', ip: '192.0.2.11')->assertStatus(429);

        // The request guard keeps its user between test requests.
        Auth::forgetGuards();
        ($this->subscribe)('192.0.2.10', 'guest@example.com')->assertStatus(202);
    });

    it('limits per visitor as well once the server forwards the address', function () {
        config()->set(ConfigKey::ClientIpHeader->value, 'X-Waitlist-Client-Ip');

        ($this->fromServer)('one@example.com', visitor: '203.0.113.1')->assertStatus(202);
        ($this->fromServer)('two@example.com', visitor: '203.0.113.1')->assertStatus(202);
        ($this->fromServer)('three@example.com', visitor: '203.0.113.1')->assertStatus(429);

        // A refused visitor does not use up the server's cap.
        ($this->fromServer)('four@example.com', visitor: '203.0.113.2')->assertStatus(202);
        ($this->fromServer)('five@example.com', visitor: '203.0.113.3')->assertStatus(429);
    });

    it('never takes a forwarded address from guests, who could send any', function () {
        config()->set(ConfigKey::ClientIpHeader->value, 'X-Waitlist-Client-Ip');

        $guest = fn (string $email, string $forwarded) => $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->postJson('/waitlist', ['email' => $email, 'purposes' => waitlistConsent()], ['X-Waitlist-Client-Ip' => $forwarded]);

        $guest('one@example.com', '203.0.113.1')->assertStatus(202);
        $guest('two@example.com', '203.0.113.2')->assertStatus(202);
        $guest('three@example.com', '203.0.113.3')->assertStatus(429);
    });

    it('bounds the token requests of a server as one source, or per visitor it forwards', function () {
        config()->set(ConfigKey::LinksPerIpPerMinute->value, 2);

        $confirm = fn (string $token, string $ip, ?string $visitor = null) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->getJson("/waitlist/confirm/{$token}", array_filter(['X-Server' => 'site', 'X-Waitlist-Client-Ip' => $visitor]));

        $confirm('guess1', '192.0.2.1')->assertNotFound();
        $confirm('guess2', '192.0.2.2')->assertNotFound();
        $confirm('guess3', '192.0.2.3')->assertStatus(429);

        config()->set(ConfigKey::ClientIpHeader->value, 'X-Waitlist-Client-Ip');

        $confirm('guess4', '192.0.2.1', '203.0.113.1')->assertNotFound();
        $confirm('guess5', '192.0.2.1', '203.0.113.1')->assertNotFound();
        $confirm('guess6', '192.0.2.1', '203.0.113.1')->assertStatus(429);
        $confirm('guess7', '192.0.2.1', '203.0.113.2')->assertNotFound();
    });

    it('records the forwarded address as the visitor\'s, when addresses are stored', function () {
        config()->set(ConfigKey::StoreIp->value, true);
        config()->set(ConfigKey::ClientIpHeader->value, 'X-Waitlist-Client-Ip');

        ($this->fromServer)('visitor@example.com', visitor: '203.0.113.7')->assertStatus(202);
        Auth::forgetGuards();
        ($this->subscribe)('10.0.0.5', 'guest@example.com')->assertStatus(202);

        expect(WaitlistActivity::query()->orderBy('id')->get()->pluck('ip')->unique()->values()->all())->toBe(['203.0.113.7', '10.0.0.5']);
    });
});
