<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Taldres\Waitlist\Models\WaitlistEntry;

/**
 * Carries no email address: the links this answers are also followed by
 * scanners and forwarded mails.
 *
 * @mixin WaitlistEntry
 */
class WaitlistEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'project' => $this->project,
            'list' => $this->list,
            'status' => $this->status,
            'purposes' => $this->purposes,
            'confirmed_at' => $this->confirmed_at,
            'unsubscribed_at' => $this->unsubscribed_at,
            'created_at' => $this->created_at,
        ];
    }
}
