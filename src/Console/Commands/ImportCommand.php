<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use SplFileObject;
use Taldres\Waitlist\Actions\ImportEntry;
use Taldres\Waitlist\Console\Concerns\ReadsStringOptions;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Exceptions\InvalidEmailException;
use Taldres\Waitlist\Exceptions\MissingConsentException;
use Taldres\Waitlist\Exceptions\UnknownPurposeException;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Exceptions\WaitlistException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\SubscribeResult;
use Throwable;

#[Signature('waitlist:import
        {file : CSV with a header row: email, purposes, and optionally list, locale and consented_at}
        {--source= : Where the addresses come from, recorded with each of them}
        {--with-consent : Put each address on the list without a mail, consented at the time in its consented_at column}
        {--request-confirmation : Let each address wait for the confirmation mail your listener sends}
        {--list= : The list of rows that name none}
        {--project=default : The project the lists belong to}
        {--limit= : Import at most this many addresses, then print the command that resumes}
        {--from-line= : Resume at this line, as the command printed when it stopped}')]
#[Description('Import addresses that consented elsewhere, with the source and time of their consent')]
class ImportCommand extends Command implements PromptsForMissingInput
{
    use ReadsStringOptions;

    protected const array COLUMNS = ['email', 'purposes', 'list', 'locale', 'consented_at'];

    public function handle(ImportEntry $import): int
    {
        $withConsent = (bool) $this->option('with-consent');

        if ($withConsent === (bool) $this->option('request-confirmation')) {
            $this->fail('Pass either --with-consent or --request-confirmation.');
        }

        $source = $this->option('source');

        if (! is_string($source) || trim($source) === '' || mb_strlen(trim($source)) > ImportEntry::SOURCE_MAX_LENGTH) {
            $this->fail('Pass --source, at most '.ImportEntry::SOURCE_MAX_LENGTH.' characters, e.g. the tool or file the addresses come from.');
        }

        $project = $this->stringOption('project') ?? WaitlistEntry::DEFAULT_PROJECT;

        if (! Waitlist::hasProject($project)) {
            $this->fail("The project [{$project}] is not defined.");
        }

        $defaultList = $this->stringOption('list');
        $limit = $this->number('limit', minimum: 1);
        $fromLine = $this->number('from-line', minimum: 2) ?? 2;

        $path = $this->argument('file');
        $path = is_string($path) ? $path : '';

        try {
            $file = new SplFileObject($path, 'r');
        } catch (RuntimeException|LogicException) {
            $this->fail("[{$path}] cannot be read.");
        }

        $header = $this->header($file, $withConsent, $defaultList);
        $imported = $known = 0;
        $failed = [];
        $line = 1;

        while (! $file->eof()) {
            $row = $file->fgetcsv(',', '"', '');
            $line++;

            if (! is_array($row) || $row === [null] || $line < $fromLine) {
                continue;
            }

            if ($limit !== null && $imported >= $limit) {
                $this->info("Stopped at line {$line} after {$limit} imports. Resume there, not from the start, which would bring back addresses erased since:");
                $this->line($this->resumeCommand($path, $source, $withConsent, $defaultList, $project, $limit, $line));

                break;
            }

            try {
                $result = $this->importRow($import, $header, $row, $source, $withConsent, $defaultList, $project);
            } catch (InvalidConfigurationException $exception) {
                // A setup error stops the run; its message may name the list
                // from the row, which a misaligned row turns into an address.
                $this->error("Line {$line}: the import stopped on a setup error (".class_basename($exception).'), which waitlist:check or waitlist:privacy names. Fix it, then resume with:');
                $this->line($this->resumeCommand($path, $source, $withConsent, $defaultList, $project, $limit, $line));

                return self::FAILURE;
            } catch (InvalidArgumentException $exception) {
                // Only fixed reasons: a cell may hold an address, also one in the wrong column.
                $failed[] = "Line {$line}: {$exception->getMessage()}";

                continue;
            }

            $result === null ? $known++ : $imported++;
        }

        foreach ($failed as $message) {
            $this->error($message);
        }

        $this->info(sprintf('Imported %d, skipped %d already on the list, %d failed.', $imported, $known, count($failed)));

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, int> column => position
     */
    protected function header(SplFileObject $file, bool $withConsent, ?string $defaultList): array
    {
        $row = $file->fgetcsv(',', '"', '');
        $names = is_array($row) ? array_map(fn (?string $name): string => Str::lower(trim((string) $name)), $row) : [];

        if ($names !== []) {
            $names[0] = Str::chopStart($names[0], "\u{FEFF}");
        }

        $header = array_flip($names);
        $unknown = array_diff($names, self::COLUMNS);

        if ($unknown !== []) {
            // A file without a header row has an address up there.
            $named = array_map(
                fn (string $name, int $position): string => preg_match('/^[a-z_]{1,32}$/', $name) === 1 ? $name : 'column '.($position + 1),
                $unknown,
                array_keys($unknown),
            );

            $this->fail('The first line is no header row the import takes; it does not take: '.implode(', ', $named).'. It takes '.implode(', ', self::COLUMNS).'.');
        }

        $required = ['email', 'purposes', ...($withConsent ? ['consented_at'] : []), ...($defaultList === null ? ['list'] : [])];
        $missing = array_diff($required, $names);

        if ($missing !== []) {
            $this->fail('The file lacks the columns '.implode(', ', $missing).($defaultList === null && in_array('list', $missing, true) ? ', or pass --list' : '').'.');
        }

        if (! $withConsent && isset($header['consented_at'])) {
            $this->warn('The consented_at column is not read with --request-confirmation: every address gets the confirmation mail.');
        }

        return $header;
    }

    /**
     * Every refusal is an InvalidArgumentException with a fixed reason that
     * names the column, never its value.
     *
     * @param  array<string, int>  $header
     * @param  array<int, string|null>  $row
     *
     * @throws InvalidArgumentException
     */
    protected function importRow(ImportEntry $import, array $header, array $row, string $source, bool $withConsent, ?string $defaultList, string $project): ?SubscribeResult
    {
        if (count($row) !== count($header)) {
            throw new InvalidArgumentException('The row has '.count($row).' fields, the header '.count($header).'.');
        }

        $value = fn (string $column): string => isset($header[$column]) ? trim((string) $row[$header[$column]]) : '';
        $list = $value('list') !== '' ? $value('list') : $defaultList;

        if ($list === null) {
            throw new InvalidArgumentException('list: the row names no list.');
        }

        $purposes = $this->purposes($value('purposes'), $value('locale'));
        $consentedAt = $withConsent ? $this->consentedAt($value('consented_at')) : null;

        try {
            return $import(
                list: $list,
                email: $value('email'),
                purposes: $purposes,
                source: $source,
                consentedAt: $consentedAt,
                project: $project,
            );
        } catch (InvalidConfigurationException $exception) {
            throw $exception;
        } catch (WaitlistException|InvalidArgumentException $exception) {
            throw new InvalidArgumentException($this->reason($exception, $withConsent), previous: $exception);
        }
    }

    /**
     * The messages of the exceptions name the list, purpose or version they
     * were given, which in a misaligned row may be an address.
     */
    protected function reason(Throwable $exception, bool $withConsent): string
    {
        return match (true) {
            $exception instanceof InvalidEmailException => 'email: not a valid address.',
            $exception instanceof UnknownWaitlistException => 'list: not a list of the project.',
            $exception instanceof MissingConsentException => "purposes: the list's primary purpose is missing.",
            $exception instanceof UnknownPurposeException => 'purposes, locale: a purpose, version or locale the list does not offer, or a hash that does not match.',
            // The source and the time are checked before; without a time, the
            // action refuses only a list without double opt-in.
            $exception instanceof InvalidArgumentException && ! $withConsent => 'list: the list has no double opt-in, so only --with-consent can import to it.',
            default => 'the row was refused.',
        };
    }

    /**
     * "waitlist:2026-10, newsletter:2026-10": purpose and version, separated by
     * commas or spaces.
     *
     * @return array<string, array{version: string, locale: string|null}>
     */
    protected function purposes(string $value, string $locale): array
    {
        $purposes = [];

        foreach (preg_split('/[\s,]+/', $value, flags: PREG_SPLIT_NO_EMPTY) ?: [] as $choice) {
            if (! str_contains($choice, ':')) {
                throw new InvalidArgumentException('purposes: write each purpose as purpose:version.');
            }

            $purpose = Str::before($choice, ':');

            if (isset($purposes[$purpose])) {
                throw new InvalidArgumentException('purposes: a purpose is named twice.');
            }

            $purposes[$purpose] = ['version' => Str::after($choice, ':'), 'locale' => $locale !== '' ? $locale : null];
        }

        return $purposes;
    }

    /**
     * 2026-10-01, 2026-10-01 12:00, 2026-10-01T12:00:00 or with an offset;
     * without one, in the application's time zone. Words such as "now" and
     * dates that roll over, such as 2026-02-31, are refused: the time must be
     * the one the source recorded.
     */
    protected function consentedAt(string $value): Carbon
    {
        $refused = new InvalidArgumentException('consented_at: not a time of consent in the form 2026-10-01, 2026-10-01 12:00:00 or 2026-10-01T12:00:00+02:00.');

        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2})(?::\d{2})?)?(?:Z|[+-]\d{2}:\d{2})?$/', $value, $parts) !== 1) {
            throw $refused;
        }

        try {
            $time = Carbon::parse($value);
        } catch (Throwable) {
            throw $refused;
        }

        if ($time->format('Y-m-d') !== $parts[1] || (isset($parts[2]) && $time->format('H:i') !== $parts[2])) {
            throw $refused;
        }

        if ($time->isFuture() || $time->getTimestamp() < 1) {
            throw new InvalidArgumentException('consented_at: lies in the future or before 1970.');
        }

        return $time;
    }

    protected function resumeCommand(string $path, string $source, bool $withConsent, ?string $list, string $project, ?int $limit, int $line): string
    {
        return implode(' ', [
            'php artisan waitlist:import',
            escapeshellarg($path),
            '--source='.escapeshellarg($source),
            $withConsent ? '--with-consent' : '--request-confirmation',
            ...($list !== null ? ['--list='.escapeshellarg($list)] : []),
            '--project='.escapeshellarg($project),
            ...($limit !== null ? ["--limit={$limit}"] : []),
            "--from-line={$line}",
        ]);
    }

    protected function number(string $option, int $minimum): ?int
    {
        $value = $this->option($option);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! ctype_digit($value) || (int) $value < $minimum) {
            $this->fail("--{$option} takes a whole number of at least {$minimum}.");
        }

        return (int) $value;
    }
}
