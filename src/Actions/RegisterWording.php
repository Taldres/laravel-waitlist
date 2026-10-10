<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Taldres\Waitlist\Events\WordingRegistered;
use Taldres\Waitlist\Exceptions\WordingConflictException;
use Taldres\Waitlist\Models\WaitlistWording;
use Taldres\Waitlist\Support\ResolvesModel;

class RegisterWording
{
    use ResolvesModel;

    /**
     * The same text again changes nothing, and restores a retired version;
     * other text for a registered version is refused, since consents given
     * under it must keep meaning what they said. A version can gain locales.
     *
     * @param  string|array<string, string>  $wording
     * @return int texts added or restored
     *
     * @throws InvalidArgumentException when a purpose, version or locale is too long
     * @throws WordingConflictException
     */
    public function __invoke(string $project, string $purpose, string $version, string|array $wording): int
    {
        $texts = is_string($wording) ? ['' => $wording] : $wording;

        if (mb_strlen($purpose) > 100 || mb_strlen($version) > 100 || max(array_map(mb_strlen(...), array_map('strval', array_keys($texts))) ?: [0]) > 35) {
            throw new InvalidArgumentException('Purposes and versions take up to 100 characters, locales up to 35.');
        }

        if ($texts === [] || in_array('', array_map(trim(...), $texts), true)) {
            throw WordingConflictException::empty($purpose, $version);
        }

        // One text for every locale or one per locale, never both: the
        // catalog would serve only the per-locale texts.
        if (count($texts) > 1 && array_key_exists('', $texts)) {
            throw WordingConflictException::mixed($purpose, $version);
        }

        return static::waitlistConnection()->transaction(function () use ($project, $purpose, $version, $texts): int {
            $registered = WaitlistWording::query()
                ->where('project', $project)
                ->where('purpose', $purpose)
                ->where('version', $version)
                ->lockForUpdate()
                ->get()
                ->keyBy('locale');

            if ($registered->isNotEmpty() && $registered->has('') !== array_key_exists('', $texts)) {
                throw WordingConflictException::mixed($purpose, $version);
            }

            $written = 0;

            foreach ($texts as $locale => $text) {
                $existing = $registered->get((string) $locale);

                if ($existing === null) {
                    WaitlistWording::query()->create([
                        'project' => $project,
                        'purpose' => $purpose,
                        'version' => $version,
                        'locale' => (string) $locale,
                        'text' => $text,
                        'registered_at' => Carbon::now(),
                    ]);
                    $written++;
                } elseif ($existing->text !== $text) {
                    throw WordingConflictException::changed($purpose, $version, (string) $locale);
                } elseif ($existing->retired_at !== null) {
                    $existing->update(['retired_at' => null]);
                    $written++;
                }
            }

            return $written;
        });
    }

    /**
     * Unlike a registration by the app, never brings a retired version back:
     * retiring it was the operator's decision.
     *
     * @return bool whether the text was new
     *
     * @throws InvalidArgumentException when a version or locale is too long
     * @throws WordingConflictException
     */
    public function fromCaller(string $project, string $purpose, string $version, ?string $locale, string $text, ?string $caller = null): bool
    {
        if (mb_strlen($purpose) > 100 || mb_strlen($version) > 100 || mb_strlen((string) $locale) > 35) {
            throw new InvalidArgumentException('Purposes and versions take up to 100 characters, locales up to 35.');
        }

        if (trim($text) === '') {
            throw WordingConflictException::empty($purpose, $version);
        }

        try {
            return $this->registerFromCaller($project, $purpose, $version, $locale ?? '', $text, $caller);
        } catch (UniqueConstraintViolationException) {
            return $this->registerFromCaller($project, $purpose, $version, $locale ?? '', $text, $caller);
        }
    }

    /**
     * Stops accepting the version for new consents; existing consents keep
     * their snapshot.
     *
     * @return int texts retired
     */
    public function retire(string $project, string $purpose, string $version): int
    {
        return WaitlistWording::query()
            ->where('project', $project)
            ->where('purpose', $purpose)
            ->where('version', $version)
            ->whereNull('retired_at')
            ->update(['retired_at' => Carbon::now()]);
    }

    /**
     * @throws WordingConflictException
     * @throws UniqueConstraintViolationException
     */
    private function registerFromCaller(string $project, string $purpose, string $version, string $locale, string $text, ?string $caller): bool
    {
        // Concurrent first signups of a version deadlock on MySQL and MariaDB
        // (gap locks) and violate the unique index on PostgreSQL.
        return static::waitlistConnection()->transaction(function () use ($project, $purpose, $version, $locale, $text, $caller): bool {
            $registered = WaitlistWording::query()
                ->where('project', $project)
                ->where('purpose', $purpose)
                ->where('version', $version)
                ->lockForUpdate()
                ->get()
                ->keyBy('locale');

            if ($registered->isNotEmpty() && $registered->has('') !== ($locale === '')) {
                throw WordingConflictException::mixed($purpose, $version);
            }

            $existing = $registered->get($locale);

            if ($existing !== null) {
                return match (true) {
                    $existing->text !== $text => throw WordingConflictException::changed($purpose, $version, $locale),
                    $existing->retired_at !== null => throw WordingConflictException::retired($purpose, $version),
                    default => false,
                };
            }

            WordingRegistered::dispatch(WaitlistWording::query()->create([
                'project' => $project,
                'purpose' => $purpose,
                'version' => $version,
                'locale' => $locale,
                'text' => $text,
                'registered_by' => $caller,
                'registered_at' => Carbon::now(),
            ]));

            return true;
        }, attempts: 3);
    }
}
