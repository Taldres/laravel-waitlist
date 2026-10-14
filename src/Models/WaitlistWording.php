<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Taldres\ImmutableAttributes\Attributes\Immutable;
use Taldres\ImmutableAttributes\Concerns\GuardsImmutableAttributes;
use Taldres\Waitlist\Models\Concerns\UsesWaitlistConnection;

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
#[Table('waitlist_wordings', timestamps: false)]
#[Fillable(['project', 'purpose', 'version', 'locale', 'text', 'registered_by', 'registered_at', 'retired_at'])]
class WaitlistWording extends Model
{
    /** The length of the purpose and version columns. */
    public const int KEY_LENGTH = 255;

    /** The length of the project column, which a project name may fill, as ProjectDefinition requires. */
    public const int PROJECT_LENGTH = 100;

    use GuardsImmutableAttributes;
    use UsesWaitlistConnection;

    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }
}
