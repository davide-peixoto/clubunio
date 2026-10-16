<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Services\MemberNoteService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Table;

class NotesRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'notes';

    protected static string $kind = 'notes';

    protected static ?string $title = 'Bemerkungen';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $fields = [Textarea::make('body')->label('Bemerkung')->required()->rows(6)->maxLength(10000),
            Toggle::make('active')->label('Aktiv')->default(true),
            Hidden::make('revision')];
        $save = fn ($id, $data) => app(MemberNoteService::class)->save(
            Filament::auth()->user()->fresh(), Filament::getTenant(), $this->getOwnerRecord()->id, $id, $data
        );

        return parent::table($table)->headerActions([
            Action::make('addNote')->label('Bemerkung hinzufügen')->icon('heroicon-o-plus')->schema($fields)
                ->action(fn (array $data) => FormMutation::run($this, fn () => $save(null, $data))),
        ])->recordActions([
            ...ActiveRecordActions::make(fn ($record, $data, $active) => FormMutation::run($this, fn () => $save($record->id, ['body' => $record->body, 'revision' => $data['revision'], 'active' => $active]))),
            Action::make('changeNote')->label('Bearbeiten')->icon('heroicon-o-pencil-square')->iconButton()->tooltip('Bemerkung bearbeiten')
                ->fillForm(fn ($record) => $record->only(['body', 'active', 'revision']))->schema($fields)
                ->action(fn ($record, array $data) => FormMutation::run($this, fn () => $save($record->id, $data))),
        ]);
    }
}
