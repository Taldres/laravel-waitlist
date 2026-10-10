<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Taldres\Waitlist\Actions\RegisterWording;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Exceptions\MissingConsentException;
use Taldres\Waitlist\Exceptions\MissingWordingException;
use Taldres\Waitlist\Exceptions\UnknownPurposeException;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Exceptions\WordingConflictException;
use Taldres\Waitlist\Exceptions\WordingMismatchException;
use Taldres\Waitlist\Exceptions\WordingNotAcceptedException;

/**
 * Validated on use rather than at boot, so a misconfiguration surfaces where it
 * matters without breaking artisan.
 */
class PurposeRegistry
{
    public function __construct(
        protected ProjectCatalog $catalog,
    ) {}

    /**
     * @throws UnknownWaitlistException
     * @throws MissingWordingException
     */
    public function policy(string $project, string $list): ListPolicy
    {
        $policy = $this->catalog->policy($project, $list) ?? throw UnknownWaitlistException::forList($project, $list);

        // The first signup brings the wording.
        if ($policy->wordingFromCallers) {
            return $policy;
        }

        if ($this->versions($project, $policy->primary) === []) {
            throw MissingWordingException::forPurpose($project, $list, $policy->primary);
        }

        // An optional purpose with no active wording is dropped; the list keeps
        // working and stored consents can still be withdrawn.
        $offered = array_values(array_filter(
            $policy->optional,
            fn (string $purpose): bool => $this->versions($project, $purpose) !== [],
        ));

        return $offered === $policy->optional ? $policy : $policy->withOptional($offered);
    }

    /**
     * @return array<string, string|array<string, string>> version => wording, or locale => wording; oldest first
     */
    public function versions(string $project, string $purpose): array
    {
        return $this->catalog->versions($project, $purpose);
    }

    /**
     * Primary purpose first; each text in the requested locale, else the app's,
     * else the fallback locale, else the first the version has.
     *
     * @return list<PurposeWording>
     */
    public function current(ListPolicy $policy, ?string $locale = null): array
    {
        $wordings = [];

        foreach ($policy->purposes() as $purpose) {
            $versions = $this->versions($policy->project, $purpose);

            if ($versions === []) {
                continue;
            }

            $version = (string) array_key_last($versions);
            [$text, $shown] = $this->display($versions[$version], $locale);

            $wordings[] = new PurposeWording($purpose, $version, $text, $purpose === $policy->primary, $shown);
        }

        return $wordings;
    }

    /**
     * Any registered version is accepted: a form loaded before a wording
     * change must still record what it showed.
     *
     * @param  array<array-key, mixed>  $requested  purpose => version, or purpose => {version, locale, hash, text}
     * @param  array<array-key, string>  $held  purposes whose consent is already in force: nothing new is recorded
     *                                          for them, so they need no hash
     * @return list<PurposeWording>
     *
     * @throws UnknownPurposeException
     * @throws WordingMismatchException
     * @throws WordingNotAcceptedException
     * @throws WordingConflictException
     * @throws MissingConsentException
     */
    public function resolve(ListPolicy $policy, array $requested, bool $acceptWording = false, ?string $caller = null, array $held = []): array
    {
        $wordings = [];

        foreach ($requested as $purpose => $choice) {
            [$version, $locale, $hash, $text] = $this->choice($choice);

            if ($text !== null && ! ($acceptWording && $policy->wordingFromCallers)) {
                throw WordingNotAcceptedException::fromClient((string) $purpose);
            }

            $wording = $text === null
                ? $this->wording($policy, (string) $purpose, $version, $locale)
                : $this->accept($policy, (string) $purpose, $version, $locale, $text, $caller);

            // Sent along with its text, a hash proves nothing more. The switch is
            // only read for a consent that gets recorded.
            if ($hash === null && $text === null && ! in_array($wording->purpose, $held, true) && WaitlistConfig::requireWordingHash()) {
                throw WordingMismatchException::hashRequired($wording->purpose);
            }

            if ($hash !== null && ! hash_equals($wording->hash(), strtolower($hash))) {
                throw WordingMismatchException::forPurpose($wording->purpose, $wording->version);
            }

            $wordings[] = $wording;
        }

        if (! in_array($policy->primary, array_column($wordings, 'purpose'), true)) {
            throw MissingConsentException::forPurpose($policy->primary);
        }

        return $wordings;
    }

    /**
     * @throws UnknownPurposeException
     */
    public function wording(ListPolicy $policy, string $purpose, string $version, ?string $locale = null): PurposeWording
    {
        if (! $policy->allows($purpose)) {
            throw UnknownPurposeException::forPurpose($policy->list, $purpose);
        }

        $wording = $this->versions($policy->project, $purpose)[$version] ?? throw UnknownPurposeException::forVersion($purpose, $version);

        if (is_string($wording)) {
            return new PurposeWording($purpose, $version, $wording, $purpose === $policy->primary);
        }

        $text = $wording[$locale ?? ''] ?? throw UnknownPurposeException::forLocale($purpose, $version, $locale);

        return new PurposeWording($purpose, $version, $text, $purpose === $policy->primary, $locale);
    }

    /**
     * Built from the text sent rather than the catalog, which may still hold
     * what it read before the registration.
     *
     * @throws UnknownPurposeException
     * @throws WordingConflictException
     */
    protected function accept(ListPolicy $policy, string $purpose, string $version, ?string $locale, string $text, ?string $caller): PurposeWording
    {
        if (! $policy->allows($purpose)) {
            throw UnknownPurposeException::forPurpose($policy->list, $purpose);
        }

        $known = $this->versions($policy->project, $purpose)[$version] ?? null;
        $required = $purpose === $policy->primary;

        if (is_string($known)) {
            return $known === $text
                ? new PurposeWording($purpose, $version, $text, $required)
                : throw WordingConflictException::changed($purpose, $version, '');
        }

        if (is_array($known) && $locale === null) {
            throw WordingConflictException::mixed($purpose, $version);
        }

        $knownText = is_array($known) ? ($known[(string) $locale] ?? null) : null;

        if ($knownText === null) {
            app(RegisterWording::class)->fromCaller($policy->project, $purpose, $version, $locale, $text, $caller);
        } elseif ($knownText !== $text) {
            throw WordingConflictException::changed($purpose, $version, (string) $locale);
        }

        return new PurposeWording($purpose, $version, $text, $required, $locale);
    }

    /**
     * @return array{0: string, 1: string|null, 2: string|null, 3: string|null} version, locale, hash and text
     */
    protected function choice(mixed $choice): array
    {
        if (is_string($choice) || is_int($choice)) {
            return [(string) $choice, null, null, null];
        }

        $version = is_array($choice) ? ($choice['version'] ?? null) : null;
        $locale = is_array($choice) ? ($choice['locale'] ?? null) : null;
        $hash = is_array($choice) ? ($choice['hash'] ?? null) : null;
        $text = is_array($choice) ? ($choice['text'] ?? null) : null;

        return [
            is_string($version) || is_int($version) ? (string) $version : '',
            is_string($locale) ? $locale : null,
            is_string($hash) ? $hash : null,
            is_string($text) ? $text : null,
        ];
    }

    /**
     * @param  string|array<string, string>  $wording
     * @return array{0: string, 1: string|null} text and locale
     */
    protected function display(string|array $wording, ?string $locale): array
    {
        if (is_string($wording)) {
            return [$wording, null];
        }

        foreach ([$locale, app()->getLocale(), config('app.fallback_locale')] as $candidate) {
            if (is_string($candidate) && isset($wording[$candidate])) {
                return [$wording[$candidate], $candidate];
            }
        }

        $first = (string) array_key_first($wording);

        return [$wording[$first], $first];
    }
}
