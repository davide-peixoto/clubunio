<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Models\EventGuest;
use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Resources\MemberResource;
use App\ClubUnio\Services\DossierMaintenanceService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class EventAttendeesRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'guests';

    protected static string $kind = 'eventGuests';

    protected static ?string $title = 'Gäste';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $save = fn ($member, $id, $revision, $active) => app(DossierMaintenanceService::class)->save(Filament::auth()->user(), Filament::getTenant(), EventGuest::class, (int) $member, $id, ['revision' => $revision, 'active' => $active, 'club_event_id' => $this->getOwnerRecord()->id]);

        return $table->modifyQueryUsing(function ($query) {
            $query->where('tenant_id', Filament::getTenant()->id);
            if (config('clubunio-event-checkin.enabled', false)) {
                $query->select('clubora.event_guests.*')->selectSub(DB::connection('clubunio')->table('clubora.event_checkins as event_presence')
                    ->select('checked_in_at')->whereColumn('event_presence.tenant_id', 'clubora.event_guests.tenant_id')
                    ->whereColumn('event_presence.member_id', 'clubora.event_guests.member_id')
                    ->where('event_id', $this->getOwnerRecord()->id)->limit(1), 'event_checked_in_at');
            }
        })->columns([TextColumn::make('member.display_name')->label('Mitglied')->searchable(query: fn ($query, $search) => $query->whereHas('member', fn ($q) => $q->where(fn ($q) => $q->where('last_name', 'ilike', '%'.$search.'%')->orWhere('first_name', 'ilike', '%'.$search.'%')))), IconColumn::make('active')->label('Aktiv')->boolean(),
            TextColumn::make('event_checked_in_at')->label(__('Attendance confirmed at'))->dateTime('d.m.Y H:i:s', 'Europe/Zurich')
                ->visible(fn () => config('clubunio-event-checkin.enabled', false))->placeholder(__('Not checked in'))])
            ->filters([TernaryFilter::make('active')->label('Aktiv')->default(true)])->headerActions([Action::make('addGuest')->label('Gast hinzufügen')->icon('heroicon-o-plus')->schema([MemberResource::memberSelect('member_id')->required()])->action(fn (array $data) => FormMutation::run($this, fn () => $save($data['member_id'], null, 0, true)))])
            ->recordActions([
                ...ActiveRecordActions::make(fn ($record, $data, $active) => FormMutation::run($this, fn () => $save($record->member_id, $record->id, $data['revision'], $active))),
                Action::make('editGuest')->label('Bearbeiten')->icon('heroicon-o-pencil-square')->iconButton()->tooltip(__('Gastzuordnung bearbeiten'))->schema([Hidden::make('revision'), Toggle::make('active')->label('Aktiv')->required()])->fillForm(fn ($record) => $record->only(['revision', 'active']))->action(fn ($record, array $data) => FormMutation::run($this, fn () => $save($record->member_id, $record->id, $data['revision'], $data['active'])))])->persistFiltersInSession()->persistSearchInSession()->deferFilters(false);
    }
}
