<?php

namespace App\ClubUnio\Resources;

use Closure;
use Filament\Actions\Action;

class FinancialDossierDialog
{
    public static function make(string $component, Closure $parentId, ?Closure $recordId = null, string $name = 'view'): Action
    {
        return Action::make($name)->label('Anzeigen / Bearbeiten')->icon('heroicon-o-eye')->iconButton()->tooltip('Details und Fachaktionen')
            ->slideOver()->modalWidth('7xl')->modalSubmitAction(false)->modalCancelActionLabel('Schliessen')
            ->modalContent(fn ($record = null) => view('clubunio.finance.embedded-dialog', [
                'component' => $component, 'recordId' => $recordId ? $recordId() : $record->id, 'dossierId' => $parentId(),
            ]));
    }
}
