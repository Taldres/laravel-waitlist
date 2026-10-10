<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Taldres\Waitlist\Actions\RecordActivity;
use Taldres\Waitlist\Contracts\EmailNormalizer;
use Taldres\Waitlist\Database\Factories\WaitlistEntryFactory;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\EntryForgotten;
use Taldres\Waitlist\Models\Concerns\UsesWaitlistEncrypter;
use Taldres\Waitlist\Support\BlindIndex;
use Taldres\Waitlist\Support\ResolvesModel;
use Taldres\Waitlist\Support\Setting;

/**
 * Identity only: what happens to the address lives in subscription cycles, and
 * $status is the projection of the latest one.
 *
 * @property string $id
 * @property string $project
 * @property string $list
 * @property string $email
 * @property string $email_hash
 * @property EntryStatus $status
 * @property int|null $latest_subscription_id
 * @property string $unsubscribe_token_hash
 * @property string|null $unsubscribe_token
 * @property string|null $manage_token_hash
 * @property Carbon|null $manage_token_expires_at
 * @property Carbon|null $manage_link_sent_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read list<string> $purposes
 * @property-read Collection<int, WaitlistSubscription> $subscriptions
 * @property-read WaitlistSubscription|null $currentSubscription
 * @property-read WaitlistSubscription|null $latestSubscription
 */
class WaitlistEntry extends Model
{
    /** @use HasFactory<WaitlistEntryFactory> */
    use HasFactory;

    use HasUuids;
    use ResolvesModel;
    use UsesWaitlistEncrypter;

    public const string DEFAULT_PROJECT = 'default';

    protected $table = 'waitlist_entries';

    protected $fillable = [
        'project',
        'list',
        'email',
        'status',
        'latest_subscription_id',
        'unsubscribe_token_hash',
        'unsubscribe_token',
        'manage_token_hash',
        'manage_token_expires_at',
        'manage_link_sent_at',
        'metadata',
    ];

    protected $hidden = [
        'email_hash',
        'unsubscribe_token_hash',
        'unsubscribe_token',
        'manage_token_hash',
    ];

    public static function waitlistConnection(): ?string
    {
        $connection = Setting::value(ConfigKey::Connection->value);

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function getConnectionName(): ?string
    {
        return static::waitlistConnection() ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return [
            'email' => 'encrypted',
            'status' => EntryStatus::class,
            'unsubscribe_token' => 'encrypted',
            'manage_token_expires_at' => 'datetime',
            'manage_link_sent_at' => 'datetime',
            'metadata' => 'encrypted:array',
        ];
    }

    /**
     * A mutator rather than a saving hook, which saveQuietly() would skip.
     *
     * @return Attribute<never, string>
     */
    protected function email(): Attribute
    {
        return Attribute::set(function (string $value): array {
            $email = app(EmailNormalizer::class)->normalize($value);

            return [
                'email' => $this->castAttributeAsEncryptedString('email', $email),
                'email_hash' => BlindIndex::hash($email),
            ];
        });
    }

    protected static function booted(): void
    {
        // Before the row goes: activity rows are found by the foreign key the
        // delete is about to clear.
        static::deleting(fn (WaitlistEntry $entry) => app(RecordActivity::class)->erased($entry));

        static::deleted(fn (WaitlistEntry $entry) => EntryForgotten::dispatch(
            entryId: $entry->id,
            project: $entry->project,
            list: $entry->list,
            email: $entry->readableEmail(),
        ));
    }

    public function readableEmail(): ?string
    {
        $payload = $this->getAttributes()['email'] ?? null;

        if (! is_string($payload)) {
            return null;
        }

        return $this->decryptOrNull($payload);
    }

    /**
     * @return HasMany<WaitlistSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(static::subscriptionModelClass(), 'waitlist_entry_id')->orderBy('sequence');
    }

    /**
     * At most one open cycle exists per entry, enforced by a unique index on
     * (waitlist_entry_id, active).
     *
     * @return HasOne<WaitlistSubscription, $this>
     */
    public function currentSubscription(): HasOne
    {
        return $this->hasOne(static::subscriptionModelClass(), 'waitlist_entry_id')->whereNotNull('active');
    }

    /**
     * The most recent cycle, open or not.
     *
     * @return BelongsTo<WaitlistSubscription, $this>
     */
    public function latestSubscription(): BelongsTo
    {
        return $this->belongsTo(static::subscriptionModelClass(), 'latest_subscription_id');
    }

    /**
     * @return HasMany<WaitlistActivity, $this>
     */
    public function activity(): HasMany
    {
        return $this->hasMany(static::activityModelClass(), 'waitlist_entry_id')->orderBy('id');
    }

    /**
     * @return Attribute<list<string>, never>
     */
    protected function purposes(): Attribute
    {
        return Attribute::get(fn (): array => $this->latestSubscription?->effectivePurposes() ?? []);
    }

    public function hasConsentFor(string $purpose): bool
    {
        return in_array($purpose, $this->purposes, true);
    }

    /**
     * @return Attribute<Carbon|null, never>
     */
    protected function confirmedAt(): Attribute
    {
        return Attribute::get(fn (): ?Carbon => $this->latestSubscription?->confirmed_at);
    }

    /**
     * When the address last left.
     *
     * @return Attribute<Carbon|null, never>
     */
    protected function unsubscribedAt(): Attribute
    {
        return Attribute::get(fn (): ?Carbon => $this->latestSubscription?->ended_at);
    }

    /**
     * SHA-256 rather than a salted password hash, so tokens stay queryable, as
     * Sanctum does for its access tokens.
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public static function findByUnsubscribeToken(string $plainToken): ?static
    {
        return static::query()->where('unsubscribe_token_hash', static::hashToken($plainToken))->first();
    }

    /**
     * Expired tokens are found too, so callers can tell an expired link from
     * an unknown one; check hasExpiredManageToken().
     */
    public static function findByManageToken(string $plainToken): ?static
    {
        return static::query()->where('manage_token_hash', static::hashToken($plainToken))->first();
    }

    public function hasExpiredManageToken(): bool
    {
        return $this->manage_token_expires_at === null || $this->manage_token_expires_at->isPast();
    }

    /**
     * Null when none is stored or it cannot be decrypted, e.g. after an APP_KEY
     * rotation.
     */
    public function plainUnsubscribeToken(): ?string
    {
        $payload = $this->getRawOriginal('unsubscribe_token');

        if (! is_string($payload)) {
            return null;
        }

        return $this->decryptOrNull($payload);
    }

    private function decryptOrNull(string $payload): ?string
    {
        try {
            $plain = $this->fromEncryptedString($payload);
        } catch (DecryptException) {
            return null;
        }

        return is_string($plain) ? $plain : null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOnList(Builder $query, string $list, string $project = self::DEFAULT_PROJECT): Builder
    {
        return $query->where('project', $project)->where('list', $list);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInProject(Builder $query, string $project): Builder
    {
        return $query->where('project', $project);
    }

    /**
     * A list without a project is the default project's list.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithin(Builder $query, ?string $list = null, ?string $project = null): Builder
    {
        return match (true) {
            $list !== null => $query->onList($list, $project ?? self::DEFAULT_PROJECT),
            $project !== null => $query->inProject($project),
            default => $query,
        };
    }

    /**
     * Addresses that may be contacted for a purpose right now.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWhereConsentedTo(Builder $query, string $purpose): Builder
    {
        return $query->whereHas('latestSubscription', fn (Builder $cycle) => $cycle
            ->whereNull('ended_at')
            ->whereNotNull('confirmed_at')
            ->whereHas('consents', fn (Builder $consent) => $consent
                ->where('purpose', $purpose)
                ->whereNull('withdrawn_at')));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForEmail(Builder $query, string $email): Builder
    {
        return $query->whereIn('email_hash', BlindIndex::hashes($email));
    }

    /**
     * Counted from the start of the current cycle, so a resend or re-subscribe
     * does not inherit an older cycle's age.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingConfirmationSince(Builder $query, int $days): Builder
    {
        return $query
            ->where('status', EntryStatus::Pending)
            ->whereHas('currentSubscription', fn (Builder $cycle) => $cycle
                ->whereNull('confirmed_at')
                ->where('started_at', '<', now()->subDays($days)));
    }

    /**
     * Left more than $days ago, counted from the end of the latest cycle.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLeftBefore(Builder $query, int $days): Builder
    {
        return $query
            ->where('status', EntryStatus::Unsubscribed)
            ->whereHas('latestSubscription', fn (Builder $cycle) => $cycle
                ->where('ended_at', '<', now()->subDays($days)));
    }

    protected static function newFactory(): WaitlistEntryFactory
    {
        return WaitlistEntryFactory::new();
    }
}
