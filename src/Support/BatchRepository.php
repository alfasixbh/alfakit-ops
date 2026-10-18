<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Support;

use Carbon\CarbonImmutable;
use Illuminate\Bus\BatchRepository as LaravelBatchRepository;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Job batches from the `job_batches` table (`queue.batching`): progress, failures and
 * status, cancelling and retrying their failed jobs (`queue:retry-batch`).
 *
 * @phpstan-type BatchRow array{id: string, name: string, total: int, pending: int, processed: int, failed: int, progress: int, status: string, created_at: ?string, finished_at: ?string, cancelled_at: ?string}
 */
final class BatchRepository
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public static function isAvailable(): bool
    {
        try {
            // DynamoDB batches have no table to page through.
            return config('queue.batching.driver', 'database') !== 'dynamodb'
                && Schema::connection(self::connectionName())->hasTable(self::table());
        } catch (Throwable) {
            return false;
        }
    }

    public static function connectionName(): ?string
    {
        $connection = config('queue.batching.database');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public static function table(): string
    {
        $table = config('queue.batching.table');

        return is_string($table) && $table !== '' ? $table : 'job_batches';
    }

    public static function query(): Builder
    {
        return DB::connection(self::connectionName())->table(self::table());
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     * @return LengthAwarePaginator<string, BatchRow>
     */
    public static function paginate(?string $search, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        if (! self::isAvailable()) {
            return new LengthAwarePaginator([], 0, $perPage, $page);
        }

        $query = self::query();

        if (($search = trim((string) $search)) !== '') {
            $query->where(static fn (Builder $query): Builder => $query->where('name', 'like', '%'.$search.'%')->orWhere('id', 'like', '%'.$search.'%'));
        }

        $status = $filters['status']['value'] ?? null;

        match ($status) {
            self::STATUS_CANCELLED => $query->whereNotNull('cancelled_at'),
            self::STATUS_FINISHED => $query->whereNull('cancelled_at')->whereNotNull('finished_at')->where('failed_jobs', 0),
            self::STATUS_FAILED => $query->whereNull('cancelled_at')->where('failed_jobs', '>', 0),
            self::STATUS_RUNNING => $query->whereNull('cancelled_at')->whereNull('finished_at')->where('failed_jobs', 0),
            default => null,
        };

        $total = (clone $query)->count();

        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->forPage($page, $perPage)->get()
            ->mapWithKeys(static function (object $record): array {
                $row = self::toRow($record);

                return [$row['id'] => $row];
            })
            ->all();

        return new LengthAwarePaginator($rows, $total, $perPage, $page);
    }

    /**
     * @return BatchRow|null
     */
    public static function find(string $id): ?array
    {
        if (! self::isAvailable()) {
            return null;
        }

        $record = self::query()->where('id', $id)->first();

        return is_object($record) ? self::toRow($record) : null;
    }

    public static function runningCount(): int
    {
        if (! self::isAvailable()) {
            return 0;
        }

        try {
            return self::query()->whereNull('cancelled_at')->whereNull('finished_at')->count();
        } catch (Throwable) {
            return 0;
        }
    }

    public static function cancel(string $id): bool
    {
        $batch = app(LaravelBatchRepository::class)->find($id);

        if ($batch === null || $batch->cancelled()) {
            return false;
        }

        $batch->cancel();

        return true;
    }

    /**
     * Push the batch's failed jobs back onto their queues (`queue:retry-batch`).
     */
    public static function retryFailedJobs(string $id): int
    {
        $batch = self::find($id);

        if ($batch === null || $batch['failed'] === 0) {
            return 0;
        }

        Artisan::call('queue:retry-batch', ['id' => [$id]]);

        return $batch['failed'];
    }

    /**
     * @return BatchRow
     */
    public static function toRow(object $record): array
    {
        $data = (array) $record;
        $total = (int) ($data['total_jobs'] ?? 0);
        $pending = (int) ($data['pending_jobs'] ?? 0);
        $failed = (int) ($data['failed_jobs'] ?? 0);
        $processed = max(0, $total - $pending);
        $timestamp = static fn (mixed $value): ?string => is_numeric($value)
            ? CarbonImmutable::createFromTimestamp((int) $value, (string) config('app.timezone', 'UTC'))->toDateTimeString()
            : null;
        $cancelledAt = $timestamp($data['cancelled_at'] ?? null);
        $finishedAt = $timestamp($data['finished_at'] ?? null);

        return [
            'id' => is_scalar($data['id'] ?? null) ? (string) $data['id'] : '',
            'name' => is_scalar($data['name'] ?? null) ? (string) $data['name'] : '',
            'total' => $total,
            'pending' => $pending,
            'processed' => $processed,
            'failed' => $failed,
            'progress' => $total > 0 ? (int) round($processed / $total * 100) : 0,
            'status' => match (true) {
                $cancelledAt !== null => self::STATUS_CANCELLED,
                $failed > 0 => self::STATUS_FAILED,
                $finishedAt !== null => self::STATUS_FINISHED,
                default => self::STATUS_RUNNING,
            },
            'created_at' => $timestamp($data['created_at'] ?? null),
            'finished_at' => $finishedAt,
            'cancelled_at' => $cancelledAt,
        ];
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_FINISHED => 'success',
            self::STATUS_FAILED => 'danger',
            self::STATUS_CANCELLED => 'gray',
            default => 'info',
        };
    }

    public static function statusIcon(string $status): string
    {
        return match ($status) {
            self::STATUS_FINISHED => 'circle-check',
            self::STATUS_FAILED => 'circle-x',
            self::STATUS_CANCELLED => 'ban',
            default => 'loader',
        };
    }
}
