<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Taldres\Waitlist\Actions\ExportPersonalData;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\PersonalData;

class ShowCommand extends Command
{
    protected $signature = 'waitlist:show
        {email : The email address to look up}
        {--list= : Only show entries on this waitlist (of the default project unless --project is given)}
        {--project= : Only show entries of this project}
        {--json : Output as JSON (suitable for data access requests)}
        {--pretty : Output as a human-readable summary}';

    protected $description = 'Show all personal data stored for an email address (right of access)';

    public function handle(ExportPersonalData $export): int
    {
        $email = $this->argument('email');

        if (! is_string($email) || $email === '') {
            $this->error('An email address is required.');

            return self::FAILURE;
        }

        $list = $this->option('list');
        $list = is_string($list) && $list !== '' ? $list : null;
        $project = $this->option('project');
        $project = is_string($project) && $project !== '' ? $project : null;

        $data = $export(email: $email, list: $list, project: $project);

        // Raw: the console would read tags in stored values as formatting.
        if ($this->option('json')) {
            $this->output->writeln(
                json_encode($data->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
                OutputInterface::OUTPUT_RAW,
            );

            return self::SUCCESS;
        }

        if ($data->isEmpty()) {
            $this->info("No entries found for {$email}{$this->scope($list, $project)}.");

            return self::SUCCESS;
        }

        if ($this->option('pretty')) {
            $data->each(fn (PersonalData $entry) => $this->renderPretty($entry));

            return self::SUCCESS;
        }

        foreach ($data as $entry) {
            $this->table(
                ['Field', 'Value'],
                collect($entry->toArray())->map(fn (mixed $value, string $key): array => [
                    $key,
                    OutputFormatter::escape(match (true) {
                        is_array($value) => (string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE),
                        is_scalar($value) => (string) $value,
                        default => '',
                    }),
                ])->values()->all(),
            );
        }

        return self::SUCCESS;
    }

    protected function renderPretty(PersonalData $entry): void
    {
        $this->newLine();
        $this->raw("Project: {$entry->project}");
        $this->raw("List: {$entry->list}");
        $this->raw("Email: {$entry->email}");
        $this->raw("Status: {$entry->status->value}");
        $this->raw('First seen: '.$entry->createdAt->format('Y-m-d H:i:s'));

        $this->newLine();
        $this->raw('Subscriptions');
        $this->raw('-------------');

        if ($entry->subscriptions === []) {
            $this->raw('None.');
        }

        foreach ($entry->subscriptions as $subscription) {
            $this->raw("#{$subscription->sequence} started ".$subscription->startedAt->format('Y-m-d H:i:s'));

            foreach ($subscription->consents as $consent) {
                $shown = $consent->locale !== null ? "{$consent->version}, {$consent->locale}" : $consent->version;
                $this->raw("  Consent to {$consent->purpose} ({$shown}): {$consent->text}");

                if ($consent->withdrawnAt !== null) {
                    $this->raw('    Withdrawn: '.$consent->withdrawnAt->format('Y-m-d H:i:s'));
                }
            }

            $this->raw('  Confirmed: '.($subscription->confirmedAt?->format('Y-m-d H:i:s') ?? '—'));
            $this->raw('  Ended: '.($subscription->endedAt?->format('Y-m-d H:i:s') ?? '—')
                .($subscription->endReason !== null ? ' ('.$subscription->endReason->value.')' : ''));
        }

        $this->newLine();
        $this->raw('Activity');
        $this->raw('--------');

        foreach ($entry->activity as $activity) {
            $line = ($activity->occurredAt?->format('Y-m-d H:i:s') ?? $activity->occurredOn->toDateString())
                .'  '.$activity->type->value
                .($activity->reference !== null ? "  [{$activity->reference}]" : '');

            if ($activity->ip !== null || $activity->userAgent !== null) {
                $line .= '  ('.($activity->ip ?? '—').' / '.($activity->userAgent ?? '—').')';
            }

            $this->raw($line);
        }

        if ($entry->metadata !== []) {
            $this->newLine();
            $this->raw('Metadata');
            $this->raw('--------');
            $this->raw((string) json_encode($entry->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }

    protected function scope(?string $list, ?string $project): string
    {
        return match (true) {
            $list !== null => ' on '.($project ?? WaitlistEntry::DEFAULT_PROJECT)."/{$list}",
            $project !== null => " in project {$project}",
            default => '',
        };
    }

    /**
     * The console would read tags in stored values as formatting.
     */
    protected function raw(string $line): void
    {
        $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
    }
}
