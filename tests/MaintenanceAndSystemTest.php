<?php

declare(strict_types=1);

use AlfaSix\AlfakitOps\OpsPlugin;
use AlfaSix\AlfakitOps\Pages\Maintenance;
use AlfaSix\AlfakitOps\Support\MaintenanceTasks;
use AlfaSix\AlfakitOps\Support\SystemInfo;
use AlfaSix\AlfakitOps\Widgets\OpsOverviewWidget;
use AlfaSix\AlfakitOps\Widgets\SystemInfoWidget;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

use function AlfaSix\AlfakitOps\Tests\failJob;
use function AlfaSix\AlfakitOps\Tests\opsPlugin;
use function AlfaSix\AlfakitOps\Tests\opsUser;
use function AlfaSix\AlfakitOps\Tests\pageProps;
use function Pest\Laravel\actingAs;

uses(AlfaSix\AlfakitOps\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('ops'));
    actingAs(opsUser());
});

/**
 * Replace Artisan commands with stand-ins that record that they ran.
 *
 * @param  array<string>  $commands
 * @return ArrayObject<int, string>
 */
function fakeCommands(array $commands): ArrayObject
{
    $ran = new ArrayObject;

    foreach ($commands as $command) {
        Artisan::command($command, function () use ($ran, $command): void {
            $ran[] = $command;
            $this->info("Ran {$command}");
        });
    }

    return $ran;
}

it('shows the overview cards and the system information', function (): void {
    failJob();
    $props = pageProps('/ops-panel/ops/overview');
    $widgets = collect($props['content'])->firstWhere('type', 'Widgets')['widgets'];
    alfakit()->setCurrentPanel(alfakit()->getPanel('ops'));

    expect($widgets)->toHaveCount(2);

    $overview = OpsOverviewWidget::make()->toSchema();
    $labels = array_column($overview['stats'], 'label');
    $failed = collect($overview['stats'])->firstWhere('label', 'Failed jobs');

    expect($labels)->toBe(['Failed jobs', 'Pending jobs', 'Running batches', 'Next scheduled task', 'Maintenance mode'])
        ->and($failed['value'])->toBe('1')
        ->and($failed['color'])->toBe('danger')
        ->and($failed['url'])->toBe('http://localhost/ops-panel/ops/failed-jobs');

    opsPlugin()->failedJobs(false);

    expect(array_column(OpsOverviewWidget::make()->toSchema()['stats'], 'label'))->not->toContain('Failed jobs');
});

it('reports versions, drivers, debug mode and disk space', function (): void {
    config()->set('app.debug', true);
    config()->set('app.env', 'production');
    app()->detectEnvironment(static fn (): string => 'production');

    $stats = collect(SystemInfoWidget::make()->toSchema()['stats'])->keyBy('label');

    expect($stats['PHP']['value'])->toBe(PHP_VERSION)
        ->and($stats['Laravel']['value'])->toBe(app()->version())
        ->and($stats['Environment']['value'])->toBe('production')
        ->and($stats['Environment']['color'])->toBe('danger')
        ->and($stats['Environment']['description'])->toBe('Debug mode is on in production: turn it off')
        ->and($stats['Queue']['value'])->toBe('database')
        ->and($stats['Cache']['value'])->toBe('array')
        ->and($stats['Database']['value'])->toBe('testing (sqlite)')
        ->and($stats->has('Disk space'))->toBe(SystemInfo::disk() !== null);

    config()->set('app.debug', false);

    expect(collect(SystemInfoWidget::make()->toSchema()['stats'])->keyBy('label')['Environment']['color'])->toBe('success');
});

it('lists the caches with their status', function (): void {
    $rows = MaintenanceTasks::rows();

    expect(array_keys($rows))->toContain('cache', 'config', 'routes', 'views', 'events', 'alfakit')
        ->and($rows['config']['cache_command'])->toBe('config:cache')
        ->and($rows['cache']['cache_command'])->toBeNull()
        ->and($rows['config']['status'])->toBe(app()->configurationIsCached());
});

it('clears the application cache', function (): void {
    Cache::put('greeting', 'hello');

    alfakit()->testPage(Maintenance::class, panel: 'ops')
        ->callTableAction('clear', 'cache')
        ->assertNotified('Application cache cleared');

    expect(Cache::get('greeting'))->toBeNull();
});

it('runs the clear and cache commands of each task', function (): void {
    $ran = fakeCommands(['config:clear', 'config:cache', 'alfakit:optimize', 'optimize', 'optimize:clear']);

    alfakit()->testPage(Maintenance::class, panel: 'ops')
        ->callTableAction('clear', 'config')
        ->assertNotified('Configuration cleared');

    alfakit()->testPage(Maintenance::class, panel: 'ops')
        ->callTableAction('cache', 'config')
        ->assertNotified('Configuration cached');

    alfakit()->testPage(Maintenance::class, panel: 'ops')
        ->callTableAction('cache', 'alfakit')
        ->assertNotified('AlfaKit cached');

    alfakit()->testPage(Maintenance::class, panel: 'ops')->callAction('optimize')->assertNotified('Everything is cached');
    alfakit()->testPage(Maintenance::class, panel: 'ops')->callAction('clearAll')->assertNotified('Every cache was cleared');

    expect($ran->getArrayCopy())->toBe(['config:clear', 'config:cache', 'alfakit:optimize', 'optimize', 'optimize:clear']);
});

it('reports a failing command', function (): void {
    Artisan::command('route:clear', function (): int {
        $this->error('Permission denied');

        return 1;
    });

    alfakit()->testPage(Maintenance::class, panel: 'ops')
        ->callTableAction('clear', 'routes')
        ->assertNotified('The command failed');
});

it('authorises cache and optimize actions separately', function (): void {
    $ran = fakeCommands(['optimize', 'optimize:clear']);
    opsPlugin()->authorizeActions(static fn (string $ability): bool => $ability === OpsPlugin::ABILITY_CLEAR_CACHES);

    alfakit()->testPage(Maintenance::class, panel: 'ops')
        ->assertActionHidden('optimize')
        ->assertActionVisible('clearAll')
        ->assertActionHidden('down')
        ->assertTableActionHidden('cache', 'config')
        ->assertTableActionVisible('clear', 'config')
        ->callAction('optimize');

    expect($ran->getArrayCopy())->toBe([]);
});

it('puts the application into maintenance mode with a bypass link and back up', function (): void {
    $page = alfakit()->testPage(Maintenance::class, panel: 'ops')
        ->assertActionVisible('down')
        ->assertActionHidden('up');

    $page->callAction('down', ['secret' => 'let-me-in-please-1234', 'retry' => 60])
        ->assertHasNoActionErrors()
        ->assertNotified('Maintenance mode is on')
        ->assertRedirect('http://localhost/let-me-in-please-1234');

    expect(app()->isDownForMaintenance())->toBeTrue()
        ->and(MaintenanceTasks::maintenanceData())->toMatchArray(['secret' => 'let-me-in-please-1234', 'retry' => 60])
        ->and(MaintenanceTasks::bypassUrl())->toBe('http://localhost/let-me-in-please-1234')
        ->and(session('url.intended'))->toBe('http://localhost/ops-panel/ops/maintenance');

    // The bypass link sets the cookie and sends the user back to the page.
    $response = $this->get('/let-me-in-please-1234');
    $response->assertRedirect();
    $cookie = collect($response->headers->getCookies())->firstWhere(fn ($cookie): bool => $cookie->getName() === 'laravel_maintenance');

    expect($cookie)->not->toBeNull();

    // Everyone else gets the maintenance page; the cookie lets this browser through.
    $this->get('/ops-panel/ops/maintenance')->assertStatus(503);

    $page = $this->withUnencryptedCookie('laravel_maintenance', $cookie->getValue())
        ->get('/ops-panel/ops/maintenance', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
        ->assertOk()
        ->json('props.page');

    expect(json_encode($page['content']))->toContain('maintenance mode')->toContain('let-me-in-please-1234')
        ->and(array_column($page['headerActions'], 'name'))->toContain('up')->not->toContain('down');

    $this->withCredentials()
        ->withUnencryptedCookie('laravel_maintenance', $cookie->getValue())
        ->postJson('/ops-panel/_alfakit/actions', ['token' => $page['token'], 'action' => 'up'])
        ->assertOk();

    expect(app()->isDownForMaintenance())->toBeFalse();
});

it('validates the bypass secret', function (string $secret): void {
    alfakit()->testPage(Maintenance::class, panel: 'ops')
        ->callAction('down', ['secret' => $secret])
        ->assertHasActionErrors(['secret']);

    expect(app()->isDownForMaintenance())->toBeFalse();
})->with([
    'too short' => ['short'],
    'path characters' => ['../../etc/passwd-and-more'],
    'empty' => [''],
]);

it('never toggles maintenance mode without the ability', function (): void {
    opsPlugin()->authorizeActions(static fn (string $ability): bool => $ability !== OpsPlugin::ABILITY_MAINTENANCE_MODE);

    alfakit()->testPage(Maintenance::class, panel: 'ops')
        ->assertActionHidden('down')
        ->callAction('down', ['secret' => 'let-me-in-please-1234']);

    expect(app()->isDownForMaintenance())->toBeFalse();
});
