<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Components\Callout;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitOps\Clusters\Operations;
use AlfaSix\AlfakitOps\OpsPlugin;
use AlfaSix\AlfakitOps\Pages\Concerns\InteractsWithOps;
use AlfaSix\AlfakitOps\Support\MaintenanceTasks;
use Illuminate\Support\Str;

/**
 * Cache and maintenance: clear (and rebuild) the application, config, route, view, event,
 * compiled and AlfaKit caches, `optimize` / `optimize:clear`, and maintenance mode with a
 * secret bypass link.
 */
class Maintenance extends Page
{
    use InteractsWithOps;

    protected static ?string $slug = 'maintenance';

    protected static ?string $cluster = Operations::class;

    protected static bool $isDiscovered = false;

    protected static ?string $navigationIcon = 'wrench';

    protected static ?int $navigationSort = 70;

    public static function getOpsFeature(): string
    {
        return OpsPlugin::FEATURE_MAINTENANCE;
    }

    protected static function getOpsTranslationKey(): string
    {
        return 'maintenance';
    }

    protected static function trans(string $key, array $replace = []): string
    {
        return (string) __('alfakit-ops::ops.maintenance.'.$key, $replace);
    }

    protected function getHeaderActions(): array
    {
        return [
            static::authorizeOpsAction(
                Action::make('optimize')
                    ->label(static::trans('actions.optimize.label'))
                    ->icon('zap')
                    ->outlined()
                    ->requiresConfirmation()
                    ->modalHeading(static::trans('actions.optimize.modal_heading'))
                    ->modalDescription(static::trans('actions.optimize.modal_description'))
                    ->modalSubmitActionLabel(static::trans('actions.optimize.submit'))
                    ->action(static fn (): null => static::notifyCommand('optimize', static::trans('notifications.optimized'))),
                OpsPlugin::ABILITY_OPTIMIZE,
            ),
            static::authorizeOpsAction(
                Action::make('clearAll')
                    ->label(static::trans('actions.clear_all.label'))
                    ->icon('eraser')
                    ->outlined()
                    ->requiresConfirmation()
                    ->modalHeading(static::trans('actions.clear_all.modal_heading'))
                    ->modalDescription(static::trans('actions.clear_all.modal_description'))
                    ->modalSubmitActionLabel(static::trans('actions.clear_all.submit'))
                    ->action(static fn (): null => static::notifyCommand('optimize:clear', static::trans('notifications.cleared_all'))),
                OpsPlugin::ABILITY_CLEAR_CACHES,
            ),
            static::authorizeOpsAction(
                Action::make('down')
                    ->label(static::trans('actions.down.label'))
                    ->icon('construction')
                    ->color('warning')
                    ->button()
                    ->modalIcon('construction')
                    ->modalHeading(static::trans('actions.down.modal_heading'))
                    ->modalDescription(static::trans('actions.down.modal_description'))
                    ->modalSubmitActionLabel(static::trans('actions.down.submit'))
                    ->visible(static fn (): bool => ! MaintenanceTasks::isDown())
                    ->form([
                        TextInput::make('secret')
                            ->label(static::trans('actions.down.secret'))
                            ->helperText(static::trans('actions.down.secret_help'))
                            ->default(static fn (): string => Str::random(32))
                            ->required()
                            ->minLength(16)
                            ->maxLength(128)
                            ->regex('/^[A-Za-z0-9_-]+$/'),
                        TextInput::make('retry')
                            ->label(static::trans('actions.down.retry'))
                            ->helperText(static::trans('actions.down.retry_help'))
                            ->integer()
                            ->minValue(1)
                            ->suffix(static::trans('seconds')),
                        TextInput::make('refresh')
                            ->label(static::trans('actions.down.refresh'))
                            ->helperText(static::trans('actions.down.refresh_help'))
                            ->integer()
                            ->minValue(1)
                            ->suffix(static::trans('seconds')),
                    ])
                    ->action(static function (array $data): void {
                        $secret = (string) $data['secret'];
                        [$exitCode, $output] = MaintenanceTasks::down(
                            $secret,
                            is_numeric($data['retry'] ?? null) ? (int) $data['retry'] : null,
                            is_numeric($data['refresh'] ?? null) ? (int) $data['refresh'] : null,
                        );

                        if ($exitCode !== 0) {
                            Notification::make()->danger()->title(static::trans('notifications.failed'))->body($output)->send();

                            return;
                        }

                        // The bypass link sets the cookie, then sends the user back here.
                        session()->put('url.intended', static::getUrl());

                        Notification::make()
                            ->warning()
                            ->persistent()
                            ->title(static::trans('notifications.down'))
                            ->body(static::trans('notifications.down_body', ['url' => url($secret)]))
                            ->send();
                    })
                    ->successRedirectUrl(static fn (array $data): ?string => MaintenanceTasks::isDown() && is_string($data['secret'] ?? null) ? url($data['secret']) : null),
                OpsPlugin::ABILITY_MAINTENANCE_MODE,
                isDestructive: true,
            ),
            static::authorizeOpsAction(
                Action::make('up')
                    ->label(static::trans('actions.up.label'))
                    ->icon('radio')
                    ->color('success')
                    ->button()
                    ->requiresConfirmation()
                    ->modalHeading(static::trans('actions.up.modal_heading'))
                    ->modalDescription(static::trans('actions.up.modal_description'))
                    ->modalSubmitActionLabel(static::trans('actions.up.submit'))
                    ->visible(static fn (): bool => MaintenanceTasks::isDown())
                    ->action(static fn (): null => static::notifyCommand('up', static::trans('notifications.up'))),
                OpsPlugin::ABILITY_MAINTENANCE_MODE,
            ),
        ];
    }

    public function content(Schema $schema): Schema
    {
        $bypass = MaintenanceTasks::bypassUrl();

        return $schema->components([
            MaintenanceTasks::isDown()
                ? Callout::make(static::trans('status.down_heading'))
                    ->description(static::trans($bypass !== null ? 'status.down_description' : 'status.down_description_no_secret'))
                    ->icon('construction')
                    ->warning()
                    ->footer($bypass !== null ? [
                        TextEntry::make('bypass_url')
                            ->label(static::trans('status.bypass_url'))
                            ->state($bypass)
                            ->fontFamily('mono')
                            ->copyable(),
                    ] : null)
                : Callout::make(static::trans('status.live_heading'))
                    ->description(static::trans('status.live_description'))
                    ->icon('radio')
                    ->success(),
        ]);
    }

    /**
     * The status callout above the cache table.
     *
     * @return array<array<string, mixed>>
     */
    protected function getContentBlocks(): array
    {
        $blocks = parent::getContentBlocks();

        usort($blocks, static fn (array $a, array $b): int => (($b['key'] ?? null) === 'content') <=> (($a['key'] ?? null) === 'content'));

        return $blocks;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(static::trans('table.heading'))
            ->description(static::trans('table.description'))
            ->records(static fn (): array => MaintenanceTasks::rows())
            ->columns([
                TextColumn::make('id')
                    ->label(static::trans('columns.cache'))
                    ->weight('medium')
                    ->formatStateUsing(static fn (string $state): string => static::trans('tasks.'.$state.'.label'))
                    ->description(static fn (array $record): string => static::trans('tasks.'.$record['id'].'.description')),
                TextColumn::make('command')
                    ->label(static::trans('columns.command'))
                    ->fontFamily('mono')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(static::trans('columns.status'))
                    ->badge()
                    ->getStateUsing(static fn (array $record): ?string => match ($record['status']) {
                        true => 'cached',
                        false => 'empty',
                        default => null,
                    })
                    ->formatStateUsing(static fn (?string $state): ?string => $state === null ? null : static::trans('statuses.'.$state))
                    ->color(static fn (?string $state): string => $state === 'cached' ? 'success' : 'gray')
                    ->placeholder('—'),
            ])
            ->recordActions([
                static::authorizeOpsAction(
                    Action::make('cache')
                        ->label(static::trans('actions.cache.label'))
                        ->icon('zap')
                        ->color('gray')
                        ->visible(static fn (array $record): bool => $record['cache_command'] !== null)
                        ->action(static fn (array $record): null => static::notifyCommand(
                            (string) $record['cache_command'],
                            static::trans('notifications.cached', ['cache' => static::trans('tasks.'.$record['id'].'.label')]),
                        )),
                    OpsPlugin::ABILITY_OPTIMIZE,
                ),
                static::authorizeOpsAction(
                    Action::make('clear')
                        ->label(static::trans('actions.clear.label'))
                        ->icon('eraser')
                        ->color('gray')
                        ->action(static fn (array $record): null => static::notifyCommand(
                            $record['command'],
                            static::trans('notifications.cleared', ['cache' => static::trans('tasks.'.$record['id'].'.label')]),
                        )),
                    OpsPlugin::ABILITY_CLEAR_CACHES,
                ),
            ])
            ->paginated(false);
    }

    /**
     * Run an Artisan command and report how it went.
     */
    protected static function notifyCommand(string $command, string $success): null
    {
        [$exitCode, $output] = MaintenanceTasks::call($command);

        $notification = Notification::make()
            ->title($exitCode === 0 ? $success : static::trans('notifications.failed'))
            ->body($output !== '' ? mb_strimwidth(strip_tags($output), 0, 300, '…') : null);

        ($exitCode === 0 ? $notification->success() : $notification->danger())->send();

        return null;
    }
}
