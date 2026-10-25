<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Resources\ProfileUi;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\SponsorRunBillingService;
use App\ClubUnio\Services\SponsorRunService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class SponsorRunBilling extends Page
{
    use WithPagination;

    protected string $view = 'clubunio.sponsor-run-billing';

    protected static ?string $title = 'Sponsorenlauf · Abrechnung';

    protected static bool $shouldRegisterNavigation = false;

    #[Url]
    public ?int $event = null;

    public string $state = '';

    public array $catalogs = [];

    public static function canAccess(): bool
    {
        return ProfileUi::allows('sponsor_runs') && ProfileUi::allows('bexio');
    }

    public function updatedEvent(): void
    {
        $this->resetPage();
    }

    public function updatedState(): void
    {
        $this->resetPage();
    }

    public function loadCatalogs(): void
    {
        $this->catalogs = app(SponsorRunBillingService::class)->catalogs(Filament::auth()->user(), Filament::getTenant());
    }

    public function configureAction(): Action
    {
        $mapping = ['user_id' => 'users', 'language_id' => 'languages', 'bank_account_id' => 'banks', 'currency_id' => 'currencies',
            'payment_type_id' => 'payment_types', 'unit_id' => 'units', 'tax_id' => 'taxes', 'account_id' => 'accounts'];
        $fields = [Toggle::make('enabled')->label('Abrechnung aktiv')->default(false)];
        foreach (SponsorRunBillingService::FIELDS as $key => $label) {
            if (isset($mapping[$key])) {
                $fields[] = Select::make($key)->label($label)->searchable()->options(fn () => collect($this->catalogs[$mapping[$key]] ?? [])
                    ->mapWithKeys(fn ($r) => [$r['id'] => ($r['name'] ?? $r['name_1'] ?? $r['number'] ?? $r['id']).' · '.$r['id']])->all())
                    ->getOptionLabelUsing(fn ($value) => $value ? 'Zuordnung #'.$value : null);
            } elseif ($key === 'mwst_type') {
                $fields[] = Select::make($key)->label($label)->options([0 => 'Inklusive MWST', 1 => 'Steuerbefreit', 2 => 'Ohne MWST']);
            } else {
                $fields[] = TextInput::make($key)->label($label)->integer()->minValue(0)->maxValue(365);
            }
        }

        return Action::make('configure')->label('Buchhaltung zuordnen')->icon('heroicon-o-cog-6-tooth')->slideOver()
            ->visible(fn () => BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant()))
            ->fillForm(fn () => app(SponsorRunBillingService::class)->configuration(Filament::getTenant()))->schema($fields)
            ->action(fn (array $data) => FormMutation::run($this, fn () => app(SponsorRunBillingService::class)->saveConfiguration(Filament::auth()->user(), Filament::getTenant(), $data)));
    }

    public function prepareAction(): Action
    {
        return Action::make('prepare')->label('Rechnung vorbereiten')->icon('heroicon-o-document-plus')->slideOver()
            ->visible(fn () => ProfileUi::allows('sponsor_runs', 'approve'))
            ->schema([Select::make('pledge_id')->label('Freigegebene Zusage')->required()->searchable()
                ->options(fn () => $this->event ? collect(app(SponsorRunService::class)->billablePledges(Filament::auth()->user(), Filament::getTenant(), $this->event))->pluck('label', 'id')->all() : [])])
            ->action(fn (array $data) => FormMutation::run($this, fn () => app(SponsorRunBillingService::class)->prepare(Filament::auth()->user(), Filament::getTenant(), (int) $data['pledge_id'])));
    }

    public function operationAction(): Action
    {
        return Action::make('operation')->label('Rechnungsaktion bestätigen')->requiresConfirmation()->slideOver()
            ->schema(fn (array $arguments) => [Hidden::make('revision')->default($arguments['revision'] ?? 0),
                ...(($arguments['action'] ?? '') === 'link-contact' ? [TextInput::make('contact_id')->label('Bestätigte Bexio-Kontakt-ID')->integer()->minValue(1)->required()] : []),
                ...(($arguments['action'] ?? '') === 'confirm-sent' ? [Textarea::make('reason')->label('Nachweis der manuellen Versandprüfung')->required()->minLength(10)->maxLength(1000)] : [])])
            ->modalDescription(fn (array $arguments) => ($arguments['action'] ?? '') === 'send'
                ? 'Die Testrechnung wird ausschliesslich an admin@svsissach.ch versendet.' : 'Bestehende Zuordnungen und aktuellen Beleg prüfen und Aktion bestätigen.')
            ->action(function (array $arguments, array $data) {
                try {
                    app(SponsorRunBillingService::class)->execute(Filament::auth()->user(), Filament::getTenant(),
                        (int) $arguments['id'], (int) $data['revision'], (string) $arguments['action'], $data);
                    Notification::make()->title('Rechnungsaktion abgeschlossen')->success()->send();
                } catch (\Throwable) {
                    Notification::make()->title('Aktion nicht bestätigt')->body('Prüfauftrag bleibt in Synchronisation erhalten. Keine ungeprüfte Neuerstellung.')->warning()->send();
                }
            });
    }

    protected function getViewData(): array
    {
        $service = app(SponsorRunBillingService::class);
        $service->authorize(Filament::auth()->user(), Filament::getTenant());
        $rows = $service->table(Filament::getTenant())->when($this->event, fn ($q) => $q->where('event_id', $this->event))
            ->when($this->state !== '', fn ($q) => $q->where('state', $this->state))
            ->orderByDesc('id')->paginate(20, ['id', 'event_id', 'pledge_id', 'state', 'revision', 'total_cents', 'remote_id', 'paid_cents', 'credit_cents', 'updated_at']);
        $events = DB::connection('clubunio')->table('clubora.sponsor_run_events')->where('tenant_id', Filament::getTenant()->id)->orderByDesc('id')->limit(100)->pluck('title', 'id');
        $sponsors = DB::connection('clubunio')->table('clubora.sponsor_run_pledges')->where('tenant_id', Filament::getTenant()->id)
            ->whereIn('id', $rows->pluck('pledge_id'))->pluck('sponsor_name', 'id');

        return compact('rows', 'events', 'sponsors') + ['states' => SponsorRunBillingService::STATES,
            'canOperate' => BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant()) && ProfileUi::allows('sponsor_runs', 'approve')];
    }
}
