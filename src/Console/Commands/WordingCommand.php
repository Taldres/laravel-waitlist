<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Taldres\Waitlist\Actions\RegisterWording;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Definitions\ProjectDefinitions;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Exceptions\WordingConflictException;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistWording;
use Taldres\Waitlist\Support\StoredWordingCatalog;

class WordingCommand extends Command
{
    protected $signature = 'waitlist:wording
        {file? : JSON of purpose => version => wording, or => locale => wording}
        {--from-definitions : Register the wording the project definition holds, e.g. once when switching to the stored catalog}
        {--project=default : The project the wording belongs to}
        {--retire-missing : Retire the versions of the listed purposes that the file no longer has}';

    protected $description = 'Register the wording your frontend or CMS shows, e.g. on deploy';

    public function handle(RegisterWording $register): int
    {
        if (! app(ProjectCatalog::class) instanceof StoredWordingCatalog) {
            $this->warn(ConfigKey::Catalog->value.' does not read registered wording; set it to '.StoredWordingCatalog::class.' or a catalog of your own that does.');
        }

        $project = $this->option('project');
        $project = is_string($project) && $project !== '' ? $project : WaitlistEntry::DEFAULT_PROJECT;
        $file = $this->argument('file');

        if (is_string($file) === (bool) $this->option('from-definitions')) {
            $this->error('Pass a file or --from-definitions.');

            return self::FAILURE;
        }

        $wording = is_string($file) ? $this->fromFile($file) : $this->fromDefinitions($project);

        if (! is_array($wording) || ! $this->wellFormed($wording)) {
            $this->error(is_string($file) ? "[{$file}] is not a JSON object of purpose => version => wording." : "The definition of [{$project}] holds no wording.");

            return self::FAILURE;
        }

        try {
            [$registered, $retired] = DB::connection(WaitlistEntry::waitlistConnection())->transaction(
                fn (): array => $this->sync($register, $project, $wording),
            );
        } catch (WordingConflictException $exception) {
            $this->error($exception->getMessage().' Nothing was registered.');

            return self::FAILURE;
        }

        $this->info("Registered {$registered} wordings, retired {$retired} versions.");

        return self::SUCCESS;
    }

    /**
     * @param  array<array-key, array<array-key, string|array<string, string>>>  $wording
     * @return array{0: int, 1: int}
     */
    protected function sync(RegisterWording $register, string $project, array $wording): array
    {
        $registered = $retired = 0;

        foreach ($wording as $purpose => $versions) {
            foreach ($versions as $version => $texts) {
                $registered += $register($project, (string) $purpose, (string) $version, $texts);
            }

            if (! $this->option('retire-missing')) {
                continue;
            }

            $missing = WaitlistWording::query()
                ->where('project', $project)
                ->where('purpose', (string) $purpose)
                ->whereNull('retired_at')
                ->whereNotIn('version', array_map('strval', array_keys($versions)))
                ->distinct()
                ->get(['version']);

            foreach ($missing as $wording) {
                $retired += (int) ($register->retire($project, (string) $purpose, $wording->version) > 0);
            }
        }

        return [$registered, $retired];
    }

    protected function fromFile(string $file): mixed
    {
        return is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    }

    /**
     * Retired purposes, defined with no versions, are left out: an empty map
     * would retire every version with --retire-missing.
     *
     * @return array<string, array<string, string|array<string, string>>>|null
     */
    protected function fromDefinitions(string $project): ?array
    {
        $purposes = array_filter(app(ProjectDefinitions::class)->get($project)?->getPurposes() ?? []);

        return $purposes === [] ? null : $purposes;
    }

    /**
     * @param  array<array-key, mixed>  $wording
     *
     * @phpstan-assert-if-true array<array-key, array<array-key, string|array<string, string>>> $wording
     */
    protected function wellFormed(array $wording): bool
    {
        foreach ($wording as $versions) {
            // An empty map would retire every version with --retire-missing.
            if (! is_array($versions) || $versions === []) {
                return false;
            }

            foreach ($versions as $texts) {
                if (! is_string($texts) && ! (is_array($texts) && Arr::every($texts, fn (mixed $text): bool => is_string($text)))) {
                    return false;
                }
            }
        }

        return true;
    }
}
