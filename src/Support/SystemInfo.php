<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Support;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Facts about the running application: versions, environment and debug mode, the drivers
 * in use and the free disk space.
 */
final class SystemInfo
{
    /** Below this share of free space the disk is reported as low. */
    public const LOW_DISK_RATIO = 0.1;

    public static function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public static function laravelVersion(): string
    {
        return app()->version();
    }

    public static function alfakitVersion(): ?string
    {
        foreach (['alfasix/alfakit'] as $package) {
            try {
                if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package)) {
                    return InstalledVersions::getPrettyVersion($package);
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    public static function environment(): string
    {
        return (string) app()->environment();
    }

    public static function isProduction(): bool
    {
        return app()->isProduction();
    }

    public static function debug(): bool
    {
        return (bool) config('app.debug');
    }

    /**
     * `name (driver)` of the default connection or store of each service.
     *
     * @return array{queue: string, cache: string, session: string, mail: string, database: string}
     */
    public static function drivers(): array
    {
        return [
            'queue' => self::named(config('queue.default'), config('queue.connections.'.self::string(config('queue.default')).'.driver')),
            'cache' => self::named(config('cache.default'), config('cache.stores.'.self::string(config('cache.default')).'.driver')),
            'session' => self::string(config('session.driver')),
            'mail' => self::named(config('mail.default'), config('mail.mailers.'.self::string(config('mail.default')).'.transport')),
            'database' => self::named(config('database.default'), self::databaseDriver()),
        ];
    }

    /**
     * @return array{free: float, total: float, ratio: float}|null
     */
    public static function disk(): ?array
    {
        $path = base_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || $total === false || $total <= 0) {
            return null;
        }

        return ['free' => $free, 'total' => $total, 'ratio' => $free / $total];
    }

    private static function databaseDriver(): ?string
    {
        try {
            return DB::connection()->getDriverName();
        } catch (Throwable) {
            return null;
        }
    }

    private static function named(mixed $name, mixed $driver): string
    {
        $name = self::string($name);
        $driver = self::string($driver);

        return match (true) {
            $name === '' => $driver,
            $driver === '' || $driver === $name => $name,
            default => $name.' ('.$driver.')',
        };
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
