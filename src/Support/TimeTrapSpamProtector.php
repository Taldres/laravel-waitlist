<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Taldres\Waitlist\Auth\WaitlistCaller;
use Taldres\Waitlist\Contracts\SpamProtector;

/**
 * A bot check that asks no outside service: a hidden field people leave empty,
 * and the challenge from GET /purposes, which must come back in the
 * X-Waitlist-Challenge header no sooner than a person fills in a form and
 * before it goes stale. Bind it as the SpamProtector to use it. A server
 * calling for its project is not checked: it holds credentials, and its
 * visitors are checked where it sees them.
 */
class TimeTrapSpamProtector implements SpamProtector
{
    public const string HEADER = 'X-Waitlist-Challenge';

    public const string HONEYPOT = 'website';

    protected const int MINIMUM_SECONDS = 2;

    protected const int MAXIMUM_SECONDS = 3600;

    // Keeps any other value the application encrypts with the same key, such
    // as a cookie, from passing as a challenge.
    private const string PREFIX = 'waitlist-challenge|';

    public function __construct(protected readonly StringEncrypter $encrypter) {}

    /**
     * A new challenge that carries the time it was issued.
     */
    public function challenge(): string
    {
        return $this->encrypter->encryptString(self::PREFIX.Date::now()->getTimestampMs());
    }

    public function passes(Request $request): bool
    {
        if (WaitlistCaller::isServer($request)) {
            return true;
        }

        $honeypot = $request->input(static::HONEYPOT);

        if ($honeypot !== null && $honeypot !== '') {
            return false;
        }

        $issuedAt = $this->issuedAt($request->header(static::HEADER));

        if ($issuedAt === null) {
            return false;
        }

        $elapsed = Date::now()->getTimestampMs() - $issuedAt;

        return $elapsed >= static::MINIMUM_SECONDS * 1000 && $elapsed <= static::MAXIMUM_SECONDS * 1000;
    }

    /**
     * When the challenge was issued, in milliseconds, or null for anything
     * this class did not issue.
     */
    protected function issuedAt(?string $challenge): ?int
    {
        if ($challenge === null || $challenge === '') {
            return null;
        }

        try {
            $payload = $this->encrypter->decryptString($challenge);
        } catch (DecryptException) {
            return null;
        }

        if (! str_starts_with($payload, self::PREFIX)) {
            return null;
        }

        $issuedAt = substr($payload, strlen(self::PREFIX));

        return ctype_digit($issuedAt) ? (int) $issuedAt : null;
    }
}
