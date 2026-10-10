<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;
use Taldres\ImmutableAttributes\Attributes\ImmutableModel;
use Taldres\ImmutableAttributes\Concerns\GuardsImmutableAttributes;
use Taldres\Waitlist\Database\Factories\WaitlistActivityFactory;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Models\Concerns\UsesWaitlistEncrypter;
use Taldres\Waitlist\Support\ResolvesModel;

/**
 * Immutable as a model. Rows outlive the person: erasure clears the foreign
 * keys, the request metadata and the exact timestamp, and leaves the project,
 * list, type, purpose, the status a departure left and the date, which is what
 * reports are built from.
 *
 * @property int $id
 * @property string|null $waitlist_entry_id
 * @property int|null $waitlist_subscription_id
 * @property string $project
 * @property string $list
 * @property ActivityType $type
 * @property EntryStatus|null $previous_status the entry's status before a departure
 * @property string|null $purpose
 * @property string|null $reference
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon|null $occurred_at
 * @property string $occurred_on Y-m-d, the reporting day this row belongs to
 */
#[ImmutableModel]
class WaitlistActivity extends Model
{
    use GuardsImmutableAttributes;

    /** @use HasFactory<WaitlistActivityFactory> */
    use HasFactory;

    use ResolvesModel;
    use UsesWaitlistEncrypter;

    public $timestamps = false;

    protected $table = 'waitlist_activity';

    protected $fillable = [
        'waitlist_entry_id',
        'waitlist_subscription_id',
        'project',
        'list',
        'type',
        'previous_status',
        'purpose',
        'reference',
        'ip',
        'user_agent',
        'occurred_at',
        'occurred_on',
    ];

    protected $hidden = [
        'ip',
        'user_agent',
        'reference',
    ];

    public function getConnectionName(): ?string
    {
        return WaitlistEntry::waitlistConnection() ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'previous_status' => EntryStatus::class,
            'ip' => 'encrypted',
            'user_agent' => 'encrypted',
            'reference' => 'encrypted',
            'occurred_at' => 'datetime',
            // occurred_on stays a plain Y-m-d string: a date cast would write it
            // as "Y-m-d 00:00:00", which does not compare equal to a date bound
            // on SQLite and silently drops the last day of a report.
        ];
    }

    protected static function booted(): void
    {
        // Append-only at the application level. Erasure rewrites rows through
        // the query builder on purpose, which these guards do not cover.
        static::deleting(fn () => throw new RuntimeException('Waitlist activity cannot be deleted; erase the entry.'));
    }

    /**
     * @return BelongsTo<WaitlistEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(static::modelClass(), 'waitlist_entry_id');
    }

    /**
     * @return BelongsTo<WaitlistSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(static::subscriptionModelClass(), 'waitlist_subscription_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOnList(Builder $query, string $list, string $project = WaitlistEntry::DEFAULT_PROJECT): Builder
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
            $list !== null => $query->onList($list, $project ?? WaitlistEntry::DEFAULT_PROJECT),
            $project !== null => $query->inProject($project),
            default => $query,
        };
    }

    protected static function newFactory(): WaitlistActivityFactory
    {
        return WaitlistActivityFactory::new();
    }
}
