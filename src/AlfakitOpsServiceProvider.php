<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps;

use AlfaSix\AlfakitOps\Support\ScheduledTasks;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AlfakitOpsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'alfakit-ops');

        // The scheduler's own runs become each task's "last run" on the schedule page.
        Event::listen(ScheduledTaskFinished::class, static function (ScheduledTaskFinished $event): void {
            self::recordScheduledRun($event->task, $event->task->exitCode, $event->runtime, null);
        });

        Event::listen(ScheduledTaskFailed::class, static function (ScheduledTaskFailed $event): void {
            self::recordScheduledRun($event->task, 1, null, $event->exception->getMessage());
        });
    }

    protected static function recordScheduledRun(\Illuminate\Console\Scheduling\Event $task, ?int $exitCode, ?float $runtime, ?string $output): void
    {
        try {
            ScheduledTasks::recordRun($task, [
                'status' => $exitCode === 0 ? ScheduledTasks::STATUS_SUCCESS : ScheduledTasks::STATUS_FAILED,
                'exit_code' => $exitCode,
                'output' => $output,
                'runtime' => $runtime === null ? null : round($runtime, 2),
                'finished_at' => CarbonImmutable::now()->toIso8601String(),
                'source' => 'scheduler',
                'user' => null,
            ]);
        } catch (Throwable) {
            // A broken cache store must never break the scheduler.
        }
    }
}
