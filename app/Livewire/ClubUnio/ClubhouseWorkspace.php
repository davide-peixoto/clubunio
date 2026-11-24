<?php

namespace App\Livewire\ClubUnio;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Services\ActiveTenant;
use App\ClubUnio\Services\ClubhouseAccess;
use App\ClubUnio\Services\ClubhouseHours;
use App\ClubUnio\Services\ClubhouseMenus;
use App\ClubUnio\Services\ClubhouseService;
use App\ClubUnio\Services\TenantDomainService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class ClubhouseWorkspace extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    #[Locked]
    public int $tenantId;

    #[Locked]
    public int $actorId;

    #[Locked]
    public string $guard;

    #[Locked]
    public string $host;

    #[Locked]
    public array $snapshot = [];

    public string $tab = 'overview';

    public string $menuStatus = 'all';

    public string $menuSearch = '';

    public int $menuPage = 1;

    public string $previewChannel = 'web';

    private ?array $renderCapabilities = null;

    public function mount(int $tenantId, string $guard = 'clubunio'): void
    {
        abort_unless(in_array($guard, ['clubunio', 'portal'], true), 403);
        $this->tenantId = $tenantId;
        $this->guard = $guard;
        $this->actorId = (int) auth($guard)->id();
        $this->host = request()->getHost();
        if ($guard === 'clubunio') {
            $this->snapshot = request()->session()->get(app(ActiveTenant::class)->key(request()), []);
        }
        $this->context();
    }

    private function context(): array
    {
        $actor = auth($this->guard)->user()?->fresh();
        abort_unless($actor && (int) $actor->id === $this->actorId && $this->host === request()->getHost(), 403);
        $tenant = Tenant::findOrFail($this->tenantId);
        $bound = app(TenantDomainService::class)->resolve(request());
        abort_if($bound && ! $bound->is($tenant), 404);
        if ($this->guard === 'clubunio') {
            app(ActiveTenant::class)->assertSnapshot(request(), $this->snapshot);
        }
        app(ClubhouseAccess::class)->authorize($actor, $tenant, 'view');

        return [$actor, $tenant];
    }

    public function can(string $ability): bool
    {
        if ($this->renderCapabilities !== null) {
            $key = match ($ability) {
                'hours.write' => 'hours_write', 'menus.write' => 'menus_write',
                'menus.publish' => 'menus_publish', 'view' => 'manage', default => null,
            };

            return $key !== null && $this->renderCapabilities[$key] === true;
        }
        return app(ClubhouseAccess::class)->allows(...[...$this->context(), $ability]);
    }

    public function selectTab(string $tab): void
    {
        [$actor, $tenant] = $this->context();
        abort_unless(in_array($tab, ['overview', 'hours', 'menus', 'access'], true), 422);
        abort_if($tab === 'access' && ! app(ClubhouseAccess::class)->administrator($actor, $tenant), 403);
        $this->tab = $tab;
    }

    public function updatedMenuStatus(): void
    {
        $this->menuPage = 1;
    }

    public function updatedMenuSearch(): void
    {
        $this->menuPage = 1;
    }

    private function saved(): void
    {
        Notification::make()->title(trans('clubhouse.saved'))->success()->send();
    }

    private function slotSchema(): array
    {
        return [Repeater::make('intervals')->label(trans('clubhouse.time_windows'))->minItems(1)->maxItems(8)->defaultItems(1)->columns(3)
            ->schema([TextInput::make('start')->label(trans('clubhouse.from_time'))->type('time')->required(),
                TextInput::make('end')->label(trans('clubhouse.to_time'))->type('time')->required(),
                Toggle::make('overnight')->label(trans('clubhouse.next_day'))->default(false)->required()])];
    }

    private function openingSchema(bool $edit): array
    {
        return [Hidden::make('revision')->dehydrated($edit),
            TextInput::make('title')->label(trans('clubhouse.label'))->required()->maxLength(160),
            DatePicker::make('starts_on')->label(trans('clubhouse.valid_from'))->required(),
            DatePicker::make('ends_on')->label(trans('clubhouse.valid_until')),
            CheckboxList::make('weekdays')->label(trans('clubhouse.repeat_days'))->columns(4)
                ->options(collect(range(1, 7))->mapWithKeys(fn ($day) => [$day => trans('clubhouse.weekday_'.$day)])->all())->default([]),
            ...$this->slotSchema(), Toggle::make('active')->label(trans('clubhouse.active'))->default(true)->required(),
            Select::make('scope')->label(trans('clubhouse.change_scope'))->default('all')->required()->live()->visible($edit)->dehydrated($edit)
                ->options(collect(['all', 'this', 'future'])->mapWithKeys(fn ($value) => [$value => trans('clubhouse.scope_'.$value)])->all()),
            DatePicker::make('on')->label(trans('clubhouse.occurrence'))->visible(fn (Get $get) => $edit && $get('scope') !== 'all')
                ->required(fn (Get $get) => $edit && $get('scope') !== 'all')->dehydrated(fn (Get $get) => $edit && $get('scope') !== 'all')];
    }

    public function newOpeningAction(): Action
    {
        return Action::make('newOpening')->label(trans('clubhouse.add_opening'))->icon('heroicon-o-plus')->schema($this->openingSchema(false))
            ->visible(fn () => $this->can('hours.write'))
            ->fillForm(fn () => ['starts_on' => now()->toDateString(), 'active' => true, 'weekdays' => [],
                'intervals' => [['start' => '18:00', 'end' => '22:00', 'overnight' => false]]])
            ->action(fn (array $data) => FormMutation::run($this, function () use ($data) {
                app(ClubhouseHours::class)->save(...[...$this->context(), null, $data]);
                $this->saved();
            }));
    }

    public function editOpeningAction(): Action
    {
        return Action::make('editOpening')->label(trans('clubhouse.edit'))->iconButton()->tooltip(trans('clubhouse.edit'))->icon('heroicon-o-pencil-square')->schema($this->openingSchema(true))
            ->visible(fn () => $this->can('hours.write'))->fillForm(function (array $arguments) {
                [, $tenant] = $this->context();
                $service = app(ClubhouseHours::class);
                $row = $service->rules($tenant)->where('id', (int) $arguments['id'])->firstOrFail();

                $data = (array) $row + ['scope' => 'all'];
                $data['weekdays'] = $service->decode($row->weekdays);
                $data['intervals'] = $service->decode($row->intervals);

                return $data;
            })
            ->action(fn (array $arguments, array $data) => FormMutation::run($this, function () use ($arguments, $data) {
                app(ClubhouseHours::class)->save(...[...$this->context(), (int) $arguments['id'], $data]);
                $this->saved();
            }));
    }

    public function exceptionAction(): Action
    {
        return Action::make('exception')->label(trans('clubhouse.add_exception'))->icon('heroicon-o-calendar-days')
            ->visible(fn () => $this->can('hours.write'))->schema([DatePicker::make('date')->label(trans('clubhouse.date'))->required(),
                Toggle::make('closed')->label(trans('clubhouse.closed_day'))->default(true)->live()->required(),
                Repeater::make('intervals')->label(trans('clubhouse.time_windows'))->maxItems(8)->default([])
                    ->visible(fn (Get $get) => ! $get('closed'))->dehydrated()->schema([
                        TextInput::make('start')->label(trans('clubhouse.from_time'))->type('time')->required(),
                        TextInput::make('end')->label(trans('clubhouse.to_time'))->type('time')->required(),
                        Toggle::make('overnight')->label(trans('clubhouse.next_day'))->default(false)]),
                Textarea::make('note')->label(trans('clubhouse.internal_note'))->maxLength(500), Hidden::make('revision')->default(0)])
            ->fillForm(function (array $arguments) {
                [, $tenant] = $this->context();
                $service = app(ClubhouseHours::class);
                if (! ($arguments['id'] ?? null)) {
                    return ['date' => now()->toDateString(), 'closed' => true, 'intervals' => [], 'revision' => 0];
                }
                $row = $service->exceptions($tenant)->whereNull('rule_id')->where('id', (int) $arguments['id'])->firstOrFail();
                $data = (array) $row;
                $data['intervals'] = $service->decode($row->intervals);

                return $data;
            })->action(fn (array $data) => FormMutation::run($this, function () use ($data) {
                $data['intervals'] ??= [];
                app(ClubhouseHours::class)->saveException(...[...$this->context(), $data]);
                $this->saved();
            }));
    }

    public function removeExceptionAction(): Action
    {
        return Action::make('removeException')->label(trans('clubhouse.revert_exception'))->icon('heroicon-o-arrow-uturn-left')->requiresConfirmation()
            ->visible(fn () => $this->can('hours.write'))->schema([Hidden::make('revision')])
            ->fillForm(function (array $arguments) {
                [, $tenant] = $this->context();

                return ['revision' => app(ClubhouseHours::class)->exceptions($tenant)->where('id', (int) $arguments['id'])->firstOrFail()->revision];
            })->action(fn (array $arguments, array $data) => FormMutation::run($this, function () use ($arguments, $data) {
                app(ClubhouseHours::class)->removeException(...[...$this->context(), (int) $arguments['id'], (int) $data['revision']]);
                $this->saved();
            }));
    }

    private function menuSchema(bool $edit): array
    {
        return [Hidden::make('revision')->dehydrated($edit),
            TextInput::make('title_de')->label(trans('clubhouse.title_de'))->required()->maxLength(160),
            TextInput::make('title_en')->label(trans('clubhouse.title_en'))->maxLength(160),
            Textarea::make('body_de')->label(trans('clubhouse.body_de'))->rows(8)->maxLength(20000),
            Textarea::make('body_en')->label(trans('clubhouse.body_en'))->rows(8)->maxLength(20000),
            DatePicker::make('valid_from')->label(trans('clubhouse.valid_from'))->required(),
            DatePicker::make('valid_until')->label(trans('clubhouse.valid_until'))->required(),
            CheckboxList::make('channels')->label(trans('clubhouse.channels'))->options(['web' => trans('clubhouse.website'), 'app' => trans('clubhouse.app')])->default(['web', 'app'])];
    }

    public function newMenuAction(): Action
    {
        return Action::make('newMenu')->label(trans('clubhouse.add_menu'))->icon('heroicon-o-plus')->schema($this->menuSchema(false))
            ->visible(fn () => $this->can('menus.write'))->fillForm(fn () => ['valid_from' => now()->toDateString(),
                'valid_until' => now()->addWeek()->toDateString(), 'channels' => ['web', 'app']])
            ->action(fn (array $data) => FormMutation::run($this, function () use ($data) {
                app(ClubhouseMenus::class)->save(...[...$this->context(), null, $data]);
                $this->saved();
            }));
    }

    public function editMenuAction(): Action
    {
        return Action::make('editMenu')->label(trans('clubhouse.edit'))->iconButton()->tooltip(trans('clubhouse.edit'))->icon('heroicon-o-pencil-square')->schema($this->menuSchema(true))
            ->visible(fn () => $this->can('menus.write'))
            ->fillForm(fn (array $arguments) => app(ClubhouseMenus::class)->get(...[...$this->context(), (int) $arguments['id']]))
            ->action(fn (array $arguments, array $data) => FormMutation::run($this, function () use ($arguments, $data) {
                app(ClubhouseMenus::class)->save(...[...$this->context(), (int) $arguments['id'], $data]);
                $this->saved();
            }));
    }

    public function uploadMenuAction(): Action
    {
        return Action::make('uploadMenu')->label(trans('clubhouse.upload_pdf'))->iconButton()->tooltip(trans('clubhouse.upload_pdf'))->icon('heroicon-o-arrow-up-tray')
            ->visible(fn () => $this->can('menus.write'))
            ->schema([Hidden::make('revision'), FileUpload::make('file')->label(trans('clubhouse.pdf'))->disk('local')
                ->acceptedFileTypes(['application/pdf'])->maxSize(10240)->storeFiles(false)->required()])
            ->fillForm(fn (array $arguments) => ['revision' => app(ClubhouseMenus::class)->get(...[...$this->context(), (int) $arguments['id']])['revision']])
            ->action(fn (array $arguments, array $data) => FormMutation::run($this, function () use ($arguments, $data) {
                app(ClubhouseMenus::class)->upload(...[...$this->context(), (int) $arguments['id'], (int) $data['revision'], $data['file']]);
                $this->saved();
            }));
    }

    public function removePdfAction(): Action
    {
        return Action::make('removePdf')->label(trans('clubhouse.remove_pdf'))->iconButton()->tooltip(trans('clubhouse.remove_pdf'))->icon('heroicon-o-trash')->requiresConfirmation()
            ->visible(fn () => $this->can('menus.write'))->schema([Hidden::make('revision')])
            ->fillForm(fn (array $arguments) => ['revision' => app(ClubhouseMenus::class)->get(...[...$this->context(), (int) $arguments['id']])['revision']])
            ->action(fn (array $arguments, array $data) => FormMutation::run($this, function () use ($arguments, $data) {
                app(ClubhouseMenus::class)->removeDocument(...[...$this->context(), (int) $arguments['id'], (int) $data['revision']]);
                $this->saved();
            }));
    }

    public function publishAction(): Action
    {
        return $this->menuTransition('publish');
    }

    public function withdrawAction(): Action
    {
        return $this->menuTransition('withdraw');
    }

    public function archiveAction(): Action
    {
        return $this->menuTransition('archive');
    }

    public function restoreAction(): Action
    {
        return $this->menuTransition('restore');
    }

    private function menuTransition(string $operation): Action
    {
        $ability = in_array($operation, ['publish', 'withdraw'], true) ? 'menus.publish' : 'menus.write';

        return Action::make($operation)->label(trans('clubhouse.'.$operation))->requiresConfirmation()->schema([Hidden::make('revision')])
            ->visible(fn () => $this->can($ability))
            ->fillForm(fn (array $arguments) => ['revision' => app(ClubhouseMenus::class)->get(...[...$this->context(), (int) $arguments['id']])['revision']])
            ->action(fn (array $arguments, array $data) => FormMutation::run($this, function () use ($arguments, $data, $operation) {
                app(ClubhouseMenus::class)->transition(...[...$this->context(), (int) $arguments['id'], (int) $data['revision'], $operation]);
                $this->saved();
            }));
    }

    public function settingsAction(): Action
    {
        return Action::make('settings')->label(trans('clubhouse.settings'))->icon('heroicon-o-cog-6-tooth')
            ->visible(fn () => app(ClubhouseAccess::class)->administrator(...$this->context()))
            ->schema([Toggle::make('public_enabled')->label(trans('clubhouse.public_enabled'))->required(),
                Select::make('timezone')->label(trans('clubhouse.timezone'))->searchable()->required()
                    ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list())), Hidden::make('revision')])
            ->fillForm(fn () => app(ClubhouseService::class)->management(...$this->context())['settings'])
            ->action(fn (array $data) => FormMutation::run($this, function () use ($data) {
                app(ClubhouseService::class)->settings(...[...$this->context(), $data]);
                $this->saved();
            }));
    }

    public function grantAction(): Action
    {
        return Action::make('grant')->label(trans('clubhouse.assign_role'))->icon('heroicon-o-user-plus')
            ->visible(fn () => app(ClubhouseAccess::class)->administrator(...$this->context()))
            ->schema([Select::make('account_id')->label(trans('clubhouse.user'))->required()->searchable()->live()
                ->options(function () {
                    [, $tenant] = $this->context();

                    return DB::connection('clubunio')->table('clubora.tenant_accounts as m')->join('clubora.accounts as a', 'a.id', '=', 'm.account_id')
                        ->where('m.tenant_id', $tenant->id)->where('a.active', true)->orderBy('a.display_name')->pluck('a.display_name', 'a.id')->all();
                })->afterStateUpdated(function ($state, Set $set) {
                    [, $tenant] = $this->context();
                    $grant = DB::connection('clubunio')->table('clubora.clubhouse_grants')->where('tenant_id', $tenant->id)->where('account_id', (int) $state)->first();
                    foreach (['hours_write', 'menus_write', 'menus_publish', 'active'] as $key) {
                        $set($key, (bool) ($grant?->{$key} ?? ($key === 'active')));
                    }
                    $set('revision', (int) ($grant->revision ?? 0));
                }), Toggle::make('hours_write')->label(trans('clubhouse.right_hours'))->default(false)->required(),
                Toggle::make('menus_write')->label(trans('clubhouse.right_menus'))->default(false)->required(),
                Toggle::make('menus_publish')->label(trans('clubhouse.right_publish'))->default(false)->required(),
                Toggle::make('active')->label(trans('clubhouse.active'))->default(true)->required(), Hidden::make('revision')->default(0),
                Textarea::make('reason')->label(trans('clubhouse.reason'))->minLength(10)->maxLength(500)->required()])
            ->action(fn (array $data) => FormMutation::run($this, function () use ($data) {
                $id = (int) $data['account_id'];
                unset($data['account_id']);
                app(ClubhouseAccess::class)->grant(...[...$this->context(), $id, $data]);
                $this->saved();
            }));
    }

    public function render()
    {
        [$actor, $tenant] = $this->context();
        $data = app(ClubhouseService::class)->management($actor, $tenant, ['status' => $this->menuStatus, 'search' => $this->menuSearch, 'page' => $this->menuPage]);
        $this->renderCapabilities = $data['capabilities'];
        abort_unless(in_array($this->tab, ['overview', 'hours', 'menus', 'access'], true), 422);
        abort_if($this->tab === 'access' && ! $data['capabilities']['admin'], 403);
        abort_unless(in_array($this->previewChannel, ['web', 'app'], true), 422);
        $overview = app(ClubhouseService::class)->overview($tenant, $this->previewChannel);
        $menus = collect($data['menus']);
        $grants = $data['capabilities']['admin'] ? DB::connection('clubunio')->table('clubora.clubhouse_grants as g')
            ->join('clubora.accounts as a', 'a.id', '=', 'g.account_id')->where('g.tenant_id', $tenant->id)
            ->orderBy('a.display_name')->get(['g.*', 'a.display_name']) : collect();

        return view('clubunio.clubhouse.workspace', compact('tenant', 'data', 'overview', 'menus', 'grants'));
    }
}
