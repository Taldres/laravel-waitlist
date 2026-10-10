<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Illuminate\Http\Request;
use Taldres\Waitlist\Auth\WaitlistCaller;
use Taldres\Waitlist\Config\ConfigFallback;
use Taldres\Waitlist\Config\WaitlistConfig;

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
     *
     * Guards or a client IP header that do not read record the step as a
     * guest's, from the address the request came from: leaving by a link must
     * not wait for them. The signup still refuses them in the gate.
     */
    public static function fromRequest(Request $request): self
    {
        $userAgent = $request->userAgent();

        [$ip, $caller] = ConfigFallback::read(
            fn (): array => [WaitlistCaller::ip($request), WaitlistCaller::of($request)],
            fallback: [$request->ip(), null],
        );

        return new self(
            ip: $ip,
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
     * Privacy settings that do not read keep neither: the step still goes
     * through, without what nobody could tell was meant to be stored.
     *
     * @return array{ip: string|null, user_agent: string|null}
     */
    public function stored(): array
    {
        $privacy = $this->ip === null && $this->userAgent === null
            ? null
            : ConfigFallback::read(WaitlistConfig::privacy(...), fallback: null);

        return [
            'ip' => $privacy?->storeIp ? $this->ip : null,
            'user_agent' => $privacy?->storeUserAgent ? $this->userAgent : null,
        ];
    }
}
