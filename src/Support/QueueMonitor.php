<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Support;

use AlfaSix\AlfakitOps\OpsPlugin;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ClearableQueue;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Pending, delayed and reserved job counts per queue, read from the queue connections
 * themselves (`size()`, `pendingSize()`…). Database connections list every queue that has
 * jobs in their table; Redis connections are only asked when the Redis extension or
 * Predis is installed. A connection that can't be reached shows an error instead of
 * breaking the page.
 *
 * @phpstan-type QueueRow array{id: string, connection: string, driver: string, queue: string, size: ?int, pending: ?int, delayed: ?int, reserved: ?int, oldest: ?string, error: ?string, clearable: bool}
 */
final class QueueMonitor
{
    /** Drivers that never hold jobs. */
    private const IGNORED_DRIVERS = ['sync', 'null', 'deferred', 'background', 'failover'];

    /**
     * @return array<string, QueueRow>
     */
    public static function rows(): array
    {
        $rows = [];

        foreach (self::monitoredQueues() as $connection => $queues) {
            foreach ($queues as $queue) {
                $row = self::row($connection, $queue);
                $rows[$row['id']] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return QueueRow|null
     */
    public static function find(string $key): ?array
    {
        return self::rows()[$key] ?? null;
    }

    public static function key(string $connection, string $queue): string
    {
        return substr(hash('sha256', $connection.'|'.$queue), 0, 16);
    }

    public static function totalPending(): int
    {
        return array_sum(array_map(static fn (array $row): int => $row['pending'] ?? $row['size'] ?? 0, self::rows()));
    }

    /**
     * The queues to list, by connection: the plugin's `monitorQueues()`, or the default
     * connection's queue plus the queues found in database connections' tables.
     *
     * @return array<string, list<string>>
     */
    public static function monitoredQueues(): array
    {
        $configured = OpsPlugin::get()->getMonitoredQueues();
        $queues = [];

        if ($configured !== null) {
            foreach ($configured as $connection => $names) {
                if (self::isMonitorable($connection)) {
                    $queues[$connection] = array_values(array_unique(array_filter($names, static fn (string $name): bool => $name !== '')));
                }
            }

            return array_filter($queues);
        }

        $default = config('queue.default');

        if (is_string($default) && self::isMonitorable($default)) {
            $queues[$default] = [self::defaultQueue($default)];
        }

        foreach ((array) config('queue.connections', []) as $connection => $config) {
            if (is_string($connection) && is_array($config) && ($config['driver'] ?? null) === 'database' && self::isMonitorable($connection)) {
                $queues[$connection] = array_values(array_unique([...($queues[$connection] ?? []), ...self::databaseQueues($connection)]));
            }
        }

        foreach ($queues as $connection => $names) {
            sort($names);
            $queues[$connection] = $names;
        }

        return array_filter($queues);
    }

    public static function driver(string $connection): ?string
    {
        $driver = config("queue.connections.{$connection}.driver");

        return is_string($driver) ? $driver : null;
    }

    public static function isMonitorable(string $connection): bool
    {
        $driver = self::driver($connection);

        if ($driver === null || in_array($driver, self::IGNORED_DRIVERS, true)) {
            return false;
        }

        // Redis needs phpredis or Predis; without either there is nothing to ask.
        if ($driver === 'redis') {
            return extension_loaded('redis') || class_exists(\Predis\Client::class);
        }

        return true;
    }

    public static function defaultQueue(string $connection): string
    {
        $queue = config("queue.connections.{$connection}.queue");

        return is_string($queue) && $queue !== '' ? $queue : 'default';
    }

    /**
     * The distinct queues of a database connection's jobs table.
     *
     * @return list<string>
     */
    public static function databaseQueues(string $connection): array
    {
        try {
            $queue = app('queue')->connection($connection);

            if (! $queue instanceof DatabaseQueue) {
                return [];
            }

            $table = config("queue.connections.{$connection}.table", 'jobs');
            $database = $queue->getDatabase();

            if (! is_string($table) || ! Schema::connection($database->getName())->hasTable($table)) {
                return [];
            }

            return $database->table($table)->distinct()->orderBy('queue')->limit(100)->pluck('queue')
                ->filter(static fn (mixed $name): bool => is_string($name) && $name !== '')
                ->map(static fn (mixed $name): string => (string) $name)
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return QueueRow
     */
    public static function row(string $connection, string $queue): array
    {
        $row = [
            'id' => self::key($connection, $queue),
            'connection' => $connection,
            'driver' => self::driver($connection) ?? '',
            'queue' => $queue,
            'size' => null,
            'pending' => null,
            'delayed' => null,
            'reserved' => null,
            'oldest' => null,
            'error' => null,
            'clearable' => false,
        ];

        try {
            $instance = app('queue')->connection($connection);
            $row['size'] = $instance->size($queue);
            $row['clearable'] = $instance instanceof ClearableQueue;

            if ($instance instanceof DatabaseQueue || $instance instanceof RedisQueue) {
                $row['pending'] = (int) $instance->pendingSize($queue);
                $row['delayed'] = (int) $instance->delayedSize($queue);
                $row['reserved'] = (int) $instance->reservedSize($queue);
                $oldest = $instance->creationTimeOfOldestPendingJob($queue);
                $row['oldest'] = is_numeric($oldest) ? CarbonImmutable::createFromTimestamp((int) $oldest, (string) config('app.timezone', 'UTC'))->toDateTimeString() : null;
            }
        } catch (Throwable $exception) {
            $row['error'] = mb_substr($exception->getMessage(), 0, 300);
        }

        return $row;
    }

    /**
     * Delete every job of the queue. Returns how many were deleted.
     */
    public static function clear(string $connection, string $queue): int
    {
        $instance = app('queue')->connection($connection);

        if (! $instance instanceof ClearableQueue) {
            return 0;
        }

        return (int) $instance->clear($queue);
    }
}
