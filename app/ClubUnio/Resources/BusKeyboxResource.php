<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\BusKeybox;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BusKeyboxAccessService;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class BusKeyboxResource extends CatalogResource
{
    protected static ?string $model = BusKeybox::class;

    protected static ?string $modelLabel = 'Schlüsselbox-Testzuordnung';

    protected static ?string $pluralModelLabel = 'Schlüsselbox-Testzuordnungen';

    protected static ?string $tenantRelationshipName = 'busKeyboxes';

    public static function canViewAny(): bool
    {
        return Filament::getTenant() && BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant());
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Anlagen und Spielbetrieb';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('revision')->label('Version')->default(0)->readOnly()->required(),
            TextInput::make('name')->label('Bezeichnung')->required()->maxLength(160),
            Select::make('bus_id')->label('Bus')->required()->options(fn () => DB::connection('clubunio')->table('clubora.buses')->where('tenant_id', Filament::getTenant()->id)->where('active', true)->pluck('name', 'id')),
            Toggle::make('active')->label('Simulation aktiv')->required()->default(false)]);
    }

    public static function table(Table $table): Table
    {
        $save = fn ($record, $data, $livewire) => FormMutation::run($livewire, fn () => app(BusKeyboxAccessService::class)->configure(Filament::auth()->user(), Filament::getTenant(), $record?->id, $data));

        return $table->columns([TextColumn::make('name')->label('Bezeichnung')->searchable(), TextColumn::make('bus.name')->label('Bus'),
            TextColumn::make('mode')->label('Modus')->formatStateUsing(fn () => 'Simulation – keine echte Schlüsselbox'), IconColumn::make('active')->label('Simulation aktiv')->boolean()])
            ->headerActions([CreateAction::make()->label('Testzuordnung erstellen')->using(fn ($data, $livewire) => $save(null, $data, $livewire))])
            ->recordActions([EditAction::make()->using(fn ($record, $data, $livewire) => $save($record, $data, $livewire))]);
    }

    public static function getPages(): array
    {
        return ['index' => BusKeyboxResource\Pages\ManageRecords::route('/')];
    }
}
