<?php

declare(strict_types=1);

use AlfaSix\AlfakitOps\Jobs\RunScheduledTaskJob;
use AlfaSix\AlfakitOps\OpsPlugin;
use AlfaSix\AlfakitOps\Pages\Schedule as SchedulePage;
use AlfaSix\AlfakitOps\Support\CronDescriber;
use AlfaSix\AlfakitOps\Support\ScheduledTasks;
use AlfaSix\AlfakitOps\Tests\Fixtures\Jobs\SendInvoice;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

use function AlfaSix\AlfakitOps\Tests\contentBlock;
use function AlfaSix\AlfakitOps\Tests\opsPlugin;
use function AlfaSix\AlfakitOps\Tests\opsUser;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

uses(AlfaSix\AlfakitOps\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('ops'));
    actingAs(opsUser());
    travelTo(now()->setDate(2026, 10, 14)->setTime(1, 0));

    Artisan::command('ops:greet {name=World}', function (string $name): void {
        $this->info("Hello {$name}");
    });

    Artisan::command('ops:fail', function (): int {
        $this->error('Nope');

        return 3;
    });
});

/**
 * The scheduled event whose command or description contains `$needle`, and its key.
 *
 * @return array{string, Event}
 */
function scheduled(string $needle): array
{
    foreach (ScheduledTasks::events() as $key => $event) {
        if (str_contains(ScheduledTasks::command($event), $needle)) {
            return [$key, $event];
        }
    }

    throw new RuntimeException("No scheduled task matches [{$needle}].");
}

it('lists the scheduled tasks with their frequency in words and next run', function (): void {
    $schedule = app(Schedule::class);
    $schedule->command('ops:greet')->dailyAt('02:30')->withoutOverlapping()->onOneServer()->description('Says hello');
    $schedule->call(static fn (): null => null)->everyFiveMinutes()->name('heartbeat');
    $schedule->job(new SendInvoice)->weekdays()->at('08:00');
    $schedule->exec('echo hi')->hourlyAt(15)->environments(['production']);

    $rows = collect(ScheduledTasks::rows());
    $greet = $rows->firstWhere('command', 'ops:greet');
    $heartbeat = $rows->firstWhere('command', 'heartbeat');
    $job = $rows->firstWhere('command', SendInvoice::class);
    $shell = $rows->firstWhere('command', 'echo hi');

    expect($greet)->toMatchArray([
        'type' => 'artisan',
        'description' => 'Says hello',
        'expression' => '30 2 * * *',
        'frequency' => 'Every day at 02:30',
        'next_due' => '2026-10-14T02:30:00+00:00',
        'timezone' => 'UTC',
        'without_overlapping' => true,
        'on_one_server' => true,
        'last_run' => null,
    ])
        ->and($heartbeat)->toMatchArray(['type' => 'closure', 'frequency' => 'Every 5 minutes'])
        ->and($job)->toMatchArray(['type' => 'job', 'frequency' => 'Weekdays at 08:00'])
        ->and($shell)->toMatchArray(['type' => 'command', 'frequency' => 'Every hour at :15', 'environments' => ['production']]);

    $table = contentBlock('/ops-panel/ops/schedule');

    expect($table['records'])->toHaveCount(4)
        ->and($table['description'])->toBe('Times are shown in UTC.');
});

it('shows the next run in the panel timezone', function (): void {
    app(Schedule::class)->command('ops:greet')->dailyAt('02:30')->timezone('Asia/Bahrain');
    alfakit()->getPanel('ops')->timezone('Asia/Tokyo');

    $row = collect(ScheduledTasks::rows())->first();
    $cells = collect(contentBlock('/ops-panel/ops/schedule')['records'])->first()['cells'];

    // At 01:00 UTC it is 04:00 in Bahrain (UTC+3): the next 02:30 there is 23:30 UTC, 08:30 in Tokyo.
    expect($row['next_due'])->toBe('2026-10-14T23:30:00+00:00')
        ->and($row['timezone'])->toBe('Asia/Bahrain')
        ->and($cells['next_due']['state'])->toBe('Oct 15, 2026 08:30');
})->skip(fn (): bool => ! method_exists(AlfaSix\Alfakit\Panel::class, 'timezone'), 'The panel has no timezone() option.');

it('runs an Artisan task now and keeps its output as the last run', function (): void {
    app(Schedule::class)->command('ops:greet Ada')->daily();
    [$key] = scheduled('ops:greet');

    alfakit()->testPage(SchedulePage::class, panel: 'ops')
        ->callTableAction('run', $key)
        ->assertNotified('ops:greet Ada finished (exit code 0)');

    $run = ScheduledTasks::rows()[$key]['last_run'];

    expect($run)->toMatchArray(['status' => 'success', 'exit_code' => 0, 'source' => 'panel', 'user' => 'Ada Admin'])
        ->and($run['output'])->toContain('Hello Ada');

    $modal = alfakit()->testPage(SchedulePage::class, panel: 'ops')->mountTableAction('output', $key)->getMountedActionModal();

    expect(json_encode($modal['content']))->toContain('Hello Ada')->toContain('Succeeded');
});

it('reports a failing task', function (): void {
    app(Schedule::class)->command('ops:fail')->daily();
    [$key] = scheduled('ops:fail');

    alfakit()->testPage(SchedulePage::class, panel: 'ops')
        ->callTableAction('run', $key)
        ->assertNotified('ops:fail failed (exit code 3)');

    expect(ScheduledTasks::rows()[$key]['last_run'])->toMatchArray(['status' => 'failed', 'exit_code' => 3]);
});

it('runs closures and catches their exceptions', function (): void {
    $ran = false;
    app(Schedule::class)->call(static function () use (&$ran): void {
        $ran = true;
    })->name('flag')->daily();
    app(Schedule::class)->call(static function (): void {
        throw new RuntimeException('Broken closure');
    })->name('broken')->daily();

    [$flag, $flagEvent] = scheduled('flag');
    [$broken, $brokenEvent] = scheduled('broken');

    expect(ScheduledTasks::run($flagEvent))->toMatchArray(['status' => 'success', 'exit_code' => 0])
        ->and($ran)->toBeTrue()
        ->and(ScheduledTasks::run($brokenEvent))->toMatchArray(['status' => 'failed', 'exit_code' => 1, 'output' => 'Broken closure']);
});

it('runs shell commands and captures their output', function (): void {
    app(Schedule::class)->exec('echo from-the-shell')->daily()->runInBackground();
    [, $event] = scheduled('from-the-shell');

    $run = ScheduledTasks::run($event);

    expect($run['status'])->toBe('success')
        ->and($run['output'])->toContain('from-the-shell')
        // The event itself is left as it was.
        ->and($event->runInBackground)->toBeTrue()
        ->and($event->output)->toBe($event->getDefaultOutput());
})->skip(PHP_OS_FAMILY === 'Windows');

it('skips a task that is already running without overlapping', function (): void {
    app(Schedule::class)->command('ops:greet')->daily()->withoutOverlapping();
    [$key, $event] = scheduled('ops:greet');
    $event->mutex->create($event);

    alfakit()->testPage(SchedulePage::class, panel: 'ops')
        ->callTableAction('run', $key)
        ->assertNotified('ops:greet is already running, so it was skipped');

    $event->mutex->forget($event);
});

it('queues the run when configured', function (): void {
    Queue::fake();
    opsPlugin()->runScheduledTasksOnQueue(queue: 'ops');
    app(Schedule::class)->command('ops:greet')->daily();
    [$key] = scheduled('ops:greet');

    alfakit()->testPage(SchedulePage::class, panel: 'ops')
        ->callTableAction('run', $key)
        ->assertNotified('ops:greet was queued');

    Queue::assertPushedOn('ops', RunScheduledTaskJob::class, static fn (RunScheduledTaskJob $job): bool => $job->task === $key && $job->user === 'Ada Admin');
});

it('runs the queued job', function (): void {
    app(Schedule::class)->command('ops:greet Queue')->daily();
    [$key] = scheduled('ops:greet');

    (new RunScheduledTaskJob($key, 'Ada Admin'))->handle();

    expect(ScheduledTasks::rows()[$key]['last_run']['output'] ?? '')->toContain('Hello Queue');
});

it('hides "Run now" from users who may not run tasks', function (): void {
    app(Schedule::class)->command('ops:greet')->daily();
    [$key] = scheduled('ops:greet');
    opsPlugin()->authorizeActions(static fn (string $ability): bool => $ability !== OpsPlugin::ABILITY_RUN_SCHEDULED_TASKS);

    alfakit()->testPage(SchedulePage::class, panel: 'ops')
        ->assertTableActionHidden('run', $key)
        ->assertTableActionVisible('output', $key)
        ->callTableAction('run', $key);

    expect(ScheduledTasks::rows()[$key]['last_run'])->toBeNull();
});

it('records the runs of the scheduler itself', function (): void {
    app(Schedule::class)->command('ops:greet')->daily();
    [$key, $event] = scheduled('ops:greet');
    $event->exitCode = 0;

    event(new ScheduledTaskFinished($event, 1.234));

    expect(ScheduledTasks::rows()[$key]['last_run'])->toMatchArray(['status' => 'success', 'exit_code' => 0, 'runtime' => 1.23, 'source' => 'scheduler']);
});

it('shows the end of the output file of a task', function (): void {
    $file = storage_path('framework/testing/ops-task.log');
    file_put_contents($file, str_repeat('x', ScheduledTasks::MAX_OUTPUT).'the end');
    app(Schedule::class)->command('ops:greet')->daily()->sendOutputTo($file);
    [, $event] = scheduled('ops:greet');

    $output = ScheduledTasks::outputFile($event);

    expect($output)->toStartWith('…')->toEndWith('the end')
        ->and(strlen((string) $output))->toBeLessThanOrEqual(ScheduledTasks::MAX_OUTPUT + 4);

    @unlink($file);
});

it('puts cron expressions into words', function (string $expression, ?string $words, ?int $seconds = null): void {
    expect(CronDescriber::describe($expression, $seconds))->toBe($words);
})->with([
    ['* * * * *', 'Every minute'],
    ['*/15 * * * *', 'Every 15 minutes'],
    ['0 * * * *', 'Every hour at :00'],
    ['0,30 * * * *', 'Every hour at :00, :30'],
    ['5 */4 * * *', 'Every 4 hours at :05'],
    ['0 0 * * *', 'Every day at 00:00'],
    ['@daily', 'Every day at 00:00'],
    ['0 1,13 * * *', 'Every day at 01:00, 13:00'],
    ['30 8 * * 1-5', 'Weekdays at 08:30'],
    ['0 10 * * 0,6', 'Weekends at 10:00'],
    ['0 9 * * 1', 'Every Monday at 09:00'],
    ['0 9 * * MON,FRI', 'Every Monday, Friday at 09:00'],
    ['0 0 1 * *', 'Monthly on day 1 at 00:00'],
    ['0 0 1 1-12/3 *', 'Quarterly on day 1 at 00:00'],
    ['0 0 25 12 *', 'Every year on December 25 at 00:00'],
    ['* * * * *', 'Every 10 seconds', 10],
    ['0 0 L * *', null],
    ['not a cron', null],
]);
