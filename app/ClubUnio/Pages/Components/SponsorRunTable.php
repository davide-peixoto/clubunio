<?php

namespace App\ClubUnio\Pages\Components;

use App\ClubUnio\Models\SponsorRunParticipant;
use App\ClubUnio\Pages\SponsorRuns;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\SponsorRunMoney;
use App\ClubUnio\Services\SponsorRunService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class SponsorRunTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Locked]
    public string $kind;

    #[Locked]
    public int $eventId;

    #[Locked]
    public ?int $participantId = null;

    #[On('sponsor-run-tables-refresh')]
    public function refreshRecords(): void
    {
        $this->flushCachedTableRecords();
    }

    private function context(): array
    {
        $service = app(SponsorRunService::class);
        $actor = Filament::auth()->user();
        $tenant = Filament::getTenant();
        abort_unless($actor && $tenant, 403);
        $service->authorize($actor, $tenant);
        $event = $service->event($tenant, $this->eventId);
        abort_unless(in_array($this->kind, ['participants', 'pledges', 'helpers', 'outbox'], true), 404);
        if ($this->kind === 'outbox') {
            abort_unless($service->allows($actor, $tenant, 'send') && app(MemberAccessService::class)->membership($actor, $tenant)?->role === 'admin', 403);
        }
        if ($this->kind === 'pledges') {
            SponsorRunParticipant::where('tenant_id', $tenant->id)->where('event_id', $event->id)->findOrFail($this->participantId);
        }

        return [$service, $actor, $tenant, $event];
    }

    public function table(Table $table): Table
    {
        [$service, $actor, $tenant, $event] = $this->context();
        $money = fn ($state) => SponsorRunMoney::format($state);
        $columns = match ($this->kind) {
            'participants' => [
                TextColumn::make('name')->label('Teilnehmer')->searchable()->sortable(),
                TextColumn::make('team_label')->label('Mannschaft')->sortable(),
                IconColumn::make('required')->label('Pflicht')->boolean(),
                IconColumn::make('checked_in')->label('Check-in')->boolean(),
                TextColumn::make('draft_laps')->label('Runden Entwurf')->placeholder('-'),
                TextColumn::make('final_laps')->label('Runden final')->placeholder('-'),
                TextColumn::make('pledge_count')->label('Zusagen'),
                TextColumn::make('total_cents')->label('Betrag final')->formatStateUsing($money)->placeholder('-'),
            ],
            'pledges' => [
                TextColumn::make('sponsor_name')->label('Sponsor')->searchable()->sortable(),
                TextColumn::make('amount_type')->label('Zusage')->formatStateUsing(fn ($state) => $state === 'fixed' ? 'Fixbetrag' : 'Pro Runde'),
                TextColumn::make('amount_cents')->label('Betrag')->formatStateUsing($money),
                TextColumn::make('cap_cents')->label('Obergrenze')->formatStateUsing($money)->placeholder('-'),
                TextColumn::make('payment_mode')->label('Zahlungsweg')->formatStateUsing(fn ($state) => $state === 'invoice' ? 'Rechnung' : 'Sammelzahlung'),
            ],
            'helpers' => [
                TextColumn::make('display_name')->label('Benutzer')->searchable()->sortable(),
                IconColumn::make('can_checkin')->label('Check-in')->boolean(),
                IconColumn::make('can_record_laps')->label('Runden')->boolean(),
                TextColumn::make('valid_from')->label('Ab')->dateTime('d.m.Y H:i', timezone: 'Europe/Zurich'),
                TextColumn::make('valid_until')->label('Bis')->dateTime('d.m.Y H:i', timezone: 'Europe/Zurich'),
                TextColumn::make('revision')->label('Revision'),
            ],
            'outbox' => [
                TextColumn::make('id')->label('Auftrag')->sortable(),
                TextColumn::make('alias')->label('Alias')->searchable()->sortable(),
                TextColumn::make('delivery_state')->label('Status')->badge(),
                TextColumn::make('created_at')->label('Erstellt')->dateTime('d.m.Y H:i', timezone: 'Europe/Zurich')->sortable(),
            ],
        };
        $filters = match ($this->kind) {
            'participants' => [SelectFilter::make('team_season_id')->label('Mannschaft')->options($service->choices($actor, $tenant, $event->season_id)['teams'])],
            'pledges' => [SelectFilter::make('payment_mode')->label('Zahlungsweg')->options(['invoice' => 'Rechnung', 'collective' => 'Sammelzahlung'])],
            default => [],
        };

        return $table->heading(match ($this->kind) {
            'participants' => 'Teilnehmer', 'pledges' => 'Zusagen', 'helpers' => 'Helfereinsätze', 'outbox' => 'Testmail-Outbox',
        })->records($this->records(...))->columns($columns)->filters($filters)->deferFilters(false)
            ->recordActions($this->kind === 'participants' ? [
                Action::make('view')->label('Teilnehmer öffnen')->icon('heroicon-o-arrow-right')->iconButton()->tooltip('Teilnehmer öffnen')
                    ->action(fn (array $record) => $this->dispatch('sponsor-run-participant-selected', id: (int) $record['id'])->to(SponsorRuns::class)),
            ] : [])->paginated([10, 20, 50])->defaultPaginationPageOption(20)->emptyStateHeading('Keine Einträge.');
    }

    private function records(?string $search, ?array $filters, int $page, int|string $recordsPerPage, ?string $sortColumn, ?string $sortDirection): LengthAwarePaginator
    {
        $recordsPerPage = in_array((string) $recordsPerPage, ['10', '20', '50'], true) ? (int) $recordsPerPage : 20;
        $page = max(1, $page);
        [$service, $actor, $tenant] = $this->context();
        $search = mb_substr(trim($search ?? ''), 0, 100);
        $filters ??= [];
        if ($this->kind === 'helpers') {
            $rows = collect($service->helpers($actor, $tenant, $this->eventId))
                ->filter(fn ($row) => $search === '' || mb_stripos($row['display_name'], $search) !== false);
            if ($sortColumn === 'display_name') {
                $rows = $rows->sortBy('display_name', SORT_NATURAL, $sortDirection === 'desc');
            }

            return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage)->map(fn ($row) => $row + ['__key' => (string) $row['id']])->values(), $rows->count(), $recordsPerPage, $page);
        }

        $query = match ($this->kind) {
            'participants' => $service->participants($actor, $tenant, $this->eventId, ['search' => $search, 'team_season_id' => $filters['team_season_id']['value'] ?? null]),
            'pledges' => $service->pledges($actor, $tenant, $this->participantId)
                ->when($search !== '', fn ($q) => $q->where('sponsor_name', 'ilike', '%'.$search.'%'))
                ->when($filters['payment_mode']['value'] ?? null, fn ($q, $value) => $q->where('payment_mode', $value)),
            'outbox' => $service->db()->table('clubora.sponsor_run_public_outbox as o')
                ->join('clubora.sponsor_run_public_pending as p', fn ($j) => $j->on('p.id', '=', 'o.pending_id')->on('p.tenant_id', '=', 'o.tenant_id'))
                ->join('clubora.sponsor_run_links as l', fn ($j) => $j->on('l.id', '=', 'p.link_id')->on('l.tenant_id', '=', 'p.tenant_id'))
                ->where('o.tenant_id', $tenant->id)->where('l.event_id', $this->eventId)
                ->when($search !== '', fn ($q) => $q->where('l.alias', 'ilike', '%'.$search.'%'))
                ->select('o.id', 'o.delivery_state', 'o.created_at', 'l.alias')->orderByDesc('o.id'),
        };
        $sorts = match ($this->kind) {
            'participants' => ['name' => 'm.last_name', 'team_label' => 't.label'],
            'pledges' => ['sponsor_name' => 'sponsor_name'],
            'outbox' => ['id' => 'o.id', 'alias' => 'l.alias', 'created_at' => 'o.created_at'],
        };
        if (isset($sorts[$sortColumn])) {
            $query->reorder($sorts[$sortColumn], $sortDirection === 'desc' ? 'desc' : 'asc');
        }
        $rows = $query->paginate($recordsPerPage, ['*'], 'page', $page);
        $summaries = $this->kind === 'participants' ? $service->summaries($actor, $tenant, $rows->pluck('id')->all()) : [];
        $rows->setCollection($rows->getCollection()->map(function ($row) use ($summaries) {
            $data = $row instanceof Model ? $row->toArray() : (array) $row;
            if ($this->kind === 'participants') {
                $data += ['name' => $data['first_name'].' '.$data['last_name'], 'checked_in' => $data['checked_in_at'] !== null,
                    'pledge_count' => $summaries[$data['id']]['pledge_count'] ?? 0, 'total_cents' => $summaries[$data['id']]['total_cents'] ?? null];
            }

            return $data + ['__key' => (string) $data['id']];
        }));

        return $rows;
    }

    public function render()
    {
        return view('clubunio.components.sponsor-run-table');
    }
}
