<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Pages;

use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\AlfakitOps\Clusters\Operations;
use AlfaSix\AlfakitOps\OpsPlugin;
use AlfaSix\AlfakitOps\Pages\Concerns\InteractsWithOps;
use AlfaSix\AlfakitOps\Widgets\OpsOverviewWidget;
use AlfaSix\AlfakitOps\Widgets\SystemInfoWidget;

/**
 * The first operations page: a card per feature (failed jobs, pending jobs, batches, the
 * next scheduled task, maintenance mode) and the system information.
 */
class Overview extends Page
{
    use InteractsWithOps;

    protected static ?string $slug = 'overview';

    protected static ?string $cluster = Operations::class;

    protected static bool $isDiscovered = false;

    protected static ?string $navigationIcon = 'gauge';

    protected static ?int $navigationSort = 10;

    public static function getOpsFeature(): string
    {
        return OpsPlugin::FEATURE_OVERVIEW;
    }

    protected static function getOpsTranslationKey(): string
    {
        return 'overview';
    }

    protected function getHeaderWidgets(): array
    {
        return [OpsOverviewWidget::class, SystemInfoWidget::class];
    }

    /**
     * @return int|array<string, int|null>
     */
    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
