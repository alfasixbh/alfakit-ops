<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Support\NumberFormat;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitOps\Clusters\Operations;
use AlfaSix\AlfakitOps\OpsPlugin;
use AlfaSix\AlfakitOps\Pages\Concerns\InteractsWithOps;
use AlfaSix\AlfakitOps\Support\LogFiles;

/**
 * The log files of the log directory: open one to browse its entries, download, empty or
 * delete it.
 */
class Logs extends Page
{
    use InteractsWithOps;

    protected static ?string $slug = 'logs';

    protected static ?string $cluster = Operations::class;

    protected static bool $isDiscovered = false;

    protected static ?string $navigationIcon = 'scroll-text';

    protected static ?int $navigationSort = 60;

    public static function getOpsFeature(): string
    {
        return OpsPlugin::FEATURE_LOGS;
    }

    protected static function getOpsTranslationKey(): string
    {
        return 'logs';
    }

    protected static function trans(string $key, array $replace = []): string
    {
        return (string) __('alfakit-ops::ops.logs.'.$key, $replace);
    }

    /**
     * Active on the log file pages too.
     *
     * @return array<\AlfaSix\Alfakit\Navigation\NavigationItem>
     */
    public static function buildNavigationItems(): array
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();
        $routes = [$panel->generateRouteName(static::getRelativeRouteName()), $panel->generateRouteName(LogFile::getRelativeRouteName())];

        return array_map(
            static fn (\AlfaSix\Alfakit\Navigation\NavigationItem $item): \AlfaSix\Alfakit\Navigation\NavigationItem => $item->isActiveWhen(static fn (): bool => request()->routeIs($routes)),
            parent::buildNavigationItems(),
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(static::trans('table.heading'))
            ->description(LogFiles::directory() ?? static::trans('table.missing_directory', ['directory' => static::getPlugin()->getLogDirectory()]))
            ->records(static fn (): array => LogFiles::all())
            ->columns([
                TextColumn::make('name')
                    ->label(static::trans('columns.name'))
                    ->weight('medium')
                    ->fontFamily('mono')
                    ->icon('file-text')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(static::trans('columns.type'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(static fn (string $state): string => static::trans('types.'.$state)),
                TextColumn::make('size')
                    ->label(static::trans('columns.size'))
                    ->formatStateUsing(static fn (mixed $state): string => NumberFormat::fileSize(is_numeric($state) ? (int) $state : 0, 1))
                    ->sortable(),
                TextColumn::make('modified_at')
                    ->label(static::trans('columns.modified_at'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(static::trans('columns.type'))
                    ->options(static fn (): array => ['daily' => static::trans('types.daily'), 'single' => static::trans('types.single')]),
            ])
            ->recordUrl(static fn (array $record): string => LogFile::getUrl(['file' => $record['name']]))
            ->recordActions([
                Action::make('open')
                    ->label(static::trans('actions.open.label'))
                    ->icon('eye')
                    ->color('gray')
                    ->url(static fn (array $record): string => LogFile::getUrl(['file' => $record['name']])),
                static::authorizeOpsAction(
                    Action::make('download')
                        ->label(static::trans('actions.download.label'))
                        ->icon('download')
                        ->color('gray')
                        ->url(static fn (array $record): string => LogFile::getDownloadUrl($record['name'])),
                    OpsPlugin::ABILITY_DOWNLOAD_LOGS,
                ),
                static::makeClearAction(),
                static::makeDeleteAction(),
            ])
            ->defaultSort('modified_at', 'desc')
            ->emptyStateIcon('scroll-text')
            ->emptyStateHeading(static::trans('table.empty.heading'))
            ->emptyStateDescription(static::trans('table.empty.description'));
    }

    /**
     * "Empty file": truncate the log (kept for processes that have it open).
     */
    public static function makeClearAction(?string $file = null): Action
    {
        return static::authorizeOpsAction(
            Action::make('clear')
                ->label(static::trans('actions.clear.label'))
                ->icon('eraser')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading(static::trans('actions.clear.modal_heading'))
                ->modalDescription(static fn (?array $record): string => static::trans('actions.clear.modal_description', ['file' => $file ?? (string) ($record['name'] ?? '')]))
                ->modalSubmitActionLabel(static::trans('actions.clear.submit'))
                ->action(static function (?array $record) use ($file): void {
                    $name = $file ?? (string) ($record['name'] ?? '');
                    $cleared = LogFiles::clear($name);

                    Notification::make()
                        ->title(static::trans($cleared ? 'notifications.cleared' : 'notifications.clear_failed', ['file' => $name]))
                        ->status($cleared ? 'success' : 'danger')
                        ->send();
                }),
            OpsPlugin::ABILITY_DELETE_LOGS,
            isDestructive: true,
        );
    }

    public static function makeDeleteAction(?string $file = null): Action
    {
        return static::authorizeOpsAction(
            Action::make('delete')
                ->label(static::trans('actions.delete.label'))
                ->icon('trash-2')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(static::trans('actions.delete.modal_heading'))
                ->modalDescription(static fn (?array $record): string => static::trans('actions.delete.modal_description', ['file' => $file ?? (string) ($record['name'] ?? '')]))
                ->modalSubmitActionLabel(static::trans('actions.delete.submit'))
                ->successRedirectUrl($file !== null ? static fn (): string => static::getUrl() : null)
                ->action(static function (?array $record) use ($file): void {
                    $name = $file ?? (string) ($record['name'] ?? '');
                    $deleted = LogFiles::delete($name);

                    Notification::make()
                        ->title(static::trans($deleted ? 'notifications.deleted' : 'notifications.delete_failed', ['file' => $name]))
                        ->status($deleted ? 'success' : 'danger')
                        ->send();
                }),
            OpsPlugin::ABILITY_DELETE_LOGS,
            isDestructive: true,
        );
    }
}
