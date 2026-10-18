<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Tests\Fixtures\Panels;

use AlfaSix\Alfakit\Pages\Dashboard;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\AlfakitOps\OpsPlugin;
use AlfaSix\AlfakitOps\Tests\TestCase;

class OpsTenantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('ops-team')
            ->path('ops-team')
            ->login()
            ->middleware(['web'])
            ->tenant(Team::class, slugAttribute: 'slug')
            ->pages([Dashboard::class])
            ->plugin(OpsPlugin::make()->logDirectory(static fn (): string => TestCase::logDirectory()));
    }
}
