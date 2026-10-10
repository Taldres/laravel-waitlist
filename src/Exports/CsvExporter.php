<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Exports;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Stringable;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\ResolvesModel;

class CsvExporter
{
    use ResolvesModel;

    /**
     * Anything outside this list is refused, so a stray config entry cannot
     * write tokens into a file that gets mailed around.
     *
     * @var list<string>
     */
    public const array EXPORTABLE = [
        'id',
        'project',
        'list',
        'email',
        'status',
        'purposes',
        'confirmed_at',
        'unsubscribed_at',
        'metadata',
        'created_at',
        'updated_at',
    ];

    public function export(string $list, ?EntryStatus $status, string $path, string $project = WaitlistEntry::DEFAULT_PROJECT): int
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new RuntimeException("Unable to open [{$path}] for writing.");
        }

        try {
            return $this->write(handle: $handle, list: $list, status: $status, project: $project);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    public function write(mixed $handle, string $list, ?EntryStatus $status = null, string $project = WaitlistEntry::DEFAULT_PROJECT): int
    {
        $export = WaitlistConfig::export();
        $columns = $export->columns;

        fputcsv($handle, $columns, escape: '');

        $query = static::modelClass()::query()
            ->with('latestSubscription.consents') // backs the projection accessors without N+1
            ->onList($list, $project)
            // No order of its own: lazyById() pages by id, and a second order
            // ahead of it would skip or repeat rows across chunks.
            ->when($status, fn (Builder $query): Builder => $query->where('status', $status));

        $rows = 0;

        $query->lazyById(500)->each(function (WaitlistEntry $entry) use ($handle, $columns, $export, &$rows) {
            // No escape character: RFC 4180 readers only know doubled quotes.
            fputcsv($handle, array_map(
                fn (string $column) => $this->stringify($entry->getAttribute($column), $export->spreadsheetSafe),
                $columns,
            ), escape: '');
            $rows++;
        });

        return $rows;
    }

    private function stringify(mixed $value, bool $spreadsheetSafe): string
    {
        $string = match (true) {
            $value === null => '',
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_array($value) => (string) json_encode($value),
            is_scalar($value) || $value instanceof Stringable => (string) $value,
            default => '',
        };

        return $spreadsheetSafe ? $this->neutralizeFormula($string) : $string;
    }

    /**
     * Spreadsheets evaluate cells starting with =, +, -, @, tab or CR even when
     * quoted; a leading apostrophe forces text. An email address may legally
     * start with one of these.
     */
    private function neutralizeFormula(string $value): string
    {
        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }
}
