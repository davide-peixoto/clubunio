<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\ClubEvent;
use App\ClubUnio\Models\ClubFunction;
use App\ClubUnio\Models\Qualification;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

class DossierMaintenanceFields
{
    public static function make(string $kind): array
    {
        $options = fn ($model, $field) => fn ($record) => $model::where('tenant_id', Filament::getTenant()->id)->where(fn ($q) => $q->where('active', true)->orWhere('id', $record?->$field ?? -1))->orderBy('name')->pluck('name', 'id');
        $fields = match ($kind) {
            'functions' => [Select::make('club_function_id')->label('Funktion')->required()->searchable()->options($options(ClubFunction::class, 'club_function_id')),
                Select::make('qualification_ids')->label('Qualifikationen')->multiple()->searchable()->default([])->options(fn ($record) => Qualification::where('tenant_id', Filament::getTenant()->id)->where(fn ($q) => $q->where('active', true)->orWhereIn('id', $record?->qualifications()->pluck('clubora.qualifications.id')->all() ?? []))->orderBy('name')->pluck('name', 'id'))],
            'keys' => [TextInput::make('number')->label('Schlüsselnummer')->required()->maxLength(100), TextInput::make('name')->label('Bezeichnung')->required()->maxLength(255), TextInput::make('color')->label('Farbe')->required()->maxLength(100)],
            'sponsorships' => [TextInput::make('name')->label('Sponsor')->required()->maxLength(255), TextInput::make('amount')->label('Betrag CHF')->numeric()->minValue(0)->required(), DatePicker::make('expires_on')->label('Laufzeit bis')->required(), Textarea::make('notes')->label('Bemerkung')->maxLength(4000)],
            'eventGuests' => [Select::make('club_event_id')->label('Veranstaltung')->required()->searchable()->options($options(ClubEvent::class, 'club_event_id'))],
        };

        return [...$fields, Toggle::make('active')->label('Aktiv')->default(true)->required(), Hidden::make('revision')->default(0)];
    }
}
