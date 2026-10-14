<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Contracts\SpamProtector;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\ConfirmationOutcome;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\EntrySubscribed;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;
use Taldres\Waitlist\Support\ClosureSpamProtector;
use Taldres\Waitlist\Support\PurposeRegistry;
use Taldres\Waitlist\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Reads every setting and throws the first that does not read, the way the
 * step that needs it would.
 */
function checkWaitlistConfig(): void
{
    $problems = WaitlistConfig::problems();

    if ($problems !== []) {
        throw $problems[0];
    }
}

/**
 * Binds the contract to an implementation that fails the way the package's
 * own do when a setting or definition they read is wrong: with a
 * configuration error, wherever the contract is resolved.
 *
 * @param  class-string  $contract
 */
function bindUnreadable(string $contract): void
{
    app()->bind($contract, fn (): never => throw new InvalidConfigurationException("The {$contract} binding cannot read its configuration."));
}

/**
 * Redefines the default project: the test purposes, the "*" list unless
 * $lists is false, and whatever $extend adds or replaces.
 *
 * @param  (Closure(ProjectDefinition): mixed)|null  $extend
 */
function defineDefaultProject(?Closure $extend = null, bool $lists = true): void
{
    Waitlist::define(function (ProjectDefinition $project) use ($extend, $lists): void {
        $lists ? TestCase::defineTestProject($project) : TestCase::defineTestPurposes($project);

        if ($extend !== null) {
            $extend($project);
        }
    });
}

/**
 * Binds a protector that asks the closure, or the package's default again for
 * null.
 *
 * @param  (Closure(Request): bool)|null  $callback
 */
function useSpamCheck(?Closure $callback): void
{
    if ($callback === null) {
        app()->forgetInstance(SpamProtector::class);

        return;
    }

    app()->instance(SpamProtector::class, new ClosureSpamProtector($callback));
}

/**
 * The primary purpose of the test lists, at the given version.
 *
 * @return array<string, string>
 */
function waitlistConsent(string $version = '2026-10'): array
{
    return ['waitlist' => $version];
}

/**
 * Plain tokens exist only in the event payload, so they are captured from it.
 *
 * @param  array<string, string>|null  $purposes
 * @param  array<string, mixed>  $metadata
 * @return array{entry: WaitlistEntry, confirm: string|null, unsubscribe: string}
 */
function subscribeAndCapture(string $list, string $email, ?array $purposes = null, string $project = 'default', array $metadata = []): array
{
    $tokens = [];

    Event::listen(EntrySubscribed::class, function (EntrySubscribed $event) use (&$tokens): void {
        $tokens = ['confirm' => $event->confirmToken, 'unsubscribe' => $event->unsubscribeToken];
    });

    $result = Waitlist::project($project)->for($list)->add($email, $purposes ?? waitlistConsent(), $metadata);

    return ['entry' => $result->entry, ...$tokens];
}

/**
 * What a listener holds when it reports: the event. Its subscription is the
 * model the package refreshes, so it carries the latest request, as a queued
 * job's SerializesModels would load it.
 *
 * @return ArrayObject<int, EntrySubscribed>
 */
function captureRequests(): ArrayObject
{
    $requests = new ArrayObject;

    Event::listen(EntrySubscribed::class, function (EntrySubscribed $event) use ($requests): void {
        $requests->append($event);
    });

    return $requests;
}

/**
 * @param  list<string>  $previousKeys
 */
function rotateAppKey(array $previousKeys = [], ?string $key = null): void
{
    config()->set('app.key', $key ?? 'base64:'.base64_encode(random_bytes(32)));
    config()->set('app.previous_keys', $previousKeys);

    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

function manageTokenFor(WaitlistEntry $entry): string
{
    return Waitlist::manageLink($entry)->token;
}

/**
 * @return list<ActivityType>
 */
function activityTypes(?string $list = null): array
{
    return WaitlistActivity::query()
        ->when($list !== null, fn ($query) => $query->where('list', $list))
        ->orderBy('id')
        ->pluck('type')
        ->all();
}

/**
 * A violation is a real defect, not a flaky test.
 */
function assertWaitlistInvariants(): void
{
    foreach (WaitlistEntry::query()->with(['subscriptions.consents', 'activity'])->get() as $entry) {
        $cycles = $entry->subscriptions;
        $primary = app(PurposeRegistry::class)->policy($entry->project, $entry->list)->primary;

        $open = $cycles->filter(fn (WaitlistSubscription $cycle) => $cycle->active !== null);
        expect($open->count())->toBeLessThanOrEqual(1, "entry {$entry->id} has more than one open cycle");

        foreach ($cycles as $cycle) {
            expect($cycle->active !== null)->toBe($cycle->isOpen(), "cycle {$cycle->id}: active does not match ended_at");

            expect($cycle->consents->pluck('purpose')->all())->toContain($primary);

            foreach ($cycle->consents as $consent) {
                expect($consent->active !== null)->toBe(! $consent->isWithdrawn(), "consent {$consent->id}: active does not match withdrawn_at");
            }

            $live = $cycle->consents->reject(fn (WaitlistConsent $consent) => $consent->isWithdrawn());
            expect($live->pluck('purpose')->duplicates())->toBeEmpty("cycle {$cycle->id}: a purpose is granted twice");

            if ($cycle->confirmed_at !== null && $cycle->ended_at !== null) {
                expect($cycle->confirmed_at->lessThanOrEqualTo($cycle->ended_at))->toBeTrue();
            }

            // A request counts from when it is issued until its failure is reported,
            // and again if its mail went out after all. A request to confirm again
            // starts the count anew, and its resends add to it.
            $sent = 0;
            $last = null;
            $asking = false;

            foreach ($entry->activity->where('waitlist_subscription_id', $cycle->getKey()) as $row) {
                match ($row->type) {
                    ActivityType::ConfirmationRequested => $sent++,
                    ActivityType::ConfirmationFailed => $sent--,
                    ActivityType::ConfirmationMailed => $last === ActivityType::ConfirmationFailed ? $sent++ : null,
                    ActivityType::ReconfirmationRequested => $sent = $asking ? $sent + 1 : 1,
                    default => null,
                };

                if (in_array($row->type, [ActivityType::ConfirmationRequested, ActivityType::ConfirmationFailed, ActivityType::ConfirmationMailed], true)) {
                    $last = $row->type;
                }

                if ($row->type === ActivityType::ReconfirmationRequested) {
                    $asking = true;
                    $last = null;
                }

                if ($row->type === ActivityType::Reconfirmed) {
                    $asking = false;
                }
            }

            expect($sent)->toBe($cycle->confirmation_count, "cycle {$cycle->id}: confirmation_count does not match the log");
            expect($cycle->confirmation_outcome)->toBe(match ($last) {
                ActivityType::ConfirmationFailed => ConfirmationOutcome::Failed,
                ActivityType::ConfirmationMailed => ConfirmationOutcome::Mailed,
                default => null,
            }, "cycle {$cycle->id}: confirmation_outcome does not match the log");

            $signups = $entry->activity
                ->where('waitlist_subscription_id', $cycle->getKey())
                ->filter(fn (WaitlistActivity $row) => in_array($row->type, [ActivityType::Subscribed, ActivityType::Resubscribed], true))
                ->count();
            expect($signups)->toBe(1, "cycle {$cycle->id}: expected exactly one signup entry in the log");
        }

        expect($cycles->pluck('sequence')->all())->toBe(range(1, $cycles->count()), "entry {$entry->id}: sequence is not contiguous");

        $latest = $cycles->last();

        if ($latest !== null) {
            expect($entry->latest_subscription_id)->toBe($latest->getKey(), "entry {$entry->id}: latest_subscription_id is stale");
            expect($entry->status)->toBe($latest->projectedStatus(), "entry {$entry->id}: status projection drifted");
        } else {
            expect($entry->status)->toBe(EntryStatus::Pending);
        }
    }

    expect(WaitlistActivity::query()->whereNull('occurred_on')->count())->toBe(0);
}
