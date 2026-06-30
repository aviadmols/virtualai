<?php

namespace App\Filament\Platform\Resources;

use App\Domain\Platform\PlatformActivityQuery;
use App\Filament\Platform\Resources\ActivityEventResource\Pages\ListActivityEvents;
use App\Models\ActivityEvent;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * P8 — Observability / activity log (READ-ONLY, cross-account).
 *
 * ActivityEvent IS BelongsToAccount and the timeline is APPEND-ONLY (ActivityRecorder
 * is its only writer). The table query goes through the AUDITED super-admin seam
 * PlatformActivityQuery::withAccount() — the one sanctioned withoutGlobalScope path,
 * guarded by PlatformGuard. NO inline withoutGlobalScopes() here, and NO
 * create/edit/delete: the log is observed, never edited.
 *
 * No secret ever appears in a row: the subject is rendered as type#id only, never a
 * hydrated payload — so neither the OpenRouter key nor a widget_secret can leak (the
 * details JSON is intentionally NOT surfaced as a column).
 */
class ActivityEventResource extends Resource
{
    // === CONSTANTS ===
    protected static ?string $model = ActivityEvent::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    // Attaches to the locked nav order (Observability group) in PlatformPanelProvider.
    protected static ?string $navigationGroup = 'platform.nav.observability';

    protected static ?int $navigationSort = 1;

    // i18n label keys (platform.logs.* + activity.* for the per-kind labels).
    private const LABEL_SINGULAR = 'platform.logs.singular';
    private const NAV_LABEL = 'platform.logs.title';

    // The per-kind / per-actor label catalogs (activity.php), mirrored EN↔HE.
    private const KIND_PREFIX = 'activity.kind.';
    private const ACTOR_PREFIX = 'platform.logs.actor.';

    public static function getModelLabel(): string
    {
        return __(self::LABEL_SINGULAR);
    }

    public static function getNavigationLabel(): string
    {
        return __(self::NAV_LABEL);
    }

    public static function getNavigationGroup(): ?string
    {
        return __(self::$navigationGroup);
    }

    // --- READ-ONLY: the timeline is observed, never edited. ---
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('account.name')
                    ->label(__('platform.logs.col.account'))
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('kind')
                    ->label(__('platform.logs.col.kind'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(static fn (string $state): string => self::kindLabel($state))
                    ->searchable(),
                TextColumn::make('actor')
                    ->label(__('platform.logs.col.actor'))
                    ->formatStateUsing(static fn (string $state): string => __(self::ACTOR_PREFIX.$state))
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('subject')
                    ->label(__('platform.logs.col.subject'))
                    ->state(static fn (ActivityEvent $r): string => self::subject($r))
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(__('platform.logs.col.date'))
                    ->dateTime()
                    ->sortable()
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('actor')
                    ->label(__('platform.logs.filter.actor'))
                    ->options(self::actorOptions()),
            ])
            ->actions([
                // Per-event detail so a super-admin can SEE why something happened (e.g. a
                // failed generation's failure_code + provider_status + message). The details
                // are recorder-curated, non-secret scalars — never a hydrated payload.
                ViewAction::make()
                    ->label(__('platform.logs.view'))
                    ->modalHeading(static fn (ActivityEvent $r): string => self::kindLabel($r->kind))
                    ->infolist([
                        TextEntry::make('account.name')->label(__('platform.logs.col.account')),
                        TextEntry::make('kind')->label(__('platform.logs.col.kind'))
                            ->formatStateUsing(static fn (string $state): string => self::kindLabel($state)),
                        TextEntry::make('actor')->label(__('platform.logs.col.actor'))
                            ->formatStateUsing(static fn (string $state): string => __(self::ACTOR_PREFIX.$state)),
                        TextEntry::make('subject')->label(__('platform.logs.col.subject'))
                            ->state(static fn (ActivityEvent $r): string => self::subject($r)),
                        TextEntry::make('created_at')->label(__('platform.logs.col.date'))->dateTime(),
                        TextEntry::make('details')->label(__('platform.logs.details_label'))
                            ->state(static fn (ActivityEvent $r): string => self::detailsText($r))
                            ->fontFamily('mono')
                            ->copyable(),
                    ]),
            ])
            ->emptyStateHeading(__('platform.logs.empty'))
            ->emptyStateDescription(__('platform.logs.empty_sub'))
            ->emptyStateIcon('heroicon-o-clipboard-document-list')
            ->defaultSort('created_at', 'desc');
    }

    /**
     * The table query is the AUDITED cross-account seam (super-admin guarded),
     * eager-loading the owning account. The ONLY sanctioned bypass of the
     * BelongsToAccount global scope — never an inline withoutGlobalScopes() here.
     */
    public static function getEloquentQuery(): Builder
    {
        return PlatformActivityQuery::withAccount();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityEvents::route('/'),
        ];
    }

    /**
     * A localised label for an event kind. Falls back to the humanised token when a
     * specific key is absent, so a NEW backend kind never renders blank — it shows a
     * readable form until its key is catalogued (and never raw English in HE for a
     * known kind, which is mirrored 1:1).
     */
    private static function kindLabel(string $kind): string
    {
        $key = self::KIND_PREFIX.$kind;
        $translated = __($key);

        return $translated === $key ? ucfirst(str_replace('_', ' ', $kind)) : $translated;
    }

    /**
     * The event's curated details rendered as readable key: value lines (e.g.
     * "failure_code: ai_call_failed", "provider_status: 404", "message: …"). Recorder
     * details are non-secret scalars; nested values fall back to a compact JSON encode.
     */
    private static function detailsText(ActivityEvent $event): string
    {
        $details = $event->details ?? [];

        if ($details === []) {
            return __('platform.logs.details_empty');
        }

        $lines = [];
        foreach ($details as $key => $value) {
            $rendered = is_scalar($value) || $value === null
                ? (string) ($value ?? '—')
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $lines[] = $key.': '.$rendered;
        }

        return implode("\n", $lines);
    }

    /** subject_type#subject_id (no payload) — never a hydrated row, never a secret. */
    private static function subject(ActivityEvent $event): string
    {
        if ($event->subject_type === null) {
            return __('platform.logs.subject.none');
        }

        $short = class_basename($event->subject_type);

        return $event->subject_id !== null ? $short.' #'.$event->subject_id : $short;
    }

    /** Actor → localised label options, for the filter select. */
    private static function actorOptions(): array
    {
        return [
            ActivityEvent::ACTOR_SYSTEM => __(self::ACTOR_PREFIX.ActivityEvent::ACTOR_SYSTEM),
            ActivityEvent::ACTOR_MERCHANT => __(self::ACTOR_PREFIX.ActivityEvent::ACTOR_MERCHANT),
            ActivityEvent::ACTOR_END_USER => __(self::ACTOR_PREFIX.ActivityEvent::ACTOR_END_USER),
            ActivityEvent::ACTOR_WEBHOOK => __(self::ACTOR_PREFIX.ActivityEvent::ACTOR_WEBHOOK),
        ];
    }
}
