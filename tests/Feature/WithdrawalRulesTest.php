<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Events\ConsentWithdrawn;
use Taldres\Waitlist\Events\EntryUnsubscribed;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Tests\TestCase;

/*
 * The rule from docs/getting-started.md#7-withdraw-a-purpose: lists that share a
 * primary purpose are separate waitlists, an optional purpose is one consent for
 * the whole project, and leaving a list withdraws its primary purpose.
 */

/**
 * Replaces the project with the test purposes and exactly these lists.
 *
 * @param  array<string, array{purpose: string, optional?: list<string>}>  $lists
 */
function defineWithdrawalLists(array $lists, string $project = 'default'): void
{
    Waitlist::define($project, function (ProjectDefinition $definition) use ($lists): void {
        TestCase::defineTestPurposes($definition);

        foreach ($lists as $list => $policy) {
            $definition->list($list, purpose: $policy['purpose'])->optional(...($policy['optional'] ?? []));
        }
    });
}

/**
 * @param  array<string, array{purpose: string, optional?: list<string>}>  $lists
 */
function joinEveryList(array $lists, string $email, string $project = 'default'): void
{
    foreach ($lists as $list => $policy) {
        $purposes = [$policy['purpose'], ...($policy['optional'] ?? [])];

        Waitlist::project($project)->for($list)->add($email, array_fill_keys($purposes, '2026-10'));
    }
}

/**
 * @return array<string, list<string>> list => purposes in force, sorted
 */
function purposesOn(string $email, string $project = 'default'): array
{
    return Waitlist::project($project)->findByEmail($email)
        ->mapWithKeys(fn (WaitlistEntry $entry) => [$entry->list => collect($entry->purposes)->sort()->values()->all()])
        ->sortKeys()
        ->all();
}

function isMailedFor(string $purpose, string $email, string $project = 'default'): bool
{
    return Waitlist::project($project)->recipients($purpose)->map->email->contains($email);
}

/**
 * @return list<string>
 */
function listsDispatchedFor(string $event): array
{
    return Event::dispatched($event)->map(fn (array $arguments) => $arguments[0]->entry->list)->sort()->values()->all();
}

/**
 * The rule, written down apart from the implementation.
 *
 * @param  array<string, array{purpose: string, optional?: list<string>}>  $lists
 * @return array<string, list<string>>
 */
function expectedAfterWithdrawing(array $lists, string $origin, string $purpose): array
{
    $primaryOnOrigin = $lists[$origin]['purpose'] === $purpose;
    $expected = [];

    foreach ($lists as $list => $policy) {
        $purposes = [$policy['purpose'], ...($policy['optional'] ?? [])];

        $kept = match (true) {
            ! in_array($purpose, $purposes, true) => $purposes,
            $policy['purpose'] !== $purpose => array_diff($purposes, [$purpose]),
            $list === $origin || ! $primaryOnOrigin => [],
            default => $purposes,
        };

        sort($kept);
        $expected[$list] = $kept;
    }

    ksort($expected);

    return $expected;
}

beforeEach(function () {
    config()->set(ConfigKey::DoubleOptIn->value, false);
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::RoutesMiddleware->value, []);
    require __DIR__.'/../../routes/waitlist.php';

    $this->lists = [
        'beta' => ['purpose' => 'waitlist', 'optional' => ['newsletter']],
        'launch' => ['purpose' => 'waitlist', 'optional' => ['newsletter']],
        'news' => ['purpose' => 'newsletter'],
    ];
    defineWithdrawalLists($this->lists);
});

describe('the example in the docs', function () {
    beforeEach(function () {
        defineWithdrawalLists($this->lists, 'acme');

        foreach (['jane@example.com', 'bob@example.com'] as $email) {
            joinEveryList($this->lists, $email);
            joinEveryList($this->lists, $email, 'acme');
        }

        $this->before = purposesOn('jane@example.com');
    });

    it('does what the table says, from every way in, and nothing more on a second click', function (string $origin, ?string $purpose, array $expected, string $way) {
        $email = 'jane@example.com';
        $entry = Waitlist::for($origin)->find($email);
        $token = Waitlist::unsubscribeToken($entry)->token;
        $manage = manageTokenFor($entry);
        $primary = $this->lists[$origin]['purpose'];

        $click = match ($way) {
            'mail link' => fn () => $purpose === null ? Waitlist::unsubscribe($token) : Waitlist::withdrawConsent($token, $purpose),
            'one-click' => fn () => $this->post("/waitlist/unsubscribe/{$token}".($purpose === null ? '' : "?purpose={$purpose}"), ['List-Unsubscribe' => 'One-Click'])->assertOk(),
            'by address' => fn () => $purpose === null ? Waitlist::for($origin)->unsubscribe($email) : Waitlist::for($origin)->withdraw($email, $purpose),
            'preference page' => fn () => in_array($purpose, [null, $primary], true)
                ? $this->postJson("/waitlist/manage/{$manage}/unsubscribe")->assertOk()
                : $this->putJson("/waitlist/manage/{$manage}/purposes", ['purposes' => [$primary => '2026-10']])->assertOk(),
        };

        Event::fake([ConsentWithdrawn::class, EntryUnsubscribed::class]);
        $click();

        expect(purposesOn($email))->toBe($expected)
            ->and(listsDispatchedFor(ConsentWithdrawn::class))->toBe(array_keys(array_filter($expected, fn (array $kept, string $list) => $kept !== [] && $kept !== $this->before[$list], ARRAY_FILTER_USE_BOTH)))
            ->and(listsDispatchedFor(EntryUnsubscribed::class))->toBe(array_keys(array_filter($expected, fn (array $kept) => $kept === [])));

        Event::fake([ConsentWithdrawn::class, EntryUnsubscribed::class]);
        $click();

        Event::assertNothingDispatched();

        expect(purposesOn($email))->toBe($expected)
            ->and(isMailedFor('newsletter', $email))->toBe(in_array('newsletter', array_merge(...array_values($expected)), true))
            ->and(purposesOn('bob@example.com'))->toBe($this->before)
            ->and(purposesOn($email, 'acme'))->toBe($this->before)
            ->and(isMailedFor('newsletter', $email, 'acme'))->toBeTrue();
    })->with([
        'the newsletter link in a beta mail' => ['beta', 'newsletter', ['beta' => ['waitlist'], 'launch' => ['waitlist'], 'news' => []]],
        'the unsubscribe link in a news mail' => ['news', null, ['beta' => ['waitlist'], 'launch' => ['waitlist'], 'news' => []]],
        'the newsletter link in a news mail' => ['news', 'newsletter', ['beta' => ['waitlist'], 'launch' => ['waitlist'], 'news' => []]],
        'the unsubscribe link in a beta mail' => ['beta', null, ['beta' => [], 'launch' => ['newsletter', 'waitlist'], 'news' => ['newsletter']]],
        'the waitlist link in a beta mail' => ['beta', 'waitlist', ['beta' => [], 'launch' => ['newsletter', 'waitlist'], 'news' => ['newsletter']]],
    ])->with(['mail link', 'one-click', 'by address', 'preference page']);
});

it('reaches a list still waiting for confirmation, so confirming it later brings nothing back', function () {
    config()->set(ConfigKey::DoubleOptIn->value, true);

    Waitlist::confirm(subscribeAndCapture('news', 'jane@example.com', ['newsletter' => '2026-10'])['confirm']);
    $pending = subscribeAndCapture('beta', 'jane@example.com', ['waitlist' => '2026-10', 'newsletter' => '2026-10']);

    Waitlist::for('news')->unsubscribe('jane@example.com');
    Waitlist::confirm($pending['confirm']);

    expect(purposesOn('jane@example.com'))->toBe(['beta' => ['waitlist'], 'news' => []])
        ->and(isMailedFor('newsletter', 'jane@example.com'))->toBeFalse()
        ->and(Waitlist::for('beta')->recipients()->map->email->all())->toBe(['jane@example.com']);
});

it('leaves a list that was already left alone', function () {
    joinEveryList($this->lists, 'jane@example.com');
    Waitlist::for('beta')->unsubscribe('jane@example.com');

    Event::fake([ConsentWithdrawn::class, EntryUnsubscribed::class]);
    Waitlist::for('launch')->withdraw('jane@example.com', 'newsletter');

    expect(purposesOn('jane@example.com'))->toBe(['beta' => [], 'launch' => ['waitlist'], 'news' => []])
        ->and(listsDispatchedFor(ConsentWithdrawn::class))->toBe(['launch'])
        ->and(listsDispatchedFor(EntryUnsubscribed::class))->toBe(['news']);
});

it('withdraws on every list before a failing listener hears of it', function () {
    joinEveryList($this->lists, 'jane@example.com');

    Event::listen(ConsentWithdrawn::class, fn () => throw new RuntimeException('Mail provider down.'));

    expect(fn () => Waitlist::for('beta')->withdraw('jane@example.com', 'newsletter'))
        ->toThrow(RuntimeException::class, 'Mail provider down.')
        ->and(purposesOn('jane@example.com'))->toBe(['beta' => ['waitlist'], 'launch' => ['waitlist'], 'news' => []])
        ->and(isMailedFor('newsletter', 'jane@example.com'))->toBeFalse();
});

it('decides from what was agreed to, not from the catalog of today', function () {
    joinEveryList($this->lists, 'jane@example.com');

    $token = Waitlist::unsubscribeToken(Waitlist::for('beta')->find('jane@example.com'))->token;

    Waitlist::define(function (ProjectDefinition $project): void {
        $project->purpose('waitlist', [
            '2026-09' => 'Earlier wording.',
            '2026-10' => 'Email me when early access opens.',
        ]);
        $project->purpose('early-access', ['2026-11' => 'Email me when early access opens.']);

        $project->list('beta', purpose: 'early-access');
        $project->list('launch', purpose: 'waitlist');
    });

    Waitlist::withdrawConsent($token, 'newsletter');

    expect(purposesOn('jane@example.com'))->toBe(['beta' => ['waitlist'], 'launch' => ['waitlist'], 'news' => []]);

    Waitlist::withdrawConsent($token, 'waitlist');

    expect(purposesOn('jane@example.com'))->toBe(['beta' => [], 'launch' => ['waitlist'], 'news' => []]);
});

it('takes a new signup after a withdrawal as new consent, on that list only', function () {
    joinEveryList($this->lists, 'jane@example.com');
    Waitlist::for('beta')->withdraw('jane@example.com', 'newsletter');

    Waitlist::for('news')->add('jane@example.com', ['newsletter' => '2026-10']);

    expect(purposesOn('jane@example.com'))->toBe(['beta' => ['waitlist'], 'launch' => ['waitlist'], 'news' => ['newsletter']])
        ->and(isMailedFor('newsletter', 'jane@example.com'))->toBeTrue();
});

describe('every combination of lists', function () {
    it('follows the rule, changes nothing on a repeat and touches nobody else', function (array $first, array $second, array $third, string $action) {
        $lists = ['l1' => $first, 'l2' => $second, 'l3' => $third];
        defineWithdrawalLists($lists);

        joinEveryList($lists, 'jane@example.com');
        joinEveryList($lists, 'bob@example.com');
        $bob = purposesOn('bob@example.com');

        $expected = expectedAfterWithdrawing($lists, 'l1', $action === 'unsubscribe' ? $first['purpose'] : $action);
        $token = Waitlist::unsubscribeToken(Waitlist::for('l1')->find('jane@example.com'))->token;
        $click = fn () => $action === 'unsubscribe' ? Waitlist::unsubscribe($token) : Waitlist::withdrawConsent($token, $action);

        $click();

        expect(purposesOn('jane@example.com'))->toBe($expected);

        $click();

        expect(purposesOn('jane@example.com'))->toBe($expected)
            ->and(purposesOn('bob@example.com'))->toBe($bob);

        foreach (['waitlist', 'newsletter'] as $purpose) {
            expect(isMailedFor($purpose, 'jane@example.com'))->toBe(in_array($purpose, array_merge(...array_values($expected)), true));
        }

        assertWaitlistInvariants();
    })->with([
        'l1: newsletter list' => [['purpose' => 'newsletter']],
        'l1: newsletter list, waitlist add-on' => [['purpose' => 'newsletter', 'optional' => ['waitlist']]],
        'l1: waitlist' => [['purpose' => 'waitlist']],
        'l1: waitlist, newsletter add-on' => [['purpose' => 'waitlist', 'optional' => ['newsletter']]],
    ])->with([
        'l2: newsletter list' => [['purpose' => 'newsletter']],
        'l2: newsletter list, waitlist add-on' => [['purpose' => 'newsletter', 'optional' => ['waitlist']]],
        'l2: waitlist' => [['purpose' => 'waitlist']],
        'l2: waitlist, newsletter add-on' => [['purpose' => 'waitlist', 'optional' => ['newsletter']]],
    ])->with([
        'l3: newsletter list' => [['purpose' => 'newsletter']],
        'l3: newsletter list, waitlist add-on' => [['purpose' => 'newsletter', 'optional' => ['waitlist']]],
        'l3: waitlist' => [['purpose' => 'waitlist']],
        'l3: waitlist, newsletter add-on' => [['purpose' => 'waitlist', 'optional' => ['newsletter']]],
    ])->with([
        'withdraw the newsletter' => ['newsletter'],
        'withdraw the waitlist' => ['waitlist'],
        'plain unsubscribe' => ['unsubscribe'],
    ]);
});
