<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Actions\GrantConsent;
use Taldres\Waitlist\Actions\SyncPurposes;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\ConsentGranted;
use Taldres\Waitlist\Events\ConsentWithdrawn;
use Taldres\Waitlist\Events\EntryUnsubscribed;
use Taldres\Waitlist\Exceptions\InvalidTokenException;
use Taldres\Waitlist\Exceptions\UnknownPurposeException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Support\PurposeRegistry;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\Support\SubscriptionLifecycle;

function confirmedWith(array $purposes = []): array
{
    $tokens = subscribeAndCapture('beta', 'user@example.com', [...waitlistConsent(), ...$purposes]);
    Waitlist::confirm($tokens['confirm']);

    return $tokens;
}

it('grants an optional purpose from the manage link', function () {
    $tokens = confirmedWith();

    Event::fake([ConsentGranted::class]);

    $entry = Waitlist::grantConsent(manageTokenFor($tokens['entry']), 'newsletter', '2026-10');

    expect($entry->purposes)->toBe(['waitlist', 'newsletter'])
        ->and(WaitlistActivity::query()->where('type', ActivityType::ConsentGranted)->sole()->purpose)->toBe('newsletter');

    Event::assertDispatched(ConsentGranted::class, fn (ConsentGranted $event) => $event->consent->purpose === 'newsletter'
        && $event->entry->is($entry));

    assertWaitlistInvariants();
});

it('grants once, however often it is asked', function () {
    $tokens = confirmedWith(['newsletter' => '2026-10']);

    Event::fake([ConsentGranted::class]);

    Waitlist::grantConsent(manageTokenFor($tokens['entry']), 'newsletter', '2026-10');

    expect(WaitlistConsent::query()->where('purpose', 'newsletter')->count())->toBe(1);
    Event::assertNotDispatched(ConsentGranted::class);
});

it('records a grant on a pending cycle, in force once confirmed', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');

    Waitlist::grantConsent(manageTokenFor($tokens['entry']), 'newsletter', '2026-10');

    expect($tokens['entry']->fresh()->purposes)->toBe([]);

    Waitlist::confirm($tokens['confirm']);

    expect($tokens['entry']->fresh()->purposes)->toBe(['waitlist', 'newsletter']);
});

it('grants nothing once the address has left', function () {
    $tokens = confirmedWith();
    Waitlist::unsubscribe($tokens['unsubscribe']);

    Event::fake([ConsentGranted::class]);

    Waitlist::grantConsent(manageTokenFor($tokens['entry']), 'newsletter', '2026-10');

    expect(WaitlistConsent::query()->where('purpose', 'newsletter')->exists())->toBeFalse();
    Event::assertNotDispatched(ConsentGranted::class);
});

it('validates what is granted', function (string $purpose, string $version) {
    $tokens = confirmedWith();

    Waitlist::grantConsent(manageTokenFor($tokens['entry']), $purpose, $version);
})->with([
    'unknown purpose' => ['marketing', '2026-10'],
    'unknown version' => ['newsletter', '2025-01'],
])->throws(UnknownPurposeException::class);

it('rejects an unknown manage token', function () {
    Waitlist::grantConsent('nope', 'newsletter', '2026-10');
})->throws(InvalidTokenException::class);

it('withdraws an optional purpose and keeps the cycle running', function () {
    $tokens = confirmedWith(['newsletter' => '2026-10']);

    Event::fake([ConsentWithdrawn::class, EntryUnsubscribed::class]);

    $entry = Waitlist::withdrawConsent($tokens['unsubscribe'], 'newsletter', new RequestContext('127.0.0.1'));

    expect($entry->status)->toBe(EntryStatus::Confirmed)
        ->and($entry->purposes)->toBe(['waitlist'])
        ->and(WaitlistActivity::query()->where('type', ActivityType::ConsentWithdrawn)->sole()->purpose)->toBe('newsletter');

    Event::assertDispatchedTimes(ConsentWithdrawn::class, 1);
    Event::assertNotDispatched(EntryUnsubscribed::class);

    Waitlist::withdrawConsent($tokens['unsubscribe'], 'newsletter');

    Event::assertDispatchedTimes(ConsentWithdrawn::class, 1);
    assertWaitlistInvariants();
});

it('ends the cycle when the primary purpose is withdrawn', function () {
    $tokens = confirmedWith(['newsletter' => '2026-10']);

    Event::fake([ConsentWithdrawn::class, EntryUnsubscribed::class]);

    $entry = Waitlist::withdrawConsent($tokens['unsubscribe'], 'waitlist');

    expect($entry->status)->toBe(EntryStatus::Unsubscribed)
        ->and($entry->purposes)->toBe([]);

    Event::assertDispatchedTimes(EntryUnsubscribed::class, 1);
    Event::assertNotDispatched(ConsentWithdrawn::class);
    assertWaitlistInvariants();
});

it('withdraws a purpose that was removed from the definition since', function () {
    $tokens = confirmedWith(['newsletter' => '2026-10']);

    Waitlist::define(function (ProjectDefinition $project): void {
        $project->purpose('waitlist', ['2026-10' => 'Email me when early access opens.']);
        $project->list('*', purpose: 'waitlist');
    });

    expect(Waitlist::withdrawConsent($tokens['unsubscribe'], 'newsletter')->purposes)->toBe(['waitlist']);
});

it('records a fresh grant after a withdrawal and keeps the old one as evidence', function () {
    $tokens = confirmedWith(['newsletter' => '2026-10']);

    Waitlist::withdrawConsent($tokens['unsubscribe'], 'newsletter');
    $entry = Waitlist::grantConsent(manageTokenFor($tokens['entry']), 'newsletter', '2026-10');

    $rows = WaitlistConsent::query()->where('purpose', 'newsletter')->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->withdrawn_at)->not->toBeNull()
        ->and($rows[1]->withdrawn_at)->toBeNull()
        ->and($entry->purposes)->toBe(['waitlist', 'newsletter']);

    assertWaitlistInvariants();
});

it('withdraws a purpose for the address on every list of the project', function () {
    Waitlist::define('acme', function (ProjectDefinition $project): void {
        $project->purpose('launch', ['v1' => 'Tell me about the launch.']);
        $project->purpose('newsletter', ['v1' => 'Acme news.']);
        $project->list('beta', purpose: 'launch')->optional('newsletter');
    });

    $beta = confirmedWith(['newsletter' => '2026-10']);
    $launch = subscribeAndCapture('launch', 'USER@example.com', [...waitlistConsent(), 'newsletter' => '2026-10']);
    Waitlist::confirm($launch['confirm']);
    $acme = subscribeAndCapture('beta', 'user@example.com', ['launch' => 'v1', 'newsletter' => 'v1'], project: 'acme');
    Waitlist::confirm($acme['confirm']);
    $other = subscribeAndCapture('launch', 'other@example.com', [...waitlistConsent(), 'newsletter' => '2026-10']);
    Waitlist::confirm($other['confirm']);

    Event::fake([ConsentWithdrawn::class]);

    Waitlist::withdrawConsent($beta['unsubscribe'], 'newsletter');

    expect($beta['entry']->fresh()->purposes)->toBe(['waitlist'])
        ->and($launch['entry']->fresh()->purposes)->toBe(['waitlist'])
        ->and($acme['entry']->fresh()->purposes)->toBe(['launch', 'newsletter'])
        ->and($other['entry']->fresh()->purposes)->toBe(['waitlist', 'newsletter']);

    Event::assertDispatchedTimes(ConsentWithdrawn::class, 2);
    assertWaitlistInvariants();
});

it('leaves only the list a withdrawal of its primary purpose came from', function () {
    $beta = confirmedWith(['newsletter' => '2026-10']);
    $launch = subscribeAndCapture('launch', 'user@example.com', [...waitlistConsent(), 'newsletter' => '2026-10']);
    Waitlist::confirm($launch['confirm']);

    Waitlist::withdrawConsent($beta['unsubscribe'], 'waitlist');

    expect($beta['entry']->fresh()->status)->toBe(EntryStatus::Unsubscribed)
        ->and($launch['entry']->fresh()->purposes)->toBe(['waitlist', 'newsletter']);

    Waitlist::for('launch')->withdraw('user@example.com', 'waitlist');

    expect($launch['entry']->fresh()->status)->toBe(EntryStatus::Unsubscribed);
});

it('ends a list whose primary purpose is withdrawn as an optional one elsewhere', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->list('news', purpose: 'newsletter'));

    $beta = confirmedWith(['newsletter' => '2026-10']);
    $news = subscribeAndCapture('news', 'user@example.com', ['newsletter' => '2026-10']);
    Waitlist::confirm($news['confirm']);

    Waitlist::withdrawConsent($beta['unsubscribe'], 'newsletter');

    expect($beta['entry']->fresh()->purposes)->toBe(['waitlist'])
        ->and($news['entry']->fresh()->status)->toBe(EntryStatus::Unsubscribed);
});

it('leaves the other lists alone when the same primary-purpose link is used again', function () {
    $beta = confirmedWith();
    $launch = subscribeAndCapture('launch', 'user@example.com');
    Waitlist::confirm($launch['confirm']);

    Waitlist::withdrawConsent($beta['unsubscribe'], 'waitlist');
    Waitlist::withdrawConsent($beta['unsubscribe'], 'waitlist');

    expect($beta['entry']->fresh()->status)->toBe(EntryStatus::Unsubscribed)
        ->and($launch['entry']->fresh()->status)->toBe(EntryStatus::Confirmed);
});

it('withdraws an add-on elsewhere when someone leaves the list that exists for it', function () {
    defineDefaultProject(function (ProjectDefinition $project): void {
        $project->list('news', purpose: 'newsletter');
        $project->list('digest', purpose: 'newsletter');
    });

    $beta = confirmedWith(['newsletter' => '2026-10']);
    $news = subscribeAndCapture('news', 'user@example.com', ['newsletter' => '2026-10']);
    Waitlist::confirm($news['confirm']);

    Waitlist::withdrawConsent($news['unsubscribe'], 'newsletter');

    expect($news['entry']->fresh()->status)->toBe(EntryStatus::Unsubscribed)
        ->and($beta['entry']->fresh()->purposes)->toBe(['waitlist'])
        ->and(Waitlist::recipients('newsletter')->all())->toBe([]);

    Waitlist::withdrawConsent($news['unsubscribe'], 'newsletter');

    expect($beta['entry']->fresh()->purposes)->toBe(['waitlist']);
});

it('leaves the lists that exist for an add-on when a withdrawal of it is repeated', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->list('news', purpose: 'newsletter'));

    $beta = confirmedWith(['newsletter' => '2026-10']);
    Waitlist::withdrawConsent($beta['unsubscribe'], 'newsletter');

    $news = subscribeAndCapture('news', 'user@example.com', ['newsletter' => '2026-10']);
    Waitlist::confirm($news['confirm']);

    Waitlist::withdrawConsent($beta['unsubscribe'], 'newsletter');

    expect($news['entry']->fresh()->status)->toBe(EntryStatus::Unsubscribed);
});

it('leaves only the one list on a plain unsubscribe', function () {
    $beta = confirmedWith(['newsletter' => '2026-10']);
    $launch = subscribeAndCapture('launch', 'user@example.com', [...waitlistConsent(), 'newsletter' => '2026-10']);
    Waitlist::confirm($launch['confirm']);

    Waitlist::unsubscribe($beta['unsubscribe']);

    expect($beta['entry']->fresh()->status)->toBe(EntryStatus::Unsubscribed)
        ->and($launch['entry']->fresh()->purposes)->toBe(['waitlist', 'newsletter']);
});

it('withdraws across the project when the preference page drops a purpose', function () {
    $beta = confirmedWith(['newsletter' => '2026-10']);
    $launch = subscribeAndCapture('launch', 'user@example.com', [...waitlistConsent(), 'newsletter' => '2026-10']);
    Waitlist::confirm($launch['confirm']);

    app(SyncPurposes::class)($beta['entry']->fresh(), waitlistConsent());

    expect($launch['entry']->fresh()->purposes)->toBe(['waitlist']);
});

it('withdraws by email for a request that came in another way', function () {
    confirmedWith(['newsletter' => '2026-10']);

    expect(Waitlist::for('beta')->withdraw('USER@example.com', 'newsletter')->purposes)->toBe(['waitlist'])
        ->and(Waitlist::for('beta')->withdraw('nobody@example.com', 'newsletter'))->toBeNull();
});

it('builds per-purpose unsubscribe links and one-click headers', function () {
    config()->set(ConfigKey::RoutesEnabled->value, true);
    require __DIR__.'/../../routes/waitlist.php';

    $tokens = confirmedWith(['newsletter' => '2026-10']);
    $entry = $tokens['entry'];

    expect(Waitlist::unsubscribeUrl($entry, 'newsletter'))->toEndWith("/waitlist/unsubscribe/{$tokens['unsubscribe']}?purpose=newsletter")
        ->and(Waitlist::listUnsubscribeHeaders($entry, 'newsletter')['List-Unsubscribe'])->toContain('?purpose=newsletter')
        ->and(Waitlist::listUnsubscribeHeaders($entry)['List-Unsubscribe'])->not->toContain('purpose');
});

it('lets a stale grant lose against an unsubscribe', function () {
    $tokens = confirmedWith();
    $stale = Waitlist::findByUnsubscribeToken($tokens['unsubscribe'])->currentSubscription;

    Waitlist::unsubscribe($tokens['unsubscribe']);

    Event::fake([ConsentGranted::class]);

    $wording = app(PurposeRegistry::class)->wording(app(PurposeRegistry::class)->policy('default', 'beta'), 'newsletter', '2026-10');

    expect(app(SubscriptionLifecycle::class)->grant($stale, $wording, RequestContext::none()))->toBeFalse();
    Event::assertNotDispatched(ConsentGranted::class);
    assertWaitlistInvariants();
});

it('grants once when two requests hold the same cycle', function () {
    $tokens = confirmedWith();
    $entry = Waitlist::findByUnsubscribeToken($tokens['unsubscribe']);

    Event::fake([ConsentGranted::class]);

    app(GrantConsent::class)->grant($entry, 'newsletter', '2026-10');
    app(GrantConsent::class)->grant($entry, 'newsletter', '2026-10');

    expect(WaitlistConsent::query()->where('purpose', 'newsletter')->count())->toBe(1);
    Event::assertDispatchedTimes(ConsentGranted::class, 1);
    assertWaitlistInvariants();
});

it('never withdraws the primary purpose as if it were optional', function () {
    $entry = subscribeAndCapture('beta', 'user@example.com', [...waitlistConsent(), 'newsletter' => '2026-10'])['entry'];
    $primary = $entry->currentSubscription->consents()->where('required', true)->sole();

    expect(app(SubscriptionLifecycle::class)->withdraw($primary, RequestContext::none()))->toBeFalse()
        ->and($primary->fresh()->withdrawn_at)->toBeNull();
});
