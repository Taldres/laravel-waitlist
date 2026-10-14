<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;
use Taldres\ImmutableAttributes\Attributes\Immutable;
use Taldres\ImmutableAttributes\Concerns\GuardsImmutableAttributes;
use Taldres\Waitlist\Models\Concerns\UsesWaitlistConnection;
use Taldres\Waitlist\Support\ResolvesModel;

/**
 * Only a withdrawal is ever written after the grant.
 *
 * @property int $id
 * @property int $waitlist_subscription_id
 * @property string $purpose
 * @property string $version
 * @property string|null $locale
 * @property string $text
 * @property bool $required
 * @property Carbon $granted_at
 * @property Carbon|null $withdrawn_at
 * @property int|null $active
 * @property-read WaitlistSubscription $subscription
 */
#[Immutable([
    'waitlist_subscription_id',
    'purpose',
    'version',
    'locale',
    'text',
    'required',
    'granted_at',
])]
#[Table('waitlist_consents', timestamps: false)]
#[Fillable([
    'waitlist_subscription_id',
    'purpose',
    'version',
    'locale',
    'text',
    'required',
    'granted_at',
    'withdrawn_at',
    'active',
])]
class WaitlistConsent extends Model
{
    use GuardsImmutableAttributes;
    use ResolvesModel;
    use UsesWaitlistConnection;

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'granted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new RuntimeException('Consents cannot be deleted individually; erase the entry.'));
    }

    /**
     * @return BelongsTo<WaitlistSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(static::subscriptionModelClass(), 'waitlist_subscription_id');
    }

    public function isWithdrawn(): bool
    {
        return $this->withdrawn_at !== null;
    }
}
