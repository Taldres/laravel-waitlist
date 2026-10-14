<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Taldres\Waitlist\Actions\PruneEntries;
use Taldres\Waitlist\Config\TimestampRange;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EndReason;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Enums\SubscribeOutcome;
use Taldres\Waitlist\Events\EntryConfirmed;
use Taldres\Waitlist\Events\EntryForgotten;
use Taldres\Waitlist\Events\ReconfirmationRequested;
use Taldres\Waitlist\Events\SubscriptionLapsed;
use Taldres\Waitlist\Exceptions\ExpiredTokenException;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Exceptions\InvalidTokenException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Tests\TestCase;

/**
 * A confirmed address, with the tokens of its signup.
 *
 * @return array{entry: WaitlistEntry, confirm: string|null, unsubscribe: string}
 */
function confirmedAddress(string $email = 'user@example.com', string $project = 'default'): array
{
    $tokens = subscribeAndCapture('beta', $email, project: $project);
    Waitlist::confirm($tokens['confirm']);

    return $tokens;
}

/**
 * The requests to confirm again that the next calls fire.
 *
 * @return ArrayObject<int, ReconfirmationRequested>
 */
function captureReconfirmations(): ArrayObject
{
    $requests = new ArrayObject;

    Event::listen(ReconfirmationRequested::class, function (ReconfirmationRequested $event) use ($requests): void {
        $requests->append($event);
    });

    return $requests;
}

function prune(): void
{
    expect(Artisan::call('waitlist:prune'))->toBe(0);
}

beforeEach(function () {
    config()->set(ConfigKey::RetentionReconfirmAfterDays->value, 365);
});

describe('asking', function () {
    it('asks nobody by default', function () {
        config()->set(ConfigKey::RetentionReconfirmAfterDays->value, null);
        confirmedAddress();
        $requests = captureReconfirmations();

        $this->travel(3000)->days();
        prune();

        expect($requests)->toHaveCount(0)
            ->and(WaitlistEntry::query()->sole()->status)->toBe(EntryStatus::Confirmed)
            ->and(WaitlistConfig::reconfirmAfterDays())->toBeNull()
            ->and(WaitlistConfig::reconfirmWithinDays())->toBe(30);
    });

    it('asks a confirmed address once its period has passed, with a confirm link that works until the deadline', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(confirm: 'https://app.test/confirm/{token}'));
        $tokens = confirmedAddress();
        $requests = captureReconfirmations();

        $this->travel(364)->days();
        prune();
        expect($requests)->toHaveCount(0);

        $this->travel(2)->days();
        $this->artisan('waitlist:prune')->expectsOutputToContain('asked 1 confirmed to confirm again, ended 0 that did not answer')->assertSuccessful();

        $request = $requests[0];
        $cycle = WaitlistEntry::query()->sole()->latestSubscription;

        expect($requests)->toHaveCount(1)
            ->and($request->confirmUrl)->toBe("https://app.test/confirm/{$request->confirmToken}")
            ->and($request->unsubscribeToken)->toBe($tokens['unsubscribe'])
            ->and($request->answerBy->toDateTimeString())->toBe(now()->addDays(30)->toDateTimeString())
            ->and($request->confirmToken)->not->toBe($tokens['confirm'])
            ->and($cycle->awaitsReconfirmation())->toBeTrue()
            ->and($cycle->confirm_token_expires_at->toDateTimeString())->toBe($request->answerBy->toDateTimeString())
            ->and(activityTypes())->toContain(ActivityType::ReconfirmationRequested);

        assertWaitlistInvariants();
    });

    it('asks once per period, however often pruning runs', function () {
        confirmedAddress();
        $requests = captureReconfirmations();

        $this->travel(400)->days();
        prune();
        prune();
        $this->travel(10)->days();
        prune();

        expect($requests)->toHaveCount(1)
            ->and(WaitlistActivity::query()->where('type', ActivityType::ReconfirmationRequested)->count())->toBe(1);
    });

    it('never asks an address that has not confirmed, or one that left', function () {
        config()->set(ConfigKey::RetentionPendingDays->value, null);
        config()->set(ConfigKey::RetentionUnsubscribedDays->value, null);
        subscribeAndCapture('beta', 'pending@example.com');
        Waitlist::unsubscribe(confirmedAddress('left@example.com')['unsubscribe']);
        $requests = captureReconfirmations();

        $this->travel(400)->days();
        prune();

        expect($requests)->toHaveCount(0);
    });

    it('counts from the address\'s own confirmation, not from the signup of an earlier cycle', function () {
        $tokens = confirmedAddress();
        Waitlist::unsubscribe($tokens['unsubscribe']);
        $this->travel(300)->days();
        Waitlist::confirm(subscribeAndCapture('beta', 'user@example.com')['confirm']);
        $requests = captureReconfirmations();

        $this->travel(100)->days();
        prune();

        expect($requests)->toHaveCount(0);
    });

    it('applies a project\'s own period to that project only', function () {
        config()->set(ConfigKey::RetentionReconfirmAfterDays->value, null);
        Waitlist::define('acme', fn (ProjectDefinition $project) => TestCase::defineTestProject($project->retention(reconfirmAfterDays: 30, reconfirmWithinDays: 7)));
        confirmedAddress('acme@example.com', 'acme');
        confirmedAddress('default@example.com');
        $requests = captureReconfirmations();

        $this->travel(31)->days();
        prune();

        expect($requests)->toHaveCount(1)
            ->and($requests[0]->entry->project)->toBe('acme')
            ->and($requests[0]->answerBy->toDateTimeString())->toBe(now()->addDays(7)->toDateTimeString());
    });

    it('asks with the configured deadline where a project only sets when to ask', function () {
        config()->set(ConfigKey::RetentionReconfirmWithinDays->value, 14);
        Waitlist::define('acme', fn (ProjectDefinition $project) => TestCase::defineTestProject($project->retention(reconfirmAfterDays: 30)));
        confirmedAddress('acme@example.com', 'acme');
        $requests = captureReconfirmations();

        $this->travel(31)->days();
        app(PruneEntries::class)(project: 'acme');

        expect($requests[0]->answerBy->toDateTimeString())->toBe(now()->addDays(14)->toDateTimeString());
    });

    it('asks nobody when the deadline does not read, and applies the other periods', function () {
        config()->set(ConfigKey::RetentionReconfirmWithinDays->value, 'soon');
        config()->set(ConfigKey::RetentionPendingDays->value, 30);
        confirmedAddress();
        subscribeAndCapture('beta', 'pending@example.com');
        $requests = captureReconfirmations();

        $this->travel(400)->days();

        expect(fn () => app(PruneEntries::class)())->toThrow(InvalidConfigurationException::class, ConfigKey::RetentionReconfirmWithinDays->value)
            ->and($requests)->toHaveCount(0)
            ->and(WaitlistEntry::query()->pluck('email')->all())->toBe(['user@example.com']);
    });

    it('refuses periods that no timestamp holds', function (ConfigKey $key, mixed $value) {
        config()->set($key->value, $value);

        expect(fn () => checkWaitlistConfig())->toThrow(InvalidConfigurationException::class, $key->value);
    })->with([
        'no days before asking' => [ConfigKey::RetentionReconfirmAfterDays, 0],
        'before 1970' => [ConfigKey::RetentionReconfirmAfterDays, TimestampRange::daysBack() + 1],
        'no days to answer' => [ConfigKey::RetentionReconfirmWithinDays, 0],
        'a deadline after 2038' => [ConfigKey::RetentionReconfirmWithinDays, TimestampRange::daysAhead() + 1],
        'never answering' => [ConfigKey::RetentionReconfirmWithinDays, null],
    ]);

    it('refuses a project period that no timestamp holds', function () {
        expect(fn () => (new ProjectDefinition('shop'))->retention(reconfirmWithinDays: TimestampRange::daysAhead() + 1))
            ->toThrow(InvalidConfigurationException::class, "A project's reconfirm within days must be between 1 and");
    });
});

describe('answering', function () {
    beforeEach(function () {
        confirmedAddress();
        $this->requests = captureReconfirmations();
        $this->travel(400)->days();
        prune();
        $this->token = $this->requests[0]->confirmToken;
    });

    it('keeps the address and starts the period anew, announcing nothing', function () {
        Event::fake([EntryConfirmed::class]);

        $entry = Waitlist::confirm($this->token);
        $cycle = $entry->latestSubscription;

        expect($entry->status)->toBe(EntryStatus::Confirmed)
            ->and($cycle->awaitsReconfirmation())->toBeFalse()
            ->and($cycle->reconfirmed_at?->toDateTimeString())->toBe(now()->toDateTimeString())
            ->and(activityTypes())->toContain(ActivityType::Reconfirmed);

        Event::assertNotDispatched(EntryConfirmed::class);
        assertWaitlistInvariants();

        $this->travel(364)->days();
        prune();
        expect($this->requests)->toHaveCount(1);

        $this->travel(2)->days();
        prune();
        expect($this->requests)->toHaveCount(2);
    });

    it('reports success on a second click and changes nothing', function () {
        Waitlist::confirm($this->token);

        expect(Waitlist::confirm($this->token)->status)->toBe(EntryStatus::Confirmed)
            ->and(WaitlistActivity::query()->where('type', ActivityType::Reconfirmed)->count())->toBe(1);
    });

    it('turns the link of the signup mail into an invalid one', function () {
        $first = WaitlistActivity::query()->count();

        expect(fn () => Waitlist::confirm('the-original-link'))->toThrow(InvalidTokenException::class)
            ->and(WaitlistActivity::query()->count())->toBe($first);
    });

    it('refuses an answer after the deadline, before and after the address lapsed', function () {
        $this->travel(31)->days();

        expect(fn () => Waitlist::confirm($this->token))->toThrow(ExpiredTokenException::class);

        prune();

        expect(fn () => Waitlist::confirm($this->token))->toThrow(InvalidTokenException::class)
            ->and(WaitlistEntry::query()->sole()->status)->toBe(EntryStatus::Unsubscribed);
    });

    it('does not answer by signing up again, which mails the request once more instead', function () {
        $this->travel(10)->minutes();

        expect(Waitlist::subscribe('beta', 'user@example.com', waitlistConsent())->outcome)->toBe(SubscribeOutcome::ConfirmationResent)
            ->and(WaitlistEntry::query()->sole()->latestSubscription->awaitsReconfirmation())->toBeTrue()
            ->and($this->requests)->toHaveCount(2)
            ->and(Waitlist::subscribe('beta', 'user@example.com', waitlistConsent())->outcome)->toBe(SubscribeOutcome::ResendSuppressed);

        assertWaitlistInvariants();
    });

    it('mails the request once more on a resend, with a fresh link and the same deadline, under the cooldown', function () {
        expect(Waitlist::resendConfirmation('beta', 'user@example.com'))->toBeNull()
            ->and($this->requests)->toHaveCount(1);

        $this->travel(10)->minutes();

        expect(Waitlist::resendConfirmation('beta', 'user@example.com'))->not->toBeNull()
            ->and($this->requests)->toHaveCount(2)
            ->and($this->requests[1]->confirmToken)->not->toBe($this->token)
            ->and($this->requests[1]->answerBy->toDateTimeString())->toBe($this->requests[0]->answerBy->toDateTimeString())
            ->and(fn () => Waitlist::confirm($this->token))->toThrow(InvalidTokenException::class)
            ->and(Waitlist::confirm($this->requests[1]->confirmToken)->latestSubscription->awaitsReconfirmation())->toBeFalse();

        assertWaitlistInvariants();
    });

    it('caps the resends of a request like those of a confirmation', function () {
        config()->set(ConfigKey::MaxConfirmations->value, 2);

        foreach ([true, false] as $sent) {
            $this->travel(10)->minutes();
            expect(Waitlist::resendConfirmation('beta', 'user@example.com') !== null)->toBe($sent);
        }

        expect($this->requests)->toHaveCount(2);
    });

    it('mails nothing once the deadline has passed', function () {
        $this->travel(31)->days();

        expect(Waitlist::resendConfirmation('beta', 'user@example.com'))->toBeNull()
            ->and($this->requests)->toHaveCount(1);
    });

    it('leaves the unsubscribe link working, and an unsubscribe makes the request\'s link invalid', function () {
        $entry = Waitlist::unsubscribe($this->requests[0]->unsubscribeToken);

        expect($entry->status)->toBe(EntryStatus::Unsubscribed)
            ->and(fn () => Waitlist::confirm($this->token))->toThrow(InvalidTokenException::class);

        $this->travel(31)->days();
        Event::fake([SubscriptionLapsed::class]);
        prune();

        Event::assertNotDispatched(SubscriptionLapsed::class);
        expect(WaitlistEntry::query()->sole()->latestSubscription->end_reason)->toBe(EndReason::Unsubscribed);
        assertWaitlistInvariants();
    });

    it('leaves the preference page working while the request is out', function () {
        $entry = WaitlistEntry::query()->sole();

        expect(Waitlist::findByManageToken(manageTokenFor($entry))?->is($entry))->toBeTrue()
            ->and($entry->hasConsentFor('waitlist'))->toBeTrue()
            ->and(Waitlist::for('beta')->recipients()->count())->toBe(1);
    });

    it('lists the request and the answer in a copy of the data', function () {
        Waitlist::confirm($this->token);

        $cycle = Waitlist::personalData('user@example.com')->sole()->toArray()['subscriptions'][0];

        expect($cycle['reconfirmed_at'])->toBe(now()->format('Y-m-d H:i:s'))
            ->and($cycle['reconfirmation_requested_at'])->toBeNull();
    });
});

describe('not answering', function () {
    beforeEach(function () {
        confirmedAddress();
        $this->requests = captureReconfirmations();
        $this->travel(400)->days();
        prune();
    });

    it('ends the cycle as lapsed once the link expired, and keeps the address like one that left', function () {
        Event::fake([SubscriptionLapsed::class, EntryForgotten::class]);

        $this->travel(29)->days();
        prune();
        expect(WaitlistEntry::query()->sole()->status)->toBe(EntryStatus::Confirmed);

        $this->travel(2)->days();
        $this->artisan('waitlist:prune')->expectsOutputToContain('ended 1 that did not answer')->assertSuccessful();

        $entry = WaitlistEntry::query()->sole();
        $lapse = WaitlistActivity::query()->where('type', ActivityType::Lapsed)->sole();

        expect($entry->status)->toBe(EntryStatus::Unsubscribed)
            ->and($entry->latestSubscription->end_reason)->toBe(EndReason::Lapsed)
            ->and($entry->latestSubscription->confirm_token_hash)->toBeNull()
            ->and($entry->hasConsentFor('waitlist'))->toBeFalse()
            ->and($lapse->previous_status)->toBe(EntryStatus::Confirmed);

        Event::assertDispatchedTimes(SubscriptionLapsed::class, 1);
        Event::assertNotDispatched(EntryForgotten::class);
        assertWaitlistInvariants();

        $this->travel(1096)->days();
        prune();

        expect(WaitlistEntry::query()->count())->toBe(0);
    });

    it('counts the lapse as a departure from the confirmed', function () {
        $report = Waitlist::for('beta')->report();
        expect($report->confirmedOn(now()))->toBe(1);

        $this->travel(31)->days();
        prune();

        expect($report->confirmedOn(now()))->toBe(0)
            ->and($report->totals()->of(ActivityType::Lapsed, EntryStatus::Confirmed))->toBe(1)
            ->and($report->totals()->leftConfirmed())->toBe(1);
    });

    it('lets a request already out run its course once asking is turned off', function () {
        config()->set(ConfigKey::RetentionReconfirmAfterDays->value, null);

        $this->travel(31)->days();
        prune();

        expect(WaitlistEntry::query()->sole()->latestSubscription->end_reason)->toBe(EndReason::Lapsed);
    });

    it('lets the address sign up again afterwards', function () {
        $this->travel(31)->days();
        prune();

        expect(Waitlist::subscribe('beta', 'user@example.com', waitlistConsent())->outcome)->toBe(SubscribeOutcome::Resubscribed);
        assertWaitlistInvariants();
    });
});

describe('over HTTP', function () {
    beforeEach(function () {
        enableWaitlistRoutes();

        confirmedAddress();
        $this->requests = captureReconfirmations();
        $this->travel(400)->days();
        prune();
        $this->token = $this->requests[0]->confirmToken;
    });

    it('tells a waiting link from a used one on GET, and answers it on POST', function () {
        $answerBy = $this->requests[0]->answerBy->toJSON();

        $this->getJson("/waitlist/confirm/{$this->token}")->assertOk()
            ->assertJsonPath('data.status', EntryStatus::Confirmed->value)
            ->assertJsonPath('data.answer_by', $answerBy);
        expect(WaitlistEntry::query()->sole()->latestSubscription->awaitsReconfirmation())->toBeTrue();

        $this->postJson("/waitlist/confirm/{$this->token}")->assertOk()
            ->assertJsonPath('data.status', EntryStatus::Confirmed->value)
            ->assertJsonPath('data.answer_by', null);

        $this->getJson("/waitlist/confirm/{$this->token}")->assertOk()->assertJsonPath('data.answer_by', null);
        expect(WaitlistEntry::query()->sole()->latestSubscription->awaitsReconfirmation())->toBeFalse();
    });

    it('answers 410 once the deadline has passed', function () {
        $this->travel(31)->days();

        $this->getJson("/waitlist/confirm/{$this->token}")->assertStatus(410)->assertJsonPath('error', 'expired_token');
        $this->postJson("/waitlist/confirm/{$this->token}")->assertStatus(410)->assertJsonPath('error', 'expired_token');
    });
});

describe('waitlist:privacy', function () {
    function privacyOutput(): string
    {
        Artisan::call('waitlist:privacy');

        return Artisan::output();
    }

    it('says that nobody is asked again by default', function () {
        config()->set(ConfigKey::RetentionReconfirmAfterDays->value, null);

        expect(privacyOutput())->toContain('- Confirmed addresses: kept while subscribed, never asked to confirm again')
            ->toContain('- Active confirmed entries and remaining reporting rows have no automatic expiry');
    });

    it('describes the periods when they are set', function () {
        expect(privacyOutput())->toContain('- Confirmed addresses asked to confirm again 365 days after they confirmed or last answered; without an answer within 30 days they leave the list and are kept like addresses that left')
            ->not->toContain('- Active confirmed entries');
    });

    it('lists the periods a project promises', function () {
        config()->set(ConfigKey::RetentionReconfirmAfterDays->value, null);
        Waitlist::define('acme', fn (ProjectDefinition $project) => TestCase::defineTestProject($project->retention(reconfirmAfterDays: 30, reconfirmWithinDays: 7)));

        expect(privacyOutput())->toContain('- Confirmed addresses asked to confirm again in project [acme]: after 30 days; without an answer within 7 days they leave the list')
            ->not->toContain('- Active confirmed entries');
    });

    it('shows the configured deadline for a project that only says when to ask', function () {
        config()->set(ConfigKey::RetentionReconfirmAfterDays->value, null);
        config()->set(ConfigKey::RetentionReconfirmWithinDays->value, 14);
        Waitlist::define('acme', fn (ProjectDefinition $project) => TestCase::defineTestProject($project->retention(reconfirmAfterDays: 30)));

        expect(privacyOutput())->toContain('- Confirmed addresses asked to confirm again in project [acme]: after 30 days; without an answer within 14 days they leave the list');
    });

    it('prints no deadline for a project in which nobody is asked', function () {
        config()->set(ConfigKey::RetentionReconfirmAfterDays->value, null);
        Waitlist::define('acme', fn (ProjectDefinition $project) => TestCase::defineTestProject($project->retention(reconfirmWithinDays: 7)));

        expect(privacyOutput())->not->toContain('in project [acme]')
            ->toContain('- Active confirmed entries and remaining reporting rows have no automatic expiry');
    });

    it('names the listeners of the new events', function () {
        Event::listen(ReconfirmationRequested::class, 'App\\Listeners\\SendStillInterestedMail');

        expect(privacyOutput())->toContain('- ReconfirmationRequested: App\\Listeners\\SendStillInterestedMail');
    });
});

describe('before the migration ran', function () {
    beforeEach(function () {
        (include __DIR__.'/../../database/migrations/2026_10_14_000001_add_reconfirmation_to_waitlist_subscriptions_table.php')->down();
    });

    afterEach(fn () => Model::preventAccessingMissingAttributes(false));

    it('still applies the retention while nobody is to be asked', function () {
        config()->set(ConfigKey::RetentionReconfirmAfterDays->value, null);
        confirmedAddress('active@example.com');
        subscribeAndCapture('beta', 'pending@example.com');

        $this->travel(40)->days();
        prune();

        expect(WaitlistEntry::query()->pluck('email')->all())->toBe(['active@example.com']);
    });

    it('applies the retention first and then names the migration when someone is to be asked', function () {
        confirmedAddress('active@example.com');
        subscribeAndCapture('beta', 'pending@example.com');

        $this->travel(400)->days();

        expect(fn () => app(PruneEntries::class)())->toThrow(InvalidConfigurationException::class, 'vendor:publish --tag=waitlist-migrations')
            ->and(WaitlistEntry::query()->pluck('email')->all())->toBe(['active@example.com']);
    });

    it('confirms, answers and exports in strict mode', function () {
        Model::preventAccessingMissingAttributes();
        enableWaitlistRoutes();

        $tokens = subscribeAndCapture('beta', 'user@example.com');

        $this->postJson("/waitlist/confirm/{$tokens['confirm']}")->assertOk()->assertJsonPath('data.answer_by', null);

        expect(Waitlist::personalData('user@example.com')->sole()->toArray()['subscriptions'][0]['reconfirmed_at'])->toBeNull();
    });
});

describe('the migration', function () {
    it('adds its columns to a table of the first release, and runs again without harm', function () {
        $migration = include __DIR__.'/../../database/migrations/2026_10_14_000001_add_reconfirmation_to_waitlist_subscriptions_table.php';

        $migration->down();
        expect(Schema::hasColumns('waitlist_subscriptions', ['reconfirmation_requested_at', 'reconfirmed_at']))->toBeFalse();

        $migration->up();
        $migration->up();

        expect(Schema::hasColumns('waitlist_subscriptions', ['reconfirmation_requested_at', 'reconfirmed_at']))->toBeTrue();
    });
});
