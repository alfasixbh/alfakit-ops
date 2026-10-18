<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Widgets;

use AlfaSix\Alfakit\Widgets\StatsOverviewWidget;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget\Stat;
use AlfaSix\AlfakitOps\OpsPlugin;
use AlfaSix\AlfakitOps\Pages\Batches;
use AlfaSix\AlfakitOps\Pages\FailedJobs;
use AlfaSix\AlfakitOps\Pages\Maintenance;
use AlfaSix\AlfakitOps\Pages\Queues;
use AlfaSix\AlfakitOps\Pages\Schedule;
use AlfaSix\AlfakitOps\Support\BatchRepository;
use AlfaSix\AlfakitOps\Support\FailedJobRepository;
use AlfaSix\AlfakitOps\Support\QueueMonitor;
use AlfaSix\AlfakitOps\Support\ScheduledTasks;

/**
 * One card per enabled feature, linking to its page: failed jobs, pending jobs, running
 * batches, the next scheduled task and the maintenance mode.
 */
class OpsOverviewWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    /** @var int|array<string, int|null>|null */
    protected int|array|null $columns = ['default' => 1, 'sm' => 2, 'lg' => 3];

    public static function canView(): bool
    {
        return OpsPlugin::get()->isAuthorized();
    }

    protected static function trans(string $key, array $replace = []): string
    {
        return (string) __('alfakit-ops::ops.overview.'.$key, $replace);
    }

    protected function getHeading(): ?string
    {
        return static::trans('heading');
    }

    protected function getPollingInterval(): ?string
    {
        return OpsPlugin::get()->getPollingInterval();
    }

    protected function getStats(): array
    {
        $stats = [];

        if (FailedJobs::canAccess()) {
            $failed = FailedJobRepository::count();
            $stats[] = Stat::make(static::trans('failed_jobs'), (string) $failed)
                ->description(trans_choice('alfakit-ops::ops.overview.failed_jobs_description', $failed, ['count' => $failed]))
                ->color($failed > 0 ? 'danger' : 'success')
                ->icon($failed > 0 ? 'circle-x' : 'circle-check')
                ->url(FailedJobs::getUrl());
        }

        if (Queues::canAccess()) {
            $pending = QueueMonitor::totalPending();
            $stats[] = Stat::make(static::trans('pending_jobs'), (string) $pending)
                ->description(trans_choice('alfakit-ops::ops.overview.queues_description', count(QueueMonitor::rows()), ['count' => count(QueueMonitor::rows())]))
                ->color($pending > 0 ? 'warning' : null)
                ->icon('layers')
                ->url(Queues::getUrl());
        }

        if (Batches::canAccess() && BatchRepository::isAvailable()) {
            $running = BatchRepository::runningCount();
            $stats[] = Stat::make(static::trans('running_batches'), (string) $running)
                ->icon('boxes')
                ->url(Batches::getUrl());
        }

        if (Schedule::canAccess()) {
            $next = collect(ScheduledTasks::rows())
                ->filter(static fn (array $row): bool => $row['next_due'] !== null)
                ->sortBy('next_due')
                ->first();

            $stats[] = Stat::make(static::trans('next_task'), $next === null ? static::trans('no_tasks') : (string) Schedule::relative($next['next_due']))
                ->description($next['command'] ?? null)
                ->icon('calendar-clock')
                ->url(Schedule::getUrl());
        }

        if (Maintenance::canAccess()) {
            $down = app()->isDownForMaintenance();
            $stats[] = Stat::make(static::trans('maintenance'), static::trans($down ? 'down' : 'live'))
                ->color($down ? 'warning' : 'success')
                ->icon($down ? 'construction' : 'radio')
                ->url(Maintenance::getUrl());
        }

        return $stats;
    }
}
