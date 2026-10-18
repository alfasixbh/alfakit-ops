<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Support;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The application's scheduled tasks (`Schedule::events()`), their next run in the panel's
 * timezone, their last run (recorded from the scheduler's events and from "Run now") and
 * running one now.
 *
 * In a web request the schedule isn't defined yet (it is registered when Artisan starts),
 * so the console kernel is bootstrapped first, exactly as `schedule:list` does.
 *
 * @phpstan-type TaskRow array{id: string, command: string, description: ?string, type: string, expression: string, frequency: ?string, next_due: ?string, timezone: string, without_overlapping: bool, on_one_server: bool, in_background: bool, in_maintenance: bool, environments: list<string>, last_run: ?array<string, mixed>}
 * @phpstan-type RunResult array{status: string, exit_code: ?int, output: ?string, runtime: ?float, finished_at: string, source: string, user: ?string}
 */
final class ScheduledTasks
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /** Captured output kept per task, in bytes. */
    public const MAX_OUTPUT = 65536;

    private const CACHE_PREFIX = 'alfakit-ops:schedule:';

    /**
     * Every scheduled event, keyed by `key()` (identical events get `-2`, `-3`…).
     *
     * @return array<string, Event>
     */
    public static function events(): array
    {
        try {
            // Loads routes/console.php and fires `Artisan::starting()` (`withSchedule()`).
            app(ConsoleKernel::class)->all();
        } catch (Throwable) {
            // The schedule may still have events registered in code.
        }

        $events = [];

        foreach (app(Schedule::class)->events() as $event) {
            $key = self::key($event);
            $unique = $key;

            for ($i = 2; isset($events[$unique]); $i++) {
                $unique = $key.'-'.$i;
            }

            $events[$unique] = $event;
        }

        return $events;
    }

    public static function find(string $key): ?Event
    {
        return self::events()[$key] ?? null;
    }

    /**
     * A stable key for the event: its expression, command and description.
     */
    public static function key(Event $event): string
    {
        return substr(sha1($event->expression."\0".($event->command ?? '')."\0".($event->description ?? '')), 0, 16);
    }

    /**
     * @return array<string, TaskRow>
     */
    public static function rows(): array
    {
        $rows = [];

        foreach (self::events() as $key => $event) {
            $rows[$key] = self::toRow($key, $event);
        }

        return $rows;
    }

    /**
     * @return TaskRow
     */
    public static function toRow(string $key, Event $event): array
    {
        $timezone = self::timezone($event);

        try {
            $next = CarbonImmutable::instance($event->nextRunDate(CarbonImmutable::now()))->utc()->toIso8601String();
        } catch (Throwable) {
            $next = null;
        }

        return [
            'id' => $key,
            'command' => self::command($event),
            'description' => is_string($event->description) && $event->description !== self::command($event) ? $event->description : null,
            'type' => self::type($event),
            'expression' => $event->getExpression(),
            'frequency' => CronDescriber::describe($event->getExpression(), is_int($event->repeatSeconds) ? $event->repeatSeconds : null),
            'next_due' => $next,
            'timezone' => $timezone,
            'without_overlapping' => (bool) $event->withoutOverlapping,
            'on_one_server' => (bool) $event->onOneServer,
            'in_background' => (bool) $event->runInBackground,
            'in_maintenance' => (bool) $event->evenInMaintenanceMode,
            'environments' => array_values(array_map(strval(...), (array) $event->environments)),
            'last_run' => self::lastRun($event),
        ];
    }

    public static function timezone(Event $event): string
    {
        $timezone = $event->timezone ?: (config('app.schedule_timezone') ?? config('app.timezone', 'UTC'));

        return $timezone instanceof DateTimeZone ? $timezone->getName() : (is_string($timezone) && $timezone !== '' ? $timezone : 'UTC');
    }

    /**
     * `artisan`, `job` (`Schedule::job()`), `closure` or `command` (shell).
     */
    public static function type(Event $event): string
    {
        return match (true) {
            $event instanceof CallbackEvent => is_string($event->description) && class_exists($event->description) ? 'job' : 'closure',
            self::artisanCommand($event) !== null => 'artisan',
            default => 'command',
        };
    }

    /**
     * What the task runs: the Artisan command line without the PHP binary, the shell
     * command, or the closure's / job's description.
     */
    public static function command(Event $event): string
    {
        if ($event instanceof CallbackEvent) {
            return $event->getSummaryForDisplay();
        }

        return self::artisanCommand($event) ?? trim((string) $event->command);
    }

    /**
     * `inspire --quiet` for `'php' 'artisan' inspire --quiet`, null for other events.
     */
    public static function artisanCommand(Event $event): ?string
    {
        if ($event instanceof CallbackEvent || ! is_string($event->command)) {
            return null;
        }

        $prefix = ConsoleApplication::formatCommandString('');

        if (str_starts_with($event->command, $prefix)) {
            return trim(substr($event->command, strlen($prefix)));
        }

        // A schedule built with another PHP binary: `'/usr/bin/php8.4' 'artisan' inspire`.
        if (preg_match("/^\S+\s+'?artisan'?\s+(.+)$/", $event->command, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * @return RunResult|null
     */
    public static function lastRun(Event $event): ?array
    {
        $run = Cache::get(self::CACHE_PREFIX.self::key($event));

        return is_array($run) ? $run : null;
    }

    /**
     * @param  RunResult  $run
     */
    public static function recordRun(Event $event, array $run): void
    {
        if (is_string($run['output']) && strlen($run['output']) > self::MAX_OUTPUT) {
            $run['output'] = '…'.substr($run['output'], -self::MAX_OUTPUT);
        }

        Cache::forever(self::CACHE_PREFIX.self::key($event), $run);
    }

    /**
     * The end of the file the task writes its output to (`sendOutputTo()` /
     * `appendOutputTo()`), if it has one.
     */
    public static function outputFile(Event $event): ?string
    {
        if ($event instanceof CallbackEvent || ! is_string($event->output) || $event->output === $event->getDefaultOutput() || ! is_file($event->output) || ! is_readable($event->output)) {
            return null;
        }

        $size = (int) filesize($event->output);
        $handle = fopen($event->output, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            if ($size > self::MAX_OUTPUT) {
                fseek($handle, -self::MAX_OUTPUT, SEEK_END);
            }

            $contents = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        return is_string($contents) ? ($size > self::MAX_OUTPUT ? '…' : '').$contents : null;
    }

    /**
     * Run the task now, in this process, and record the result as its last run. Artisan
     * commands run through the console kernel (their output is captured); shell commands
     * and closures run as the scheduler runs them. `withoutOverlapping()` is respected:
     * a task that is already running is skipped.
     *
     * @return RunResult
     */
    public static function run(Event $event, ?string $user = null): array
    {
        $container = app();
        $started = microtime(true);
        $output = null;
        $exitCode = null;
        $status = self::STATUS_SUCCESS;

        try {
            if (($command = self::artisanCommand($event)) !== null) {
                if ($event->withoutOverlapping && ! $event->mutex->create($event)) {
                    $status = self::STATUS_SKIPPED;
                } else {
                    try {
                        $event->callBeforeCallbacks($container);
                        $exitCode = Artisan::call($command);
                        $output = Artisan::output();
                    } catch (Throwable $exception) {
                        $exitCode = 1;
                        $output = $exception->getMessage();
                    } finally {
                        $event->finish($container, $exitCode);
                    }
                }
            } elseif ($event instanceof CallbackEvent) {
                $event->run($container);
                $status = $event->skippedBecauseOverlapping ? self::STATUS_SKIPPED : self::STATUS_SUCCESS;
                $exitCode = $event->skippedBecauseOverlapping ? null : ($event->exitCode ?? 0);
            } else {
                [$exitCode, $output, $status] = self::runShellCommand($event);
            }
        } catch (Throwable $exception) {
            $exitCode = 1;
            $output = $exception->getMessage();
        }

        if ($status !== self::STATUS_SKIPPED && $exitCode !== 0) {
            $status = self::STATUS_FAILED;
        }

        $run = [
            'status' => $status,
            'exit_code' => $exitCode,
            'output' => is_string($output) && trim($output) !== '' ? $output : null,
            'runtime' => round(microtime(true) - $started, 2),
            'finished_at' => CarbonImmutable::now()->toIso8601String(),
            'source' => 'panel',
            'user' => $user,
        ];

        self::recordRun($event, $run);

        return $run;
    }

    /**
     * Run a shell command in the foreground with its output captured in a temporary file.
     *
     * @return array{?int, ?string, string}
     */
    private static function runShellCommand(Event $event): array
    {
        $file = tempnam(sys_get_temp_dir(), 'alfakit-ops-');
        $original = [$event->output, $event->shouldAppendOutput, $event->runInBackground];

        if ($file !== false) {
            $event->output = $file;
            $event->shouldAppendOutput = false;
        }

        $event->runInBackground = false;

        try {
            $event->run(app());
        } finally {
            [$event->output, $event->shouldAppendOutput, $event->runInBackground] = $original;
        }

        $output = $file !== false && is_file($file) ? (string) file_get_contents($file) : null;

        if ($file !== false && is_file($file)) {
            @unlink($file);
        }

        if ($event->skippedBecauseOverlapping) {
            return [null, null, self::STATUS_SKIPPED];
        }

        return [$event->exitCode ?? 0, $output, self::STATUS_SUCCESS];
    }
}
