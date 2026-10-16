<?php

namespace App\ClubUnio\Resources\Relations;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;

final class ActiveRecordActions
{
    public static function make(Closure $save, ?Closure $allowed = null, ?Closure $versions = null, string $deactivateLabel = 'Deaktivieren'): array
    {
        return array_map(fn (bool $active) => Action::make($active ? 'restoreRecord' : 'deactivateRecord')
            ->label($active ? 'Wiederherstellen' : $deactivateLabel)
            ->icon($active ? 'heroicon-o-arrow-path' : 'heroicon-o-archive-box')->iconButton()
            ->tooltip($active ? 'Wiederherstellen' : $deactivateLabel)->color($active ? 'primary' : 'danger')
            ->visible(fn ($record) => (bool) $record->active !== $active && (! $allowed || $allowed($record)))
            ->requiresConfirmation()->modalDescription('Der Eintrag und seine Historie bleiben erhalten.')
            ->fillForm(fn ($record) => $versions ? $versions($record) : ['revision' => $record->revision])
            ->schema([Hidden::make('revision')->required(), Hidden::make('expense_revision')])
            ->action(fn ($record, array $data) => $save($record, $data, $active)), [false, true]);
    }
}
