<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\InvoiceLineStatus;
use App\ClubUnio\Models\InvoiceStatus;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class InvoiceStatusResource extends CatalogResource
{
    protected static ?string $model = InvoiceStatus::class;

    protected static ?string $modelLabel = 'Bexio-Statuszuordnung';

    protected static ?string $pluralModelLabel = 'Bexio-Statuszuordnungen';

    protected static ?string $tenantRelationshipName = 'invoiceStatuses';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Bezeichnung')->required()->maxLength(160),
            TextInput::make('code')->label('Bexio-Statuscode')->required()->maxLength(10)
                ->disabled(fn (?Model $record) => $record !== null)->dehydrated(fn (?Model $record) => $record === null),
            Select::make('position_code')->label('Positionsstatus')->required()->searchable()
                ->options(fn () => InvoiceLineStatus::where('tenant_id', Filament::getTenant()->id)->where('active', true)->orderBy('name')->get()->mapWithKeys(fn ($s) => [$s->code => $s->name.' ('.$s->code.')'])),
            Toggle::make('active')->label('Aktiv')->default(true)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return parent::table($table)->columns([
            TextColumn::make('name')->label('Bezeichnung')->searchable()->sortable(),
            TextColumn::make('code')->label('Bexio-Statuscode')->searchable(),
            TextColumn::make('position_code')->label('Positionsstatus'),
            IconColumn::make('active')->label('Aktiv')->boolean(),
        ])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => InvoiceStatusResource\Pages\ManageRecords::route('/')];
    }
}
