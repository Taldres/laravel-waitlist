<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Database\Factories;

use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Taldres\Waitlist\Actions\RecordActivity;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\EndReason;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Exceptions\WaitlistException;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;
use Taldres\Waitlist\Support\PurposeRegistry;

/**
 * @extends Factory<WaitlistEntry>
 */
class WaitlistEntryFactory extends Factory
{
    protected $model = WaitlistEntry::class;

    public function definition(): array
    {
        $unsubscribeToken = Str::random(64);

        return [
            'project' => WaitlistEntry::DEFAULT_PROJECT,
            'list' => 'default',
            'email' => $this->faker->unique()->safeEmail(),
            'status' => EntryStatus::Pending,
            'unsubscribe_token_hash' => WaitlistEntry::hashToken($unsubscribeToken),
            'unsubscribe_token' => $unsubscribeToken,
            'metadata' => [],
        ];
    }

    /**
     * @param  list<string>|null  $purposes  primary first; null for the list's primary purpose
     */
    public function pending(?string $confirmToken = null, ?array $purposes = null): static
    {
        return $this->withCycle(
            fn (array $attributes): array => $attributes,
            $confirmToken,
            $purposes,
            EntryStatus::Pending,
            [ActivityType::Subscribed, ActivityType::ConfirmationRequested],
        );
    }

    /**
     * @param  list<string>|null  $purposes  primary first; null for the list's primary purpose
     */
    public function confirmed(?array $purposes = null): static
    {
        return $this->withCycle(
            fn (array $attributes): array => $attributes + ['confirmed_at' => now()],
            null,
            $purposes,
            EntryStatus::Confirmed,
            [ActivityType::Subscribed, ActivityType::ConfirmationRequested, ActivityType::Confirmed],
        );
    }

    /**
     * @param  list<string>|null  $purposes  primary first; null for the list's primary purpose
     */
    public function unsubscribed(?array $purposes = null): static
    {
        return $this->withCycle(fn (array $attributes): array => [
            ...$attributes,
            'active' => null,
            'confirm_token_hash' => null,
            'confirm_token_expires_at' => null,
            'confirmed_at' => now(),
            'ended_at' => now(),
            'end_reason' => EndReason::Unsubscribed,
        ], null, $purposes, EntryStatus::Unsubscribed, [
            ActivityType::Subscribed,
            ActivityType::ConfirmationRequested,
            ActivityType::Confirmed,
            ActivityType::Unsubscribed,
        ]);
    }

    public function onList(string $list, string $project = WaitlistEntry::DEFAULT_PROJECT): static
    {
        return $this->state(fn (): array => ['project' => $project, 'list' => $list]);
    }

    /**
     * Build the cycle and the activity rows the actions would have produced, so
     * fixtures satisfy the same invariants.
     *
     * @param  Closure(array<string, mixed>): array<string, mixed>  $mutate
     * @param  list<string>|null  $purposes  primary first, granted with their current wording
     * @param  list<ActivityType>  $activity
     */
    protected function withCycle(Closure $mutate, ?string $confirmToken, ?array $purposes, EntryStatus $status, array $activity): static
    {
        return $this->state(fn () => ['status' => $status])
            ->afterCreating(function (WaitlistEntry $entry) use ($mutate, $confirmToken, $purposes, $activity): void {
                $token = $confirmToken ?? Str::random(64);

                /** @var WaitlistSubscription $subscription */
                $subscription = $entry->subscriptions()->create($mutate([
                    'sequence' => 1,
                    'active' => 1,
                    'confirm_token_hash' => WaitlistEntry::hashToken($token),
                    'confirm_token_expires_at' => now()->addDays(7),
                    'started_at' => now(),
                    'confirmation_sent_at' => now(),
                    'confirmation_count' => 1,
                ]));

                $registry = app(PurposeRegistry::class);

                foreach ($purposes ?? [$this->primaryPurpose($registry, $entry)] as $index => $purpose) {
                    $versions = $registry->versions($entry->project, $purpose);
                    $version = array_key_last($versions);
                    $wording = $version !== null ? $versions[$version] : "Consent to {$purpose}.";
                    $locale = is_array($wording) ? (isset($wording[app()->getLocale()]) ? app()->getLocale() : (string) array_key_first($wording)) : null;

                    $subscription->consents()->create([
                        'purpose' => $purpose,
                        'version' => $version ?? 'test',
                        'locale' => $locale,
                        'text' => is_array($wording) ? $wording[(string) $locale] : $wording,
                        'required' => $index === 0,
                        'granted_at' => $subscription->started_at,
                        'active' => 1,
                    ]);
                }

                $entry->forceFill(['latest_subscription_id' => $subscription->getKey()])->save();
                $entry->setRelation('latestSubscription', $subscription);
                $entry->setRelation('currentSubscription', $subscription->isOpen() ? $subscription : null);

                $recorder = app(RecordActivity::class);

                foreach ($activity as $type) {
                    // Only unsubscribed() logs a departure, from a confirmed cycle.
                    $recorder($entry, $type, $subscription, previousStatus: $type->isDeparture() ? EntryStatus::Confirmed : null);
                }
            });
    }

    /**
     * Falls back to "waitlist" for a list the catalog does not know.
     */
    protected function primaryPurpose(PurposeRegistry $registry, WaitlistEntry $entry): string
    {
        try {
            return $registry->policy($entry->project, $entry->list)->primary;
        } catch (WaitlistException|InvalidArgumentException) {
            return 'waitlist';
        }
    }
}
