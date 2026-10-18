<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Jobs;

use AlfaSix\AlfakitOps\Support\ScheduledTasks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs a scheduled task now ("Run now" with `OpsPlugin::runScheduledTasksOnQueue()`). The
 * task is looked up by its key when the job runs; the result is stored as its last run.
 */
class RunScheduledTaskJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** A task that runs too long is the scheduler's business, not a retry's. */
    public int $tries = 1;

    public function __construct(public string $task, public ?string $user = null) {}

    public function handle(): void
    {
        $event = ScheduledTasks::find($this->task);

        if ($event !== null) {
            ScheduledTasks::run($event, $this->user);
        }
    }

    public function displayName(): string
    {
        return 'AlfaKit Ops: run scheduled task '.$this->task;
    }
}
