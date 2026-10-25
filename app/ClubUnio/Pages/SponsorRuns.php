<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\SponsorRunParticipant;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\SponsorRunPublicService;
use App\ClubUnio\Services\SponsorRunService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class SponsorRuns extends Page
{
    protected string $view = 'clubunio.sponsor-runs';

    protected static ?string $title = 'Sponsorenlauf';

    protected static ?string $slug = 'sponsor-runs';

    #[Locked]
    public ?int $eventId = null;

    #[Locked]
    public ?int $participantId = null;

    #[Locked]
    public ?string $shareUrl = null;

    public ?int $season = null;

    public string $status = '';

    public string $search = '';

    public string $participantSearch = '';

    public ?int $team = null;

    public int $eventPage = 1;

    public int $participantPage = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Sport';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-flag';
    }

    public static function canAccess(): bool
    {
        return Filament::auth()->user() && Filament::getTenant() && app(SponsorRunService::class)->allows(Filament::auth()->user(), Filament::getTenant());
    }

    private function service(): SponsorRunService
    {
        return app(SponsorRunService::class);
    }

    private function actor(): Account
    {
        return Filament::auth()->user();
    }

    private function allowed(string $function): bool
    {
        return $this->service()->allows($this->actor(), Filament::getTenant(), $function);
    }

    public function updatedSeason(): void
    {
        $this->eventPage = 1;
        $this->eventId = null;
        $this->participantId = null;
    }

    public function updatedSearch(): void
    {
        $this->eventPage = 1;
    }

    public function updatedStatus(): void
    {
        $this->eventPage = 1;
    }

    public function updatedParticipantSearch(): void
    {
        $this->participantPage = 1;
    }

    public function updatedTeam(): void
    {
        $this->participantPage = 1;
    }

    public function openEvent(int $id): void
    {
        $this->service()->authorize($this->actor(), Filament::getTenant());
        $this->service()->event(Filament::getTenant(), $id);
        $this->eventId = $id;
        $this->participantId = null;
        $this->shareUrl = null;
        $this->participantPage = 1;
        $this->team = null;
    }

    public function openParticipant(int $id): void
    {
        $this->service()->authorize($this->actor(), Filament::getTenant());
        SponsorRunParticipant::where('tenant_id', Filament::getTenant()->id)->where('event_id', $this->eventId)->findOrFail($id);
        $this->participantId = $id;
        $this->shareUrl = null;
    }

    private function eventSchema(bool $edit = false): array
    {
        $tenant = Filament::getTenant();
        $choices = $this->service()->choices($this->actor(), $tenant);

        return [Hidden::make('revision')->default(0), Select::make('season_id')->label('Saison')->options($choices['seasons'])->required()->live()->disabled($edit)->dehydrated(),
            TextInput::make('title')->label('Titel')->required()->maxLength(160), TextInput::make('place')->label('Ort')->required()->maxLength(160),
            DatePicker::make('event_date')->label('Lauftag')->required(), DatePicker::make('registration_deadline')->label('Zusagefrist')->required(),
            Select::make('status_code')->label('Status')->options($choices['statuses'])->default('draft')->required(),
            CheckboxList::make('team_ids')->label('Pflichtmannschaften')->options(fn ($get) => $this->service()->choices($this->actor(), $tenant, (int) $get('season_id'))['teams'])->required()->columns(2),
            Textarea::make('reason')->label('Begründung')->required($edit)->maxLength(1000)];
    }

    public function newEventAction(): Action
    {
        return Action::make('newEvent')->label('Anlass anlegen')->icon('heroicon-o-plus')->visible(fn () => $this->allowed('create'))
            ->schema(fn () => $this->eventSchema())->fillForm(fn () => ['revision' => 0, 'status_code' => 'draft', 'season_id' => $this->season])
            ->action(function (array $data) {
                $r = $this->service()->saveEvent($this->actor(), Filament::getTenant(), null, $data);
                $this->openEvent($r->id);
            });
    }

    public function editEventAction(): Action
    {
        return Action::make('editEvent')->label('Anlass bearbeiten')->icon('heroicon-o-pencil-square')->visible(fn () => $this->eventId && $this->allowed('update'))
            ->schema(fn () => $this->eventSchema(true))->fillForm(function () {
                $r = $this->service()->event(Filament::getTenant(), $this->eventId);

                return $r->only(['season_id', 'title', 'place', 'status_code', 'revision']) + ['event_date' => $r->event_date->toDateString(), 'registration_deadline' => $r->registration_deadline->toDateString(),
                    'team_ids' => $this->service()->db()->table('clubora.sponsor_run_event_teams')->where('tenant_id', $r->tenant_id)->where('event_id', $r->id)->pluck('team_season_id')->all()];
            })->action(fn (array $data) => $this->service()->saveEvent($this->actor(), Filament::getTenant(), $this->eventId, $data));
    }

    public function cancelEventAction(): Action
    {
        return Action::make('cancelEvent')->label('Anlass absagen')->icon('heroicon-o-x-circle')->color('danger')->visible(fn () => $this->eventId && $this->allowed('cancel'))
            ->schema([Hidden::make('revision'), Textarea::make('reason')->label('Begründung')->required()->maxLength(1000)])
            ->fillForm(fn () => ['revision' => $this->service()->event(Filament::getTenant(), $this->eventId)->revision])
            ->requiresConfirmation()->action(fn (array $data) => $this->service()->cancelEvent($this->actor(), Filament::getTenant(), $this->eventId, (int) $data['revision'], $data['reason']));
    }

    public static function pledgeSchema(): array
    {
        return [Hidden::make('request_key')->default(fn () => (string) Str::uuid()), TextInput::make('sponsor_name')->label('Sponsorname')->required()->maxLength(160),
            Select::make('amount_type')->label('Zusageart')->options(['per_lap' => 'Pro Runde', 'fixed' => 'Fixbetrag'])->required()->default('per_lap'),
            TextInput::make('amount_cents')->label('Betrag in Rappen')->numeric()->minValue(1)->maxValue(10000000)->required(), TextInput::make('cap_cents')->label('Obergrenze in Rappen')->numeric()->minValue(1)->maxValue(10000000),
            Select::make('payment_mode')->label('Zahlungsweg')->options(['invoice' => 'Rechnung', 'collective' => 'Sammelzahlung'])->default('invoice')->required(),
            Checkbox::make('collector_confirmed')->label('Ich übernehme diese Sammelzahlung persönlich'),
            Select::make('recipient_type')->label('Rechnungsempfaenger')->options(['person' => 'Person', 'company' => 'Firma'])->default('person')->required(),
            TextInput::make('company')->label('Firma')->maxLength(160), TextInput::make('first_name')->label('Vorname / Kontakt')->required()->maxLength(100), TextInput::make('last_name')->label('Nachname')->required()->maxLength(100),
            TextInput::make('street')->label('Strasse')->required()->maxLength(160), TextInput::make('house_number')->label('Hausnummer')->maxLength(20),
            TextInput::make('postal_code')->label('PLZ')->required()->maxLength(20), TextInput::make('city')->label('Ort')->required()->maxLength(100),
            TextInput::make('country')->label('Land (ISO)')->default('CH')->required()->length(2), TextInput::make('email')->label('E-Mail')->email()->required()->maxLength(254)];
    }

    public function pledgeAction(): Action
    {
        return Action::make('pledge')->label('Zusage erfassen')->icon('heroicon-o-plus')->visible(fn () => $this->participantId && $this->allowed('create'))->schema(self::pledgeSchema())
            ->action(fn (array $data) => $this->service()->addPledge($this->actor(), Filament::getTenant(), $this->participantId, $data));
    }

    public function operationAction(): Action
    {
        return Action::make('operation')->label('Erfassung')->icon('heroicon-o-pencil')->visible(fn () => $this->participantId && ($this->allowed('update') || $this->allowed('approve')))
            ->schema([Hidden::make('revision'), Select::make('action')->label('Aktion')->options(fn () => array_filter(['checkin' => $this->allowed('update') ? 'Check-in' : null, 'laps' => $this->allowed('update') ? 'Rundenentwurf' : null, 'approve' => $this->allowed('approve') ? 'Runden endgültig freigeben' : null]))->required(),
                Checkbox::make('checked_in')->label('Eingecheckt'), TextInput::make('laps')->label('Runden')->numeric()->minValue(0)->maxValue(10000),
                Checkbox::make('confirm_final')->label('Ich bestätige den aktuellen Rundenentwurf endgültig')])
            ->fillForm(function () {
                $p = SponsorRunParticipant::where('tenant_id', Filament::getTenant()->id)->findOrFail($this->participantId);

                return ['revision' => $p->revision, 'checked_in' => $p->checked_in_at !== null, 'laps' => $p->draft_laps];
            })
            ->action(function (array $data) {
                abort_if($data['action'] === 'approve' && ! ($data['confirm_final'] ?? false), 422);
                $this->service()->operations($this->actor(), Filament::getTenant(), $this->participantId, (int) $data['revision'], $data['action'], $data['action'] === 'checkin' ? (bool) ($data['checked_in'] ?? false) : $data['laps']);
            })->requiresConfirmation();
    }

    public function helperAction(): Action
    {
        return Action::make('helper')->label('Helfer pflegen')->icon('heroicon-o-user-plus')->visible(fn () => $this->eventId && $this->allowed('update'))
            ->schema([Select::make('account_id')->label('Benutzer')->searchable()->required()->live()->afterStateUpdated(function ($state, $set) {
                $this->service()->authorize($this->actor(), Filament::getTenant(), 'update');
                $e = $this->service()->event(Filament::getTenant(), $this->eventId);
                $old = $this->service()->db()->table('clubora.sponsor_run_helpers')->where('tenant_id', Filament::getTenant()->id)->where('event_id', $e->id)->where('account_id', $state)->first();
                $day = CarbonImmutable::parse($e->event_date->toDateString(), 'Europe/Zurich');
                foreach (['revision' => $old->revision ?? 0, 'can_checkin' => $old->can_checkin ?? false, 'can_record_laps' => $old->can_record_laps ?? false,
                    'valid_from' => $old->valid_from ?? $day->startOfDay()->toIso8601String(), 'valid_until' => $old->valid_until ?? $day->endOfDay()->toIso8601String()] as $key => $value) {
                    $set($key, $value);
                }
            })->getSearchResultsUsing(function (string $search) {
                if (mb_strlen($search) < 2) {
                    return [];
                }
                $tenant = Filament::getTenant();

                return Account::where('active', true)->whereHas('tenants', fn ($q) => $q->where('clubora.tenants.id', $tenant->id))
                    ->whereIn('id', $this->service()->db()->table('clubora.account_member_links')->where('tenant_id', $tenant->id)->where('kind', 'self')->where('state', 'approved')
                        ->whereIn('member_id', app(MemberAccessService::class)->activeMembers($tenant)->select('clubora.members.id'))->select('account_id'))
                    ->where('display_name', 'ilike', '%'.mb_substr($search, 0, 100).'%')->limit(20)->pluck('display_name', 'id')->all();
            }), Hidden::make('revision')->default(0),
                Checkbox::make('can_checkin')->label('Check-in'), Checkbox::make('can_record_laps')->label('Rundenentwurf'),
                DateTimePicker::make('valid_from')->label('Gültig ab')->timezone('Europe/Zurich')->required(), DateTimePicker::make('valid_until')->label('Gültig bis')->timezone('Europe/Zurich')->required()])
            ->action(fn (array $data) => $this->service()->saveHelper($this->actor(), Filament::getTenant(), $this->eventId, $data));
    }

    public function shareAction(): Action
    {
        return Action::make('share')->label('Sponsorenlink')->icon('heroicon-o-link')->visible(fn () => $this->participantId && $this->allowed('send'))
            ->schema([Select::make('action')->label('Aktion')->options(['show' => 'Anzeigen', 'create' => 'Erstellen', 'rotate' => 'Ersetzen', 'revoke' => 'Widerrufen'])->required()->default('show'),
                TextInput::make('alias')->label('Öffentlicher Alias')->maxLength(80), Checkbox::make('consent')->label('Diesen Alias ausdruecklich öffentlich freigeben')])
            ->action(function (array $data) {
                $result = app(SponsorRunPublicService::class)->share($this->actor(), Filament::getTenant(), $this->participantId, $data['action'], $data);
                $this->shareUrl = $result['url'];
            });
    }

    public function publicConfigurationAction(): Action
    {
        return Action::make('publicConfiguration')->label('Öffentliche Testzusagen')->icon('heroicon-o-cog-6-tooth')
            ->visible(fn () => $this->allowed('send') && app(MemberAccessService::class)->membership($this->actor(), Filament::getTenant())?->role === 'admin')
            ->schema([Checkbox::make('enabled')->label('Öffentliche Testzusagen für diesen Verein freigeben')])
            ->fillForm(fn () => ['enabled' => app(SponsorRunPublicService::class)->publicEnabled(Filament::getTenant())])
            ->action(fn (array $data) => app(SponsorRunPublicService::class)->configurePublic($this->actor(), Filament::getTenant(), (bool) $data['enabled']))->requiresConfirmation();
    }

    private function outbox()
    {
        return $this->service()->db()->table('clubora.sponsor_run_public_outbox as o')
            ->join('clubora.sponsor_run_public_pending as p', fn ($j) => $j->on('p.id', '=', 'o.pending_id')->on('p.tenant_id', '=', 'o.tenant_id'))
            ->join('clubora.sponsor_run_links as l', fn ($j) => $j->on('l.id', '=', 'p.link_id')->on('l.tenant_id', '=', 'p.tenant_id'))
            ->where('o.tenant_id', Filament::getTenant()->id)->where('l.event_id', $this->eventId);
    }

    private function canTestSend(): bool
    {
        return $this->allowed('send') && app(MemberAccessService::class)->membership($this->actor(), Filament::getTenant())?->role === 'admin';
    }

    public function testOutboxAction(): Action
    {
        return Action::make('testOutbox')->label('Testmail senden')->icon('heroicon-o-envelope')->visible(fn () => $this->eventId && $this->canTestSend())
            ->modalHeading('Testmail an admin@svsissach.ch senden')->modalSubmitActionLabel('An Testadresse senden')
            ->schema([Select::make('outbox_id')->label('Bestätigung in Test-Outbox')->required()->options(fn () => $this->outbox()
                ->where('o.delivery_state', 'held')->whereNotNull('o.payload_encrypted')->whereNull('p.pledge_id')->whereNull('l.revoked_at')
                ->where('p.expires_at', '>', now()->toIso8601String())->where('l.expires_at', '>', now()->toIso8601String())->whereColumn('p.link_revision', 'l.revision')
                ->orderByDesc('o.id')->limit(50)->get(['o.id', 'l.alias'])->mapWithKeys(fn ($r) => [$r->id => '#'.$r->id.' / '.$r->alias])->all()),
                Checkbox::make('confirm_test')->label('Ausschliesslich an admin@svsissach.ch senden')->accepted()->required()])
            ->requiresConfirmation()->action(function (array $data) {
                abort_unless($this->canTestSend() && ($data['confirm_test'] ?? false), 403);
                abort_unless($this->outbox()->where('o.id', (int) $data['outbox_id'])->exists(), 404);
                app(SponsorRunPublicService::class)->dispatchTestOutbox($this->actor(), Filament::getTenant(), (int) $data['outbox_id']);
            });
    }

    protected function getViewData(): array
    {
        $s = $this->service();
        $tenant = Filament::getTenant();
        $a = $this->actor();
        $s->authorize($a, $tenant);
        $events = $s->events($a, $tenant, ['season_id' => $this->season, 'status_code' => $this->status, 'search' => $this->search])->paginate(20, ['*'], 'events', max(1, $this->eventPage));
        $event = $this->eventId ? $s->event($tenant, $this->eventId) : null;
        $participants = $event ? $s->participants($a, $tenant, $event->id, ['search' => $this->participantSearch, 'team_season_id' => $this->team])->paginate(20, ['*'], 'participants', max(1, $this->participantPage)) : null;
        $selected = $this->participantId ? SponsorRunParticipant::where('tenant_id', $tenant->id)->where('event_id', $this->eventId)->findOrFail($this->participantId) : null;

        return ['events' => $events, 'event' => $event, 'choices' => $s->choices($a, $tenant, $event?->season_id ?? $this->season), 'participants' => $participants,
            'summaries' => $participants ? $s->summaries($a, $tenant, $participants->pluck('id')->all()) : [], 'helpers' => $event ? $s->helpers($a, $tenant, $event->id) : [],
            'selected' => $selected, 'pledges' => $selected ? $s->pledges($a, $tenant, $selected->id)->paginate(20) : null, 'canBill' => $this->allowed('approve'),
            'outbox' => $event && $this->canTestSend() ? $this->outbox()->orderByDesc('o.id')->limit(20)->get(['o.id', 'o.delivery_state', 'o.created_at', 'l.alias']) : collect()];
    }
}
