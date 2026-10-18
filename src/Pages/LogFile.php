<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Infolists\Components\CodeEntry;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Schemas\Components\Callout;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Grid;
use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Enums\PaginationMode;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitOps\Clusters\Operations;
use AlfaSix\AlfakitOps\Http\Controllers\DownloadLogController;
use AlfaSix\AlfakitOps\OpsPlugin;
use AlfaSix\AlfakitOps\Pages\Concerns\InteractsWithOps;
use AlfaSix\AlfakitOps\Support\LogFiles;
use AlfaSix\AlfakitOps\Support\LogReader;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;

/**
 * The entries of one log file, newest first, read from the end of the file a page at a
 * time: filter by level, search, open an entry for its context and stack trace; download,
 * empty or delete the file. `/{panel}/ops/logs/{file}`.
 *
 * @phpstan-import-type LogEntry from LogReader
 */
class LogFile extends Page
{
    use InteractsWithOps;

    protected static ?string $slug = 'log-file';

    protected static ?string $routePath = '/logs/{file}';

    protected static ?string $cluster = Operations::class;

    protected static bool $isDiscovered = false;

    protected static bool $shouldRegisterNavigation = false;

    protected ?string $file = null;

    protected ?string $path = null;

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

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * The page, plus `{page}/download`: a signed route that sends the file.
     */
    public static function registerRoutes(Panel $panel): void
    {
        parent::registerRoutes($panel);

        Route::get(static::getClusterRoutePrefix().ltrim(static::getRoutePath(), '/').'/download', DownloadLogController::class)
            ->name(static::getRelativeRouteName().'.download')
            ->middleware('signed');
    }

    /**
     * A temporary signed link that downloads the log file `$name`.
     */
    public static function getDownloadUrl(string $name): string
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();
        $parameters = ['file' => $name];
        $tenant = $panel->hasTenancy() ? alfakit()->getTenant() : null;

        if ($tenant !== null) {
            $parameters['tenant'] = $panel->getTenantRouteKey($tenant);
        }

        return URL::temporarySignedRoute(
            $panel->generateRouteName(static::getRelativeRouteName().'.download'),
            now()->addMinutes(static::getPlugin()->getDownloadLinkLifetime()),
            $parameters,
        );
    }

    public function mount(mixed ...$parameters): void
    {
        $file = $parameters[0] ?? null;
        $path = is_string($file) ? LogFiles::resolve($file) : null;

        abort_if($path === null, 404);

        $this->file = (string) $file;
        $this->path = $path;
    }

    protected function flushCaches(): void
    {
        parent::flushCaches();

        $this->file = null;
        $this->path = null;
    }

    public function getFile(): string
    {
        return (string) $this->file;
    }

    public function getTitle(): string
    {
        return $this->getFile();
    }

    public function getSubheading(): ?string
    {
        $row = LogFiles::find($this->getFile());

        return $row === null ? null : static::trans('file_summary', [
            'size' => (string) Number::fileSize($row['size'], precision: 1),
            'modified' => (string) Schedule::relative(\Carbon\CarbonImmutable::parse($row['modified_at'])->toIso8601String()),
        ]);
    }

    /**
     * @return array<string, string>|array<int, string>
     */
    public function getBreadcrumbs(): array
    {
        return [Logs::getUrl() => Logs::getNavigationLabel(), $this->getFile()];
    }

    protected function getHeaderActions(): array
    {
        $file = $this->getFile();

        return [
            static::authorizeOpsAction(
                Action::make('download')
                    ->label(static::trans('actions.download.label'))
                    ->icon('download')
                    ->outlined()
                    ->url(static fn (): string => static::getDownloadUrl($file)),
                OpsPlugin::ABILITY_DOWNLOAD_LOGS,
            ),
            Logs::makeClearAction($file),
            Logs::makeDeleteAction($file),
        ];
    }

    public function table(Table $table): Table
    {
        $path = (string) $this->path;

        return $table
            ->heading(static::trans('entries.heading'))
            ->description(static::trans('entries.description'))
            ->records(static fn (?string $search, array $filters, int $page, int|string $recordsPerPage) => LogReader::paginate(
                $path,
                $page,
                is_int($recordsPerPage) ? $recordsPerPage : 100,
                array_values(array_filter((array) ($filters['level']['values'] ?? []), is_string(...))),
                $search,
            ))
            ->resolveRecordUsing(static fn (string $key): ?array => ctype_digit($key) ? LogReader::entryAt($path, (int) $key) : null)
            ->paginationMode(PaginationMode::Simple)
            ->paginationPageOptions([25, 50, 100])
            ->columns([
                TextColumn::make('level')
                    ->label(static::trans('columns.level'))
                    ->badge()
                    ->formatStateUsing(static fn (?string $state): string => static::levelLabel($state))
                    ->color(static fn (?string $state): string => LogReader::levelColor($state))
                    ->icon(static fn (?string $state): string => LogReader::levelIcon($state))
                    ->placeholder('—'),
                TextColumn::make('date')
                    ->label(static::trans('columns.date'))
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('message')
                    ->label(static::trans('columns.message'))
                    ->fontFamily('mono')
                    ->lineClamp(3)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('channel')
                    ->label(static::trans('columns.channel'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('level')
                    ->label(static::trans('columns.level'))
                    ->multiple()
                    ->options(static fn (): array => array_combine(LogReader::LEVELS, array_map(static::levelLabel(...), LogReader::LEVELS))),
            ])
            ->recordActions([
                Action::make('view')
                    ->label(static::trans('actions.view.label'))
                    ->icon('eye')
                    ->color('gray')
                    ->slideOver()
                    ->modalWidth(Width::FourExtraLarge)
                    ->modalHeading(static fn (array $record): string => static::levelLabel($record['level']))
                    ->modalDescription(static fn (array $record): ?string => $record['date'])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(static::trans('actions.view.close'))
                    ->modalContent(static fn (array $record): array => static::entrySchema($record)),
            ])
            ->recordAction('view')
            ->emptyStateIcon('scroll-text')
            ->emptyStateHeading(static::trans('entries.empty.heading'))
            ->emptyStateDescription(static::trans('entries.empty.description'));
    }

    public static function levelLabel(?string $level): string
    {
        return $level === null ? static::trans('levels.unknown') : static::trans('levels.'.$level);
    }

    /**
     * @param  LogEntry  $record
     * @return array<Component>
     */
    public static function entrySchema(array $record): array
    {
        return array_values(array_filter([
            Grid::make(['default' => 1, 'sm' => 3])->schema([
                TextEntry::make('level')
                    ->label(static::trans('columns.level'))
                    ->state(static::levelLabel($record['level']))
                    ->badge()
                    ->color(LogReader::levelColor($record['level'])),
                TextEntry::make('date')
                    ->label(static::trans('columns.date'))
                    ->state($record['date'])
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('channel')
                    ->label(static::trans('columns.channel'))
                    ->state($record['channel'])
                    ->placeholder('—'),
            ]),
            CodeEntry::make('message')
                ->label(static::trans('columns.message'))
                ->state($record['message'])
                ->copyable(),
            $record['context'] !== null ? CodeEntry::make('context')
                ->label(static::trans('columns.context'))
                ->state($record['context'])
                ->grammar(str_starts_with(ltrim($record['context']), '{') && json_decode($record['context']) !== null ? 'json' : null)
                ->copyable() : null,
            $record['truncated'] ? Callout::make(static::trans('truncated'))->warning() : null,
        ]));
    }
}
