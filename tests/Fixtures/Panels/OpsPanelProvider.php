<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Tests\Fixtures\Panels;

use AlfaSix\Alfakit\Pages\Dashboard;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\AlfakitOps\OpsPlugin;
use AlfaSix\AlfakitOps\Tests\TestCase;

class OpsPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('ops')
            ->path('ops-panel')
            ->login()
            ->middleware(['web'])
            ->pages([Dashboard::class])
            ->plugin(
                OpsPlugin::make()
                    ->navigationGroup('System')
                    ->logDirectory(static fn (): string => TestCase::logDirectory())
                    ->redactKeys(['card_holder'])
                    ->dashboardWidget(),
            );
    }
}
