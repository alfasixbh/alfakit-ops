<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Cache tasks run through Artisan, with whether each cache is currently built, and
 * maintenance mode (`down --secret` / `up`).
 *
 * @phpstan-type TaskRow array{id: string, command: string, cache_command: ?string, status: ?bool}
 */
final class MaintenanceTasks
{
    /**
     * The clear commands, keyed by task, with the command that builds the cache again.
     *
     * @var array<string, array{0: string, 1: ?string}>
     */
    public const TASKS = [
        'cache' => ['cache:clear', null],
        'config' => ['config:clear', 'config:cache'],
        'routes' => ['route:clear', 'route:cache'],
        'views' => ['view:clear', 'view:cache'],
        'events' => ['event:clear', 'event:cache'],
        'compiled' => ['clear-compiled', null],
        'alfakit' => ['alfakit:optimize-clear', 'alfakit:optimize'],
        'schedule' => ['schedule:clear-cache', null],
    ];

    /**
     * @return array<string, TaskRow>
     */
    public static function rows(): array
    {
        $rows = [];
        $commands = Artisan::all();

        foreach (self::TASKS as $id => [$command, $cacheCommand]) {
            if (! isset($commands[$command])) {
                continue;
            }

            $rows[$id] = [
                'id' => $id,
                'command' => $command,
                'cache_command' => $cacheCommand !== null && isset($commands[$cacheCommand]) ? $cacheCommand : null,
                'status' => self::status($id),
            ];
        }

        return $rows;
    }

    /**
     * @return TaskRow|null
     */
    public static function find(string $id): ?array
    {
        return self::rows()[$id] ?? null;
    }

    /**
     * Whether the cache is built (true), empty (false) or unknown (null).
     */
    public static function status(string $id): ?bool
    {
        try {
            return match ($id) {
                'config' => app()->configurationIsCached(),
                'routes' => app()->routesAreCached(),
                'events' => app()->eventsAreCached(),
                'compiled' => is_file(app()->getCachedServicesPath()) || is_file(app()->getCachedPackagesPath()),
                'views' => self::hasFiles(config('view.compiled')),
                'alfakit' => self::hasFiles(config('alfakit.cache_path')),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    private static function hasFiles(mixed $directory): ?bool
    {
        if (! is_string($directory) || $directory === '') {
            return null;
        }

        return is_dir($directory) && File::files($directory) !== [];
    }

    /**
     * Run an Artisan command; returns its exit code and output.
     *
     * @return array{int, string}
     */
    public static function call(string $command): array
    {
        try {
            $exitCode = Artisan::call($command);

            return [$exitCode, trim(Artisan::output())];
        } catch (Throwable $exception) {
            return [1, $exception->getMessage()];
        }
    }

    public static function isDown(): bool
    {
        return app()->isDownForMaintenance();
    }

    /**
     * The data of the active maintenance mode (`secret`, `retry`, `status`…), or null.
     *
     * @return array<string, mixed>|null
     */
    public static function maintenanceData(): ?array
    {
        if (! self::isDown()) {
            return null;
        }

        try {
            return app()->maintenanceMode()->data();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The URL that sets the bypass cookie, when maintenance mode has a secret.
     */
    public static function bypassUrl(): ?string
    {
        $secret = self::maintenanceData()['secret'] ?? null;

        return is_string($secret) && $secret !== '' ? url($secret) : null;
    }

    /**
     * Put the application into maintenance mode (`down --secret=…`).
     *
     * @return array{int, string}
     */
    public static function down(string $secret, ?int $retry = null, ?int $refresh = null): array
    {
        $options = ['--secret' => $secret];

        if ($retry !== null && $retry > 0) {
            $options['--retry'] = $retry;
        }

        if ($refresh !== null && $refresh > 0) {
            $options['--refresh'] = $refresh;
        }

        try {
            $exitCode = Artisan::call('down', $options);

            return [$exitCode, trim(Artisan::output())];
        } catch (Throwable $exception) {
            return [1, $exception->getMessage()];
        }
    }

    /**
     * @return array{int, string}
     */
    public static function up(): array
    {
        return self::call('up');
    }
}
