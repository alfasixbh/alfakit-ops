<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Widgets;

use AlfaSix\Alfakit\Support\NumberFormat;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget\Stat;
use AlfaSix\AlfakitOps\OpsPlugin;
use AlfaSix\AlfakitOps\Support\SystemInfo;
use Illuminate\Support\Number;

/**
 * PHP, Laravel and AlfaKit versions, the environment (with a warning when debug mode is
 * on), the queue, cache, session, mail and database drivers and the free disk space.
 * On the operations overview, and on the dashboard with `OpsPlugin::dashboardWidget()`.
 */
class SystemInfoWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    /** @var int|array<string, int|null>|null */
    protected int|array|null $columns = ['default' => 1, 'sm' => 2, 'lg' => 4];

    public static function canView(): bool
    {
        $plugin = OpsPlugin::get();

        return $plugin->isAuthorized() && $plugin->hasFeature(OpsPlugin::FEATURE_OVERVIEW);
    }

    protected static function trans(string $key, array $replace = []): string
    {
        return (string) __('alfakit-ops::ops.system.'.$key, $replace);
    }

    protected function getHeading(): ?string
    {
        return static::trans('heading');
    }

    protected function getStats(): array
    {
        $drivers = SystemInfo::drivers();
        $debug = SystemInfo::debug();
        $production = SystemInfo::isProduction();
        $disk = SystemInfo::disk();

        return array_values(array_filter([
            Stat::make(static::trans('php'), SystemInfo::phpVersion())
                ->description(static::trans('php_description', ['sapi' => PHP_SAPI]))
                ->icon('code'),
            Stat::make(static::trans('laravel'), SystemInfo::laravelVersion())
                ->icon('box'),
            Stat::make(static::trans('alfakit'), SystemInfo::alfakitVersion() ?? '—')
                ->icon('layout-dashboard'),
            Stat::make(static::trans('environment'), SystemInfo::environment())
                ->description(static::trans($debug ? ($production ? 'debug_on_production' : 'debug_on') : 'debug_off'))
                ->descriptionIcon($debug ? 'triangle-alert' : 'shield-check')
                ->color($debug ? ($production ? 'danger' : 'warning') : 'success')
                ->icon('server'),
            Stat::make(static::trans('queue'), $drivers['queue'])->icon('layers'),
            Stat::make(static::trans('cache'), $drivers['cache'])->icon('database-zap'),
            Stat::make(static::trans('session'), $drivers['session'])->icon('cookie'),
            Stat::make(static::trans('mail'), $drivers['mail'])->icon('mail'),
            Stat::make(static::trans('database'), $drivers['database'])->icon('database'),
            $disk === null ? null : Stat::make(static::trans('disk'), static::trans('disk_value', ['free' => NumberFormat::fileSize($disk['free'], 1)]))
                ->description(static::trans('disk_description', [
                    'total' => NumberFormat::fileSize($disk['total'], 1),
                    'percentage' => (string) Number::percentage($disk['ratio'] * 100, precision: 0),
                ]))
                ->color($disk['ratio'] < SystemInfo::LOW_DISK_RATIO ? 'danger' : null)
                ->icon('hard-drive'),
        ]));
    }
}
