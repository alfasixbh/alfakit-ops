<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Support;

use AlfaSix\AlfakitOps\OpsPlugin;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Queue\Failed\DatabaseFailedJobProvider;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Failed jobs from Laravel's failer (`queue.failer`). Database failers are queried directly
 * (search, filters, sorting and paging in SQL); any other failer (file, DynamoDB) is read
 * through `all()` and filtered in memory. Retrying uses `queue:retry`, so it behaves
 * exactly like the command.
 *
 * Rows: `id` (the failer's key: the UUID for `database-uuids`), `uuid`, `connection`,
 * `queue`, `job` (display name), `exception_summary`, `exception`, `payload` (raw JSON),
 * `attempts`, `failed_at`.
 *
 * @phpstan-type FailedJobRow array{id: string, uuid: ?string, connection: string, queue: string, job: string, exception_summary: string, exception: string, payload: string, attempts: ?int, failed_at: ?string}
 */
final class FailedJobRepository
{
    public static function provider(): ?FailedJobProviderInterface
    {
        try {
            return app('queue.failer');
        } catch (Throwable) {
            return null;
        }
    }

    public static function usesDatabase(): bool
    {
        $provider = self::provider();

        return $provider instanceof DatabaseFailedJobProvider || $provider instanceof DatabaseUuidFailedJobProvider;
    }

    /**
     * Whether failed jobs can be listed at all (a failer is configured and, for database
     * failers, its table exists).
     */
    public static function isAvailable(): bool
    {
        $provider = self::provider();

        if ($provider === null || $provider instanceof \Illuminate\Queue\Failed\NullFailedJobProvider) {
            return false;
        }

        if (! self::usesDatabase()) {
            return true;
        }

        try {
            return Schema::connection(self::connectionName())->hasTable(self::table());
        } catch (Throwable) {
            return false;
        }
    }

    public static function connectionName(): ?string
    {
        $connection = config('queue.failed.database');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public static function table(): string
    {
        $table = config('queue.failed.table');

        return is_string($table) && $table !== '' ? $table : 'failed_jobs';
    }

    public static function query(): Builder
    {
        return DB::connection(self::connectionName())->table(self::table());
    }

    protected static function keyColumn(): string
    {
        return self::provider() instanceof DatabaseUuidFailedJobProvider ? 'uuid' : 'id';
    }

    public static function count(): int
    {
        if (! self::isAvailable()) {
            return 0;
        }

        try {
            return self::usesDatabase() ? self::query()->count() : count(self::provider()?->all() ?? []);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * One page of failed jobs, newest first unless sorted otherwise.
     *
     * @param  array<string, array<string, mixed>>  $filters
     * @return LengthAwarePaginator<string, FailedJobRow>
     */
    public static function paginate(?string $search, array $filters, ?string $sortColumn, ?string $sortDirection, int $page, int $perPage): LengthAwarePaginator
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        if (! self::isAvailable()) {
            return new LengthAwarePaginator([], 0, $perPage, $page);
        }

        if (! self::usesDatabase()) {
            $rows = self::filterInMemory(self::allRows(), $search, $filters, $sortColumn, $sortDirection);

            return new LengthAwarePaginator($rows->forPage($page, $perPage)->all(), $rows->count(), $perPage, $page);
        }

        $query = self::filteredQuery($search, $filters);
        $total = (clone $query)->count();
        $column = in_array($sortColumn, ['queue', 'connection', 'failed_at'], true) ? $sortColumn : 'failed_at';
        $direction = $sortDirection === 'asc' ? 'asc' : 'desc';

        $rows = $query
            ->orderBy($column, $direction)
            ->orderBy('id', $direction)
            ->forPage($page, $perPage)
            ->get()
            ->mapWithKeys(static function (object $record): array {
                $row = self::toRow($record);

                return [$row['id'] => $row];
            })
            ->all();

        return new LengthAwarePaginator($rows, $total, $perPage, $page);
    }

    /**
     * The keys of every failed job matching the search and filters ("select all").
     *
     * @param  array<string, array<string, mixed>>  $filters
     * @return list<string>
     */
    public static function keys(?string $search = null, array $filters = []): array
    {
        if (! self::isAvailable()) {
            return [];
        }

        if (! self::usesDatabase()) {
            return array_values(array_map(strval(...), self::filterInMemory(self::allRows(), $search, $filters, null, null)->keys()->all()));
        }

        return self::filteredQuery($search, $filters)->orderByDesc('id')->pluck(self::keyColumn())->map(static fn (mixed $key): string => is_scalar($key) ? (string) $key : '')->filter()->values()->all();
    }

    /**
     * @return FailedJobRow|null
     */
    public static function find(string $key): ?array
    {
        if (! self::isAvailable()) {
            return null;
        }

        try {
            $record = self::provider()?->find($key);
        } catch (Throwable) {
            return null;
        }

        return is_object($record) ? self::toRow($record) : null;
    }

    /**
     * @param  array<string>  $keys
     * @return array<string, FailedJobRow>
     */
    public static function findMany(array $keys): array
    {
        $rows = [];

        foreach (array_unique($keys) as $key) {
            if (($row = self::find($key)) !== null) {
                $rows[$row['id']] = $row;
            }
        }

        return $rows;
    }

    /**
     * Push failed jobs back onto their queues (`queue:retry`). Returns how many existed.
     *
     * @param  array<string>  $keys
     */
    public static function retry(array $keys): int
    {
        $retried = 0;

        // One by one: a job Laravel can't rebuild (a closure dispatched from tinker) makes
        // `queue:retry` throw. Report it and go on, so the action doesn't fail as a whole.
        foreach (array_keys(self::findMany($keys)) as $id) {
            try {
                Artisan::call('queue:retry', ['id' => [$id]]);
                $retried++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $retried;
    }

    /**
     * Retry every failed job (`queue:retry all`). Returns how many there were.
     */
    public static function retryAll(): int
    {
        $count = self::count();

        if ($count > 0) {
            Artisan::call('queue:retry', ['id' => ['all']]);
        }

        return $count;
    }

    /**
     * @param  array<string>  $keys
     */
    public static function forget(array $keys): int
    {
        $provider = self::provider();
        $count = 0;

        foreach (array_unique($keys) as $key) {
            if ($provider?->forget($key)) {
                $count++;
            }
        }

        return $count;
    }

    public static function flush(): int
    {
        $count = self::count();
        self::provider()?->flush();

        return $count;
    }

    /**
     * @return array<string, string>
     */
    public static function queueOptions(): array
    {
        return self::distinct('queue');
    }

    /**
     * @return array<string, string>
     */
    public static function connectionOptions(): array
    {
        return self::distinct('connection');
    }

    /**
     * The job classes of the latest 500 failed jobs.
     *
     * @return array<string, string>
     */
    public static function jobOptions(): array
    {
        if (! self::isAvailable()) {
            return [];
        }

        $payloads = self::usesDatabase()
            ? self::query()->orderByDesc('id')->limit(500)->pluck('payload')->all()
            : self::allRows()->take(500)->pluck('payload')->all();

        $options = [];

        foreach ($payloads as $payload) {
            $name = is_string($payload) ? self::displayName($payload) : '';

            if ($name !== '') {
                $options[$name] = $name;
            }
        }

        ksort($options);

        return $options;
    }

    /**
     * @return array<string, string>
     */
    protected static function distinct(string $column): array
    {
        if (! self::isAvailable()) {
            return [];
        }

        $values = self::usesDatabase()
            ? self::query()->distinct()->orderBy($column)->limit(200)->pluck($column)->all()
            : self::allRows()->pluck($column)->unique()->sort()->values()->all();

        $options = [];

        foreach ($values as $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $options[(string) $value] = (string) $value;
            }
        }

        return $options;
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     */
    protected static function filteredQuery(?string $search, array $filters): Builder
    {
        $query = self::query();

        if (($search = trim((string) $search)) !== '') {
            $query->where(static function (Builder $query) use ($search): void {
                $like = '%'.$search.'%';

                $query->where('uuid', 'like', $like)
                    ->orWhere('queue', 'like', $like)
                    ->orWhere('connection', 'like', $like)
                    ->orWhere('exception', 'like', $like)
                    ->orWhere('payload', 'like', $like);
            });
        }

        if (($queue = self::filterValue($filters, 'queue')) !== null) {
            $query->where('queue', $queue);
        }

        if (($connection = self::filterValue($filters, 'connection')) !== null) {
            $query->where('connection', $connection);
        }

        if (($job = self::filterValue($filters, 'job')) !== null) {
            // The payload's JSON has `"displayName":"App\\Jobs\\SendInvoice"`; `!` escapes
            // LIKE wildcards on every supported database.
            $needle = '"displayName":'.json_encode($job, JSON_UNESCAPED_UNICODE);
            $query->whereRaw('payload like ? escape ?', ['%'.addcslashes($needle, '%_!').'%', '!']);
        }

        [$from, $until] = self::dateRange($filters);

        if ($from !== null) {
            $query->where('failed_at', '>=', $from->toDateTimeString());
        }

        if ($until !== null) {
            $query->where('failed_at', '<=', $until->toDateTimeString());
        }

        return $query;
    }

    /**
     * @return Collection<string, FailedJobRow>
     */
    protected static function allRows(): Collection
    {
        try {
            $records = self::provider()?->all() ?? [];
        } catch (Throwable) {
            $records = [];
        }

        $rows = [];

        foreach ($records as $record) {
            if (is_object($record)) {
                $row = self::toRow($record);
                $rows[$row['id']] = $row;
            }
        }

        return collect($rows);
    }

    /**
     * @param  Collection<string, FailedJobRow>  $rows
     * @param  array<string, array<string, mixed>>  $filters
     * @return Collection<string, FailedJobRow>
     */
    protected static function filterInMemory(Collection $rows, ?string $search, array $filters, ?string $sortColumn, ?string $sortDirection): Collection
    {
        $search = trim((string) $search);
        $queue = self::filterValue($filters, 'queue');
        $connection = self::filterValue($filters, 'connection');
        $job = self::filterValue($filters, 'job');
        [$from, $until] = self::dateRange($filters);

        $rows = $rows->filter(static function (array $row) use ($search, $queue, $connection, $job, $from, $until): bool {
            if ($search !== '' && ! str_contains(mb_strtolower(implode("\n", [$row['uuid'] ?? '', $row['queue'], $row['connection'], $row['exception'], $row['payload']])), mb_strtolower($search))) {
                return false;
            }

            $failedAt = $row['failed_at'] !== null ? CarbonImmutable::parse($row['failed_at']) : null;

            return ($queue === null || $row['queue'] === $queue)
                && ($connection === null || $row['connection'] === $connection)
                && ($job === null || $row['job'] === $job)
                && ($from === null || ($failedAt !== null && $failedAt->greaterThanOrEqualTo($from)))
                && ($until === null || ($failedAt !== null && $failedAt->lessThanOrEqualTo($until)));
        });

        $column = in_array($sortColumn, ['queue', 'connection', 'failed_at'], true) ? $sortColumn : 'failed_at';

        return $sortDirection === 'asc' ? $rows->sortBy($column) : $rows->sortByDesc($column);
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     */
    protected static function filterValue(array $filters, string $name): ?string
    {
        $value = $filters[$name]['value'] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * The `failed_at` filter's dates, read in the panel's timezone and converted to the
     * application's (the timezone `failed_at` is stored in).
     *
     * @param  array<string, array<string, mixed>>  $filters
     * @return array{?CarbonImmutable, ?CarbonImmutable}
     */
    protected static function dateRange(array $filters): array
    {
        $timezone = alfakit()->getTimezone();
        $appTimezone = (string) config('app.timezone', 'UTC');
        $parse = static function (mixed $value, bool $end) use ($timezone, $appTimezone): ?CarbonImmutable {
            if (! is_string($value) || $value === '') {
                return null;
            }

            try {
                $date = CarbonImmutable::parse($value, $timezone);
            } catch (Throwable) {
                return null;
            }

            return ($end ? $date->endOfDay() : $date->startOfDay())->setTimezone($appTimezone);
        };

        return [
            $parse($filters['failed_at']['from'] ?? null, false),
            $parse($filters['failed_at']['until'] ?? null, true),
        ];
    }

    /**
     * @return FailedJobRow
     */
    public static function toRow(object $record): array
    {
        $data = (array) $record;
        $payload = is_string($data['payload'] ?? null) ? $data['payload'] : '';
        $exception = is_string($data['exception'] ?? null) ? $data['exception'] : '';
        $decoded = json_decode($payload, true);
        $decoded = is_array($decoded) ? $decoded : [];
        // Rows read from the table carry both keys; the UUID failer is keyed by `uuid`.
        $id = self::keyColumn() === 'uuid' && isset($data['uuid']) ? $data['uuid'] : ($data['id'] ?? null);
        $failedAt = $data['failed_at'] ?? null;
        $uuid = $data['uuid'] ?? $decoded['uuid'] ?? null;

        return [
            'id' => is_scalar($id) ? (string) $id : '',
            'uuid' => is_string($uuid) ? $uuid : null,
            'connection' => is_scalar($data['connection'] ?? null) ? (string) $data['connection'] : '',
            'queue' => is_scalar($data['queue'] ?? null) ? (string) $data['queue'] : '',
            'job' => self::displayName($payload),
            'exception_summary' => self::exceptionSummary($exception),
            'exception' => $exception,
            'payload' => $payload,
            'attempts' => is_int($decoded['attempts'] ?? null) ? $decoded['attempts'] : null,
            'failed_at' => match (true) {
                $failedAt instanceof DateTimeInterface => CarbonImmutable::instance($failedAt)->toDateTimeString(),
                is_scalar($failedAt) => (string) $failedAt,
                default => null,
            },
        ];
    }

    public static function displayName(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return '';
        }

        $name = $decoded['displayName'] ?? $decoded['job'] ?? '';

        return is_string($name) ? $name : '';
    }

    /**
     * The exception's first line without the `in /path/to/File.php:12` suffix.
     */
    public static function exceptionSummary(string $exception): string
    {
        $line = trim(strtok($exception, "\n") ?: '');
        $line = (string) preg_replace('/ in \S+:\d+$/', '', $line);

        return mb_strlen($line) > 300 ? mb_substr($line, 0, 300).'…' : $line;
    }

    /**
     * The payload as pretty JSON with every sensitive value hidden. The serialized
     * `data.command` is read without `unserialize()` and shown as a tree; a command that
     * can't be read is hidden when it mentions a sensitive key.
     */
    public static function presentPayload(string $payload, ?Redactor $redactor = null): string
    {
        $redactor ??= new Redactor(OpsPlugin::get()->getRedactedKeys());
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return $redactor->mentionsSensitiveKey($payload) ? Redactor::placeholder() : $payload;
        }

        $command = $decoded['data']['command'] ?? null;

        if (is_string($command) && is_array($decoded['data'] ?? null)) {
            $parsed = SerializedValue::looksSerialized($command) ? SerializedValue::parse($command) : null;

            $decoded['data']['command'] = match (true) {
                $parsed !== null => $parsed,
                $redactor->mentionsSensitiveKey($command) => Redactor::placeholder(),
                default => $command,
            };
        }

        return (string) json_encode($redactor->redact($decoded), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
