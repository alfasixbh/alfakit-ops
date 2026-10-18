<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\AlfakitOps\Clusters\Operations;
use AlfaSix\AlfakitOps\OpsPlugin;
use AlfaSix\AlfakitOps\Pages\Batches;
use AlfaSix\AlfakitOps\Pages\FailedJobs;
use AlfaSix\AlfakitOps\Pages\Logs;
use AlfaSix\AlfakitOps\Pages\Maintenance;
use AlfaSix\AlfakitOps\Pages\Overview;
use AlfaSix\AlfakitOps\Pages\Queues;
use AlfaSix\AlfakitOps\Pages\Schedule;
use AlfaSix\AlfakitOps\Widgets\SystemInfoWidget;
use Illuminate\Support\Facades\Gate;

use function AlfaSix\AlfakitOps\Tests\failJob;
use function AlfaSix\AlfakitOps\Tests\inertiaGet;
use function AlfaSix\AlfakitOps\Tests\opsPlugin;
use function AlfaSix\AlfakitOps\Tests\opsUser;
use function AlfaSix\AlfakitOps\Tests\pageProps;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(AlfaSix\AlfakitOps\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('ops'));
});

dataset('pages', [
    'overview' => ['/ops-panel/ops/overview'],
    'failed jobs' => ['/ops-panel/ops/failed-jobs'],
    'queues' => ['/ops-panel/ops/queues'],
    'batches' => ['/ops-panel/ops/batches'],
    'schedule' => ['/ops-panel/ops/schedule'],
    'logs' => ['/ops-panel/ops/logs'],
    'maintenance' => ['/ops-panel/ops/maintenance'],
]);

it('registers the operations cluster and its pages', function (): void {
    $pages = alfakit()->getPanel('ops')->getPages();

    expect($pages)->toContain(Operations::class, Overview::class, FailedJobs::class, Queues::class, Batches::class, Schedule::class, Logs::class, Maintenance::class);
});

it('opens every page for users the viewAlfakitOps gate allows', function (string $url): void {
    actingAs(opsUser());

    inertiaGet($url)->assertOk();
})->with('pages');

it('denies everyone else by default', function (string $url): void {
    actingAs(opsUser('Eve Intruder'));

    inertiaGet($url)->assertForbidden();
})->with('pages');

it('sends guests to the login page', function (): void {
    get('/ops-panel/ops/overview')->assertRedirect();
});

it('denies when neither authorize() nor the gate allow', function (): void {
    actingAs(opsUser());
    Gate::define('viewAlfakitOps', static fn (): bool => false);

    inertiaGet('/ops-panel/ops/overview')->assertForbidden();
});

it('uses authorize() instead of the gate when it is set', function (): void {
    actingAs($eve = opsUser('Eve Operator'));
    opsPlugin()->authorize(static fn ($user): bool => $user->getAttribute('name') === 'Eve Operator');

    inertiaGet('/ops-panel/ops/overview')->assertOk();

    actingAs(opsUser());
    inertiaGet('/ops-panel/ops/overview')->assertForbidden();
});

it('checks another gate with gate()', function (): void {
    actingAs(opsUser('Eve Operator'));
    Gate::define('manageServers', static fn (): bool => true);
    opsPlugin()->gate('manageServers');

    inertiaGet('/ops-panel/ops/overview')->assertOk();
});

it('shows one cluster item in the navigation, in the configured group', function (): void {
    actingAs(opsUser());

    $subNavigation = json_encode(pageProps('/ops-panel/ops/overview')['subNavigation'], JSON_UNESCAPED_SLASHES);
    alfakit()->setCurrentPanel(alfakit()->getPanel('ops'));

    expect($subNavigation)->toContain('Failed jobs')->toContain('/ops-panel/ops/schedule');

    expect(Operations::getNavigationLabel())->toBe('Operations')
        ->and(Operations::getNavigationGroup())->toBe('System')
        ->and(Operations::getNavigationItems())->toHaveCount(1)
        ->and(Operations::getClusterNavigationItems())->toHaveCount(7)
        ->and(array_map(static fn ($item): string => (string) $item->getLabel(), Operations::getClusterNavigationItems()))
        ->toBe(['Overview', 'Failed jobs', 'Queues', 'Batches', 'Scheduler', 'Logs', 'Maintenance']);

    actingAs(opsUser('Eve Intruder'));

    expect(Operations::getNavigationItems())->toBe([]);
});

it('redirects the cluster to its first page', function (): void {
    actingAs(opsUser());

    get('/ops-panel/ops')->assertRedirect('http://localhost/ops-panel/ops/overview');
});

it('turns features off', function (): void {
    actingAs(opsUser());
    opsPlugin()->failedJobs(false)->logs(static fn (): bool => false);

    inertiaGet('/ops-panel/ops/failed-jobs')->assertForbidden();
    inertiaGet('/ops-panel/ops/logs')->assertForbidden();
    inertiaGet('/ops-panel/ops/queues')->assertOk();

    expect(array_map(static fn ($item): string => (string) $item->getLabel(), Operations::getClusterNavigationItems()))
        ->not->toContain('Failed jobs', 'Logs');
});

it('does not register the pages of features turned off before registration', function (): void {
    $panel = (new AlfaSix\Alfakit\Panel)->id('ops-lean')->plugin(OpsPlugin::make()->failedJobs(false)->maintenance(false));

    expect($panel->getPages())->not->toContain(FailedJobs::class, Maintenance::class)
        ->and($panel->getPages())->toContain(Queues::class, Logs::class);
});

it('hides itself on tenant panels unless allowed', function (): void {
    $team = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $user = opsUser();
    $user->teams()->attach($team);
    actingAs($user);

    inertiaGet('/ops-team/alpha/ops/overview')->assertForbidden();

    alfakit()->setCurrentPanel(alfakit()->getPanel('ops-team'));
    expect(Operations::getNavigationItems())->toBe([]);

    opsPlugin('ops-team')->allowOnTenantPanels();

    inertiaGet('/ops-team/alpha/ops/overview')->assertOk();
});

it('denies every action in read-only mode except downloads', function (): void {
    actingAs(opsUser());
    opsPlugin()->readOnly();

    expect(opsPlugin()->can(OpsPlugin::ABILITY_RETRY_FAILED_JOBS))->toBeFalse()
        ->and(opsPlugin()->can(OpsPlugin::ABILITY_MAINTENANCE_MODE))->toBeFalse()
        ->and(opsPlugin()->can(OpsPlugin::ABILITY_DOWNLOAD_LOGS))->toBeTrue();

    failJob();

    alfakit()->testPage(FailedJobs::class, panel: 'ops')
        ->assertActionHidden('retryAll')
        ->assertActionHidden('flush');
});

it('authorises each action with authorizeActions()', function (): void {
    actingAs(opsUser());
    $asked = [];
    opsPlugin()->authorizeActions(static function (string $ability, $user) use (&$asked): bool {
        $asked[] = $ability;

        return $ability === OpsPlugin::ABILITY_RETRY_FAILED_JOBS && $user->getAttribute('name') === 'Ada Admin';
    });

    failJob();

    alfakit()->testPage(FailedJobs::class, panel: 'ops')
        ->assertActionVisible('retryAll')
        ->assertActionHidden('flush');

    expect($asked)->toContain(OpsPlugin::ABILITY_RETRY_FAILED_JOBS, OpsPlugin::ABILITY_FLUSH_FAILED_JOBS);
});

it('never runs an unauthorised action, even when posted directly', function (): void {
    actingAs(opsUser());
    opsPlugin()->authorizeActions(static fn (string $ability): bool => $ability !== OpsPlugin::ABILITY_FLUSH_FAILED_JOBS);
    failJob();

    alfakit()->testPage(FailedJobs::class, panel: 'ops')->callAction('flush');

    expect(DB::table('failed_jobs')->count())->toBe(1);
});

it('asks for the password before destructive actions when configured', function (): void {
    actingAs(opsUser());
    opsPlugin()->requiresPasswordConfirmation();
    failJob();

    $modal = alfakit()->testPage(FailedJobs::class, panel: 'ops')->mountAction('flush')->getMountedActionModal();

    expect($modal['passwordConfirmation'] ?? null)->toBeTrue();

    $retry = alfakit()->testPage(FailedJobs::class, panel: 'ops')->mountAction('retryAll')->getMountedActionModal();

    expect($retry['passwordConfirmation'] ?? null)->toBeNull();
});

it('adds the system information widget to the dashboard', function (): void {
    actingAs(opsUser());

    expect(alfakit()->getPanel('ops')->getWidgets())->toContain(SystemInfoWidget::class)
        ->and(SystemInfoWidget::canView())->toBeTrue();

    actingAs(opsUser('Eve Intruder'));

    expect(SystemInfoWidget::canView())->toBeFalse();
});
