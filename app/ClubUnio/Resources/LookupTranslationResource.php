<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\LookupTranslation;
use App\ClubUnio\Services\LookupTranslations;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class LookupTranslationResource extends Resource
{
    protected static ?string $model = LookupTranslation::class;

    // Explicit scoping avoids adding a relationship to the shared Tenant model.
    protected static bool $isScopedToTenant = false;

    public static function getModelLabel(): string
    {
        return __('Lookup-Übersetzung');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Lookup-Übersetzungen');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Stammdaten');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-language';
    }

    public static function canViewAny(): bool
    {
        $service = app(LookupTranslations::class);

        return $service->allowed(Filament::auth()->user(), Filament::getTenant()) && $service->available();
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny() && (int) $record->tenant_id === (int) Filament::getTenant()->id;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        abort_unless(self::canViewAny(), 403);

        return parent::getEloquentQuery()->where('tenant_id', Filament::getTenant()->id);
    }

    private static function tables(): array
    {
        return array_map(fn ($label) => __($label), app(LookupTranslations::class)->tables());
    }

    private static function source(string $table, string $id): ?object
    {
        abort_unless(self::canViewAny(), 403);
        $service = app(LookupTranslations::class);
        if ($service->tableName($table) !== $table) {
            return null;
        }
        $query = DB::connection('clubunio')->table('clubora.'.$table)->where($service->sourceKey($table), $id);
        if (! $service->isGlobal($table)) {
            $query->where('tenant_id', Filament::getTenant()->id);
        }

        return $query->first();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('revision')->default(0),
            Select::make('source_table')->label(__('Lookup-Tabelle'))->options(fn () => self::tables())
                ->required()->live()->disabled(fn (?Model $record) => $record !== null)->dehydrated()
                ->afterStateUpdated(function ($set) {
                    $set('source_id', null);
                    $set('source_name', null);
                }),
            Select::make('source_id')->label(__('Lookup-Wert'))->required()->searchable()->live()
                ->disabled(fn (?Model $record) => $record !== null)->dehydrated()
                ->getSearchResultsUsing(function (string $search, $get) {
                    abort_unless(static::canViewAny(), 403);

                    return $get('source_table') ? app(LookupTranslations::class)->sourceOptions($get('source_table'), Filament::getTenant(), $search) : [];
                })
                ->getOptionLabelUsing(fn ($value, $get) => $value && $get('source_table') ? self::source($get('source_table'), (string) $value)?->name : null)
                ->afterStateUpdated(fn ($state, $get, $set) => $set('source_name', self::source($get('source_table'), (string) $state)?->name)),
            TextInput::make('source_name')->label(__('Unveränderter Quellwert'))->readOnly()->required(),
            Select::make('locale')->label(__('Sprache'))->options(fn () => array_intersect_key(config('clubunio-languages.enabled'), array_flip(['de', 'en'])))
                ->required()->disabled(fn (?Model $record) => $record !== null)->dehydrated(),
            TextInput::make('label')->label(__('Anzeigetext'))->required()->maxLength(255),
            Textarea::make('reason')->label(__('Begründung'))->required()->minLength(10)->maxLength(500),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('source_table')->label(__('Lookup-Tabelle'))->formatStateUsing(fn ($state) => self::tables()[$state] ?? $state)->sortable(),
            TextColumn::make('source_id')->label(__('Lookup-ID')),
            TextColumn::make('source_name')->label(__('Unveränderter Quellwert'))->searchable()->wrap(),
            TextColumn::make('locale')->label(__('Sprache'))->sortable(),
            TextColumn::make('label')->label(__('Anzeigetext'))->searchable()->wrap(),
            TextColumn::make('revision')->label(__('Revision')),
        ])->filters([
            SelectFilter::make('source_table')->label(__('Lookup-Tabelle'))->options(fn () => self::tables()),
            SelectFilter::make('locale')->label(__('Sprache'))->options(fn () => config('clubunio-languages.enabled')),
        ])->headerActions([
            CreateAction::make()->using(fn (array $data, $livewire) => FormMutation::run($livewire,
                fn () => app(LookupTranslations::class)->save(Filament::auth()->user(), Filament::getTenant(), null, $data))),
        ])->recordActions([
            EditAction::make()->iconButton()->tooltip(__('Bearbeiten'))
                ->mutateRecordDataUsing(function (array $data) {
                    $data['source_name'] = static::source($data['source_table'], $data['source_id'])?->name ?? $data['source_name'];

                    return $data;
                })
                ->using(fn (Model $record, array $data, $livewire) => FormMutation::run($livewire,
                    fn () => app(LookupTranslations::class)->save(Filament::auth()->user(), Filament::getTenant(), $record->id, $data))),
        ])->defaultSort('source_table')->deferFilters(false);
    }

    public static function getPages(): array
    {
        return ['index' => LookupTranslationResource\Pages\ManageLookupTranslations::route('/')];
    }
}
