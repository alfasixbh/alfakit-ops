<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Support;

use AlfaSix\AlfakitOps\OpsPlugin;
use Carbon\CarbonImmutable;

/**
 * The log files of the configured directory (`OpsPlugin::logDirectory()`). A file is
 * addressed by its name only, and every lookup goes through `resolve()`: the name must be
 * a plain file name matching the configured patterns, and its real path (symbolic links
 * resolved) must be a file directly inside the real log directory. Nothing else can be
 * read, downloaded or deleted.
 *
 * @phpstan-type LogFileRow array{id: string, name: string, size: int, modified_at: string, type: string}
 */
final class LogFiles
{
    public static function directory(): ?string
    {
        $directory = realpath(OpsPlugin::get()->getLogDirectory());

        return $directory !== false && is_dir($directory) ? $directory : null;
    }

    /**
     * Every log file, newest first, keyed by name.
     *
     * @return array<string, LogFileRow>
     */
    public static function all(): array
    {
        $directory = self::directory();

        if ($directory === null) {
            return [];
        }

        $rows = [];

        foreach (scandir($directory) ?: [] as $name) {
            $path = self::resolve($name);

            if ($path === null) {
                continue;
            }

            $rows[$name] = [
                'id' => $name,
                'name' => $name,
                'size' => (int) filesize($path),
                'modified_at' => CarbonImmutable::createFromTimestamp((int) filemtime($path), (string) config('app.timezone', 'UTC'))->toDateTimeString(),
                'type' => preg_match('/-\d{4}-\d{2}-\d{2}\.[^.]+$/', $name) === 1 ? 'daily' : 'single',
            ];
        }

        uasort($rows, static fn (array $a, array $b): int => [$b['modified_at'], $a['name']] <=> [$a['modified_at'], $b['name']]);

        return $rows;
    }

    /**
     * @return LogFileRow|null
     */
    public static function find(string $name): ?array
    {
        return self::all()[$name] ?? null;
    }

    public static function isValidName(string $name): bool
    {
        if ($name === '' || strlen($name) > 255 || $name !== basename($name) || str_contains($name, '..') || preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]*$/', $name) !== 1) {
            return false;
        }

        foreach (OpsPlugin::get()->getLogFilePatterns() as $pattern) {
            if (fnmatch($pattern, $name, FNM_PERIOD)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The real path of the log file `$name`, or null when the name isn't a listed log file
     * of the directory (path traversal, a symbolic link out of it, another file type).
     */
    public static function resolve(string $name): ?string
    {
        $directory = self::directory();

        if ($directory === null || ! self::isValidName($name)) {
            return null;
        }

        $path = realpath($directory.DIRECTORY_SEPARATOR.$name);

        if ($path === false || ! is_file($path) || ! is_readable($path) || dirname($path) !== $directory) {
            return null;
        }

        return $path;
    }

    public static function delete(string $name): bool
    {
        $path = self::resolve($name);

        return $path !== null && @unlink($path);
    }

    /**
     * Empty the file (keeps it, so open handles of running processes stay valid).
     */
    public static function clear(string $name): bool
    {
        $path = self::resolve($name);

        if ($path === null || ! is_writable($path)) {
            return false;
        }

        $handle = fopen($path, 'r+b');

        if ($handle === false) {
            return false;
        }

        try {
            return ftruncate($handle, 0);
        } finally {
            fclose($handle);
        }
    }
}
