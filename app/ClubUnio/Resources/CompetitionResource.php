<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Competition;
use App\ClubUnio\Pages\SfvCompetitions;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Validator;

class CompetitionResource extends FacilityResource
{
    protected static ?string $model = Competition::class;

    protected static ?string $modelLabel = 'Wettkampf';

    protected static ?string $pluralModelLabel = 'Wettkämpfe';

    protected static ?string $tenantRelationshipName = 'competitions';

    public static function form(Schema $schema): Schema
    {
        $fields = [Hidden::make('revision'), DatePicker::make('plays_on')->label('Datum')->required(), TimePicker::make('starts_at')->label('Zeit')->seconds(false)];
        foreach (['host' => 'Gastgeber', 'guest' => 'Gast'] as $prefix => $label) {
            $fields[] = TextInput::make($prefix.'_name')->label($label)->required()->maxLength(255);
            $fields[] = TextInput::make($prefix.'_category')->label($label.' Kategorie')->maxLength(100);
            $fields[] = Select::make($prefix.'_club_number')->label($label.' Verein')->searchable()
                ->getSearchResultsUsing(fn (string $search) => FootballClubResource::searchClubs($search))
                ->getOptionLabelUsing(fn ($value) => FootballClubResource::clubLabel($value));
            foreach (['room' => 'Garderobe', 'side' => 'Seite'] as $category => $caption) {
                $fields[] = Select::make($prefix.'_'.$category.'_id')->label($label.' '.$caption)->options(fn () => TrainingSlotResource::choices($category));
            }
        }
        $fields[] = Select::make('field_id')->label('Spielfeld')->options(fn () => TrainingSlotResource::choices('field'));
        $fields[] = Textarea::make('remarks')->label('Bemerkungen')->maxLength(10000);
        $fields[] = TextInput::make('association_match_id')->label('SFV Match-ID')->disabled()->dehydrated(false);
        $fields[] = TextInput::make('source')->label('Quelle')->disabled()->dehydrated(false);
        $fields[] = Toggle::make('show_on_signage')->label('Auf Signage anzeigen')->default(true);
        $fields[] = Toggle::make('active')->label('Aktiv')->default(true);

        return $schema->components($fields);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('plays_on')->label('Datum')->date('d.m.Y')->sortable(), TextColumn::make('starts_at')->label('Zeit')->sortable(),
            TextColumn::make('host_name')->label('Gastgeber')->searchable(), TextColumn::make('host_category')->label('Kategorie')->toggleable(),
            TextColumn::make('hostRoom.name')->label('Garderobe Gastgeber'), TextColumn::make('hostSide.name')->label('Seite Gastgeber')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('guest_name')->label('Gast')->searchable(), TextColumn::make('guest_category')->label('Kategorie')->toggleable(), TextColumn::make('guestRoom.name')->label('Garderobe Gast'), TextColumn::make('guestSide.name')->label('Seite Gast')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('field.name')->label('Spielfeld'), TextColumn::make('remarks')->label('Bemerkungen')->wrap()->toggleable(),
            TextColumn::make('host_club_number')->label('Vereinsnummer Gastgeber')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('guest_club_number')->label('Vereinsnummer Gast')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('association_match_id')->label('SFV Match-ID')->toggleable(isToggledHiddenByDefault: true), TextColumn::make('source')->label('Quelle')->toggleable(),
            IconColumn::make('show_on_signage')->label('Signage')->boolean(), IconColumn::make('active')->label('Aktiv')->boolean(),
        ])->filters([
            Filter::make('upcoming')->label('Ab heute')->default()->query(fn ($query) => $query->where('plays_on', '>=', now('Europe/Zurich')->toDateString())),
            Filter::make('missing_club')->label('Vereinszuordnung prüfen')->query(fn ($query) => $query->where(function ($q) {
                foreach (['host', 'guest'] as $side) {
                    $q->orWhereNotExists(fn ($clubs) => $clubs->selectRaw('1')->from('clubora.football_clubs')
                        ->whereColumn('football_clubs.tenant_id', 'competitions.tenant_id')
                        ->whereColumn('football_clubs.club_number', 'competitions.'.$side.'_club_number')->where('football_clubs.active', true));
                }
            })),
            Filter::make('date')->schema([DatePicker::make('from')->label('Von'), DatePicker::make('until')->label('Bis')])
                ->indicateUsing(fn (array $data) => array_filter([empty($data['from']) ? null : 'Von '.$data['from'], empty($data['until']) ? null : 'Bis '.$data['until']]))
                ->query(function ($query, array $data) {
                    if (Validator::make($data, ['from' => 'nullable|date_format:Y-m-d', 'until' => 'nullable|date_format:Y-m-d'])->fails()) {
                        return $query->whereRaw('1=0');
                    }

                    return $query->when($data['from'] ?? null, fn ($q, $date) => $q->where('plays_on', '>=', $date))->when($data['until'] ?? null, fn ($q, $date) => $q->where('plays_on', '<=', $date));
                }),
            SelectFilter::make('field_id')->label('Spielfeld')->options(fn () => TrainingSlotResource::choices('field')), TernaryFilter::make('show_on_signage')->label('Signage'), TernaryFilter::make('active')->label('Aktiv')->default(true),
        ])->headerActions([Action::make('sfv')->label('SFV-Übernahme')->icon('heroicon-o-arrow-down-tray')->url(fn () => SfvCompetitions::getUrl()), static::createAction()])->recordActions([static::editAction()])->defaultSort('plays_on')->deferFilters(false)->persistFiltersInSession()->persistSearchInSession();
    }

    public static function getPages(): array
    {
        return ['index' => CompetitionResource\Pages\ManageCompetitions::route('/')];
    }
}
