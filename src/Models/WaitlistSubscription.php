<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;
use Taldres\ImmutableAttributes\Attributes\Immutable;
use Taldres\ImmutableAttributes\Concerns\GuardsImmutableAttributes;
use Taldres\Waitlist\Database\Factories\WaitlistSubscriptionFactory;
use Taldres\Waitlist\Enums\ConfirmationOutcome;
use Taldres\Waitlist\Enums\EndReason;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Support\ResolvesModel;

/**
 * One subscription cycle: an opt-in, its confirmation requests, and how it
 * ended. There is no status column: the state is the combination of
 * confirmed_at and ended_at, and only moves forward.
 *
 * @property int $id
 * @property string $waitlist_entry_id
 * @property int $sequence
 * @property int|null $active
 * @property string|null $confirm_token_hash
 * @property Carbon|null $confirm_token_expires_at
 * @property Carbon $started_at
 * @property Carbon|null $confirmation_sent_at
 * @property int $confirmation_count
 * @property ConfirmationOutcome|null $confirmation_outcome
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $ended_at
 * @property EndReason|null $end_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WaitlistEntry $entry
 * @property-read Collection<int, WaitlistConsent> $consents
 */
#[Immutable('waitlist_entry_id', 'sequence', 'started_at')]
class WaitlistSubscription extends Model
{
    use GuardsImmutableAttributes;

    /** @use HasFactory<WaitlistSubscriptionFactory> */
    use HasFactory;

    use ResolvesModel;

    protected $table = 'waitlist_subscriptions';

    protected $fillable = [
        'waitlist_entry_id',
        'sequence',
        'active',
        'confirm_token_hash',
        'confirm_token_expires_at',
        'started_at',
        'confirmation_sent_at',
        'confirmation_count',
        'confirmation_outcome',
        'confirmed_at',
        'ended_at',
        'end_reason',
    ];

    protected $hidden = [
        'confirm_token_hash',
    ];

    public function getConnectionName(): ?string
    {
        return WaitlistEntry::waitlistConnection() ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return [
            'end_reason' => EndReason::class,
            'confirmation_outcome' => ConfirmationOutcome::class,
            'confirm_token_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'confirmation_sent_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new RuntimeException('Subscriptions cannot be deleted individually; erase the entry.'));
    }

    /**
     * @return BelongsTo<WaitlistEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(static::modelClass(), 'waitlist_entry_id');
    }

    /**
     * Withdrawn grants included.
     *
     * @return HasMany<WaitlistConsent, $this>
     */
    public function consents(): HasMany
    {
        return $this->hasMany(static::consentModelClass(), 'waitlist_subscription_id')->orderBy('id');
    }

    /**
     * Purposes in force: the cycle is open and confirmed, the grant not
     * withdrawn.
     *
     * @return list<string>
     */
    public function effectivePurposes(): array
    {
        if (! $this->isOpen() || ! $this->isConfirmed()) {
            return [];
        }

        return array_values($this->consents
            ->filter(fn (WaitlistConsent $consent) => ! $consent->isWithdrawn())
            ->map(fn (WaitlistConsent $consent): string => $consent->purpose)
            ->unique()
            ->all());
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function hasExpiredToken(): bool
    {
        return $this->confirm_token_expires_at?->isPast() === true;
    }

    public function projectedStatus(): EntryStatus
    {
        return match (true) {
            ! $this->isOpen() => EntryStatus::Unsubscribed,
            $this->isConfirmed() => EntryStatus::Confirmed,
            default => EntryStatus::Pending,
        };
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    protected static function newFactory(): WaitlistSubscriptionFactory
    {
        return WaitlistSubscriptionFactory::new();
    }
}
