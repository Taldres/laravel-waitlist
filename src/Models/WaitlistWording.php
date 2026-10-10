<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Taldres\ImmutableAttributes\Attributes\Immutable;
use Taldres\ImmutableAttributes\Concerns\GuardsImmutableAttributes;

/**
 * Only a retirement is ever written after the registration.
 *
 * @property int $id
 * @property string $project
 * @property string $purpose
 * @property string $version
 * @property string $locale empty for a version with one text for every locale
 * @property string $text
 * @property string|null $registered_by
 * @property Carbon $registered_at
 * @property Carbon|null $retired_at
 */
#[Immutable('project', 'purpose', 'version', 'locale', 'text', 'registered_by', 'registered_at')]
class WaitlistWording extends Model
{
    use GuardsImmutableAttributes;

    public $timestamps = false;

    protected $table = 'waitlist_wordings';

    protected $fillable = ['project', 'purpose', 'version', 'locale', 'text', 'registered_by', 'registered_at', 'retired_at'];

    public function getConnectionName(): ?string
    {
        return WaitlistEntry::waitlistConnection() ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }
}
