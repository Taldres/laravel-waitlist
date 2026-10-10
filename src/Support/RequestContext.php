<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Illuminate\Http\Request;
use Taldres\Waitlist\Auth\WaitlistCaller;
use Taldres\Waitlist\Enums\ConfigKey;

/**
 * Where a lifecycle step came from. stored() returns IP and user agent only
 * when the privacy config opts in.
 */
final readonly class RequestContext
{
    public function __construct(
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?string $caller = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * The user agent is scrubbed of invalid UTF-8: anyone can send one, and it
     * would break every JSON copy of the person's data later.
     */
    public static function fromRequest(Request $request): self
    {
        $userAgent = $request->userAgent();
        $caller = WaitlistCaller::of($request);

        return new self(
            ip: WaitlistCaller::ip($request),
            userAgent: $userAgent === null ? null : mb_scrub($userAgent, 'UTF-8'),
            caller: $caller === null ? null : WaitlistCaller::key($caller),
        );
    }

    /**
     * @param  array{ip?: string|null, user_agent?: string|null}  $context
     */
    public static function fromArray(array $context): self
    {
        return new self(ip: $context['ip'] ?? null, userAgent: $context['user_agent'] ?? null);
    }

    /**
     * @return array{ip: string|null, user_agent: string|null}
     */
    public function stored(): array
    {
        return [
            'ip' => Setting::enabled(ConfigKey::StoreIp->value) ? $this->ip : null,
            'user_agent' => Setting::enabled(ConfigKey::StoreUserAgent->value) ? $this->userAgent : null,
        ];
    }
}
