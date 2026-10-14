<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\MemberDocument;
use Filament\Actions\Action;
use Filament\Facades\Filament;

class DocumentActions
{
    public static function make(): array
    {
        return [
            Action::make('previewDocument')->label('Vorschau')->icon('heroicon-o-eye')->iconButton()->tooltip('Dokument ansehen')
                ->visible(fn (MemberDocument $record) => $record->active)->slideOver()->modalHeading('Dokument')
                ->modalSubmitAction(false)->modalCancelActionLabel('Schliessen')->modalWidth('5xl')
                ->modalContent(fn (MemberDocument $record) => view('clubunio.documents.preview', ['url' => self::url($record), 'name' => $record->original_filename ?: 'Dokument'])),
            Action::make('downloadDocument')->label('Herunterladen')->icon('heroicon-o-arrow-down-tray')->iconButton()->tooltip('Dokument herunterladen')
                ->visible(fn (MemberDocument $record) => $record->active)->url(fn (MemberDocument $record) => self::url($record, true))->openUrlInNewTab(),
        ];
    }

    private static function url(MemberDocument $record, bool $download = false): string
    {
        abort_unless((int) $record->tenant_id === (int) Filament::getTenant()->id, 403);

        return route('clubunio.documents.open', ['tenant' => Filament::getTenant()->slug, 'document' => $record->id, 'download' => $download ? 1 : 0]);
    }
}
