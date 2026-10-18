<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Clusters;

use AlfaSix\Alfakit\Clusters\Cluster;
use AlfaSix\AlfakitOps\OpsPlugin;

/**
 * The "Operations" navigation item: every operations page in one sub-navigation, under
 * `/{panel}/ops`. Its label, group, icon and sort come from the plugin.
 */
class Operations extends Cluster
{
    protected static ?string $slug = 'ops';

    protected static bool $isDiscovered = false;

    public static function canAccess(): bool
    {
        return OpsPlugin::get()->isAuthorized();
    }

    public function getTitle(): string
    {
        return OpsPlugin::get()->getNavigationLabel();
    }

    public static function getNavigationLabel(): string
    {
        return OpsPlugin::get()->getNavigationLabel();
    }

    public static function getClusterBreadcrumb(): string
    {
        return OpsPlugin::get()->getNavigationLabel();
    }

    public static function getNavigationGroup(): ?string
    {
        return OpsPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationIcon(): ?string
    {
        return OpsPlugin::get()->getNavigationIcon();
    }

    public static function getNavigationSort(): ?int
    {
        return OpsPlugin::get()->getNavigationSort();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return OpsPlugin::get()->shouldRegisterNavigation();
    }
}
