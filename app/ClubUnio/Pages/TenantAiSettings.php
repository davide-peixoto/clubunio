<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\ImpersonationService;
use App\ClubUnio\Services\PlatformAdministrationService;
use App\ClubUnio\Services\TenantAiConfiguration;
use App\ClubUnio\Services\TenantAiKnowledge;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class TenantAiSettings extends Page
{
    protected string $view = 'clubunio.tenant-ai-settings';

    protected static ?string $slug = 'ai-settings';

    protected static bool $shouldRegisterNavigation = true;

    #[Locked]
    public int $contextTenantId;

    #[Locked]
    public int $contextAccountId;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->contextTenantId = (int) Filament::getTenant()->getKey();
        $this->contextAccountId = (int) Filament::auth()->id();
    }

    public static function getNavigationLabel(): string
    {
        return trans('tenant_ai.title');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-sparkles';
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return config('clubunio-tenant-ai.enabled', false) && $tenant && ! Filament::auth()->user()?->api_only
            && ! request()->attributes->has('clubunio_impersonation')
            && ! (request()->hasSession() && request()->session()->has(ImpersonationService::KEY))
            && BexioConnectionService::canManage(Filament::auth()->user(), $tenant);
    }

    public function getTitle(): string
    {
        return trans('tenant_ai.title');
    }

    public function configureAction(): Action
    {
        return Action::make('configure')->label(trans('tenant_ai.actions.configure'))
            ->icon('heroicon-o-cog-6-tooth')->requiresConfirmation()
            ->modalDescription(trans('tenant_ai.configuration_confirmation'))
            ->fillForm(function (): array {
                $this->assertContext();
                $summary = app(TenantAiConfiguration::class)->summary(Filament::getTenant());

                // Only non-secret summary fields may enter Livewire state.
                return Arr::only($summary, ['mode', 'project_id', 'enabled', 'privacy_reviewed', 'revision'])
                    + ['api_key' => '', 'monthly_limit_usd' => number_format($summary['monthly_limit_microusd'] / 1000000, 6, '.', '')];
            })
            ->schema([
                Select::make('mode')->label(trans('tenant_ai.fields.mode'))->required()->live()
                    ->options(['own' => trans('tenant_ai.modes.own'), 'clubunio' => trans('tenant_ai.modes.clubunio')])
                    ->disabled(fn (): bool => $this->managedConfigurationLocked())->dehydrated()
                    ->disableOptionWhen(fn (string $value): bool => $value === 'clubunio' && ! $this->platformAdministrator() && ! $this->managedConfigurationLocked()),
                TextInput::make('project_id')->label(trans('tenant_ai.fields.project_id'))->maxLength(255)
                    ->disabled(fn (Get $get): bool => $this->managedConfigurationLocked() || ($get('mode') === 'clubunio' && ! $this->platformAdministrator()))
                    ->dehydrated(),
                TextInput::make('api_key')->label(trans('tenant_ai.fields.api_key'))->password()->autocomplete('new-password')
                    ->maxLength(1024)
                    ->visible(fn (Get $get): bool => ! $this->managedConfigurationLocked() && ($get('mode') === 'own' || $this->platformAdministrator())),
                Toggle::make('enabled')->label(trans('tenant_ai.fields.enabled')),
                Toggle::make('privacy_reviewed')->label(trans('tenant_ai.fields.privacy_reviewed')),
                TextInput::make('monthly_limit_usd')->label(trans('tenant_ai.fields.monthly_limit_usd'))
                    ->numeric()->step(0.000001)->minValue(0.000001)->required()->regex('/^\d{1,4}(?:\.\d{1,6})?$/D')
                    ->maxValue(fn (): float => $this->managedConfigurationLocked()
                        ? app(TenantAiConfiguration::class)->summary(Filament::getTenant())['monthly_limit_microusd'] / 1000000 : 1000),
                Hidden::make('revision')->required(),
            ])
            ->action(function (array $data, Action $action): void {
                $this->run(function () use ($data): void {
                    $service = app(TenantAiConfiguration::class);
                    $summary = $service->summary(Filament::getTenant());
                    [$whole, $fraction] = array_pad(explode('.', (string) $data['monthly_limit_usd'], 2), 2, '');
                    $data['monthly_limit_microusd'] = (int) $whole * 1000000 + (int) str_pad($fraction, 6, '0');
                    unset($data['monthly_limit_usd']);
                    $data += ['api_key' => ''];
                    $platform = $this->platformAdministrator();
                    abort_if(! $platform && $data['mode'] === 'clubunio' && $summary['mode'] !== 'clubunio', 403);
                    if (! $platform && $summary['mode'] === 'clubunio') {
                        abort_if($data['mode'] !== $summary['mode'] || $data['project_id'] !== $summary['project_id']
                            || trim($data['api_key'] ?? '') !== '' || $data['monthly_limit_microusd'] > $summary['monthly_limit_microusd'], 403);
                    }
                    if (trim($data['api_key'] ?? '') !== '' || ($data['project_id'] ?? '') !== $summary['project_id'] || $data['mode'] !== $summary['mode']) {
                        $data['enabled'] = false;
                    }
                    $service->save(Filament::auth()->user(), Filament::getTenant(), $data);
                }, $action);
            });
    }

    public function verifyAction(): Action
    {
        return Action::make('verify')->label(trans('tenant_ai.actions.verify'))->icon('heroicon-o-check-badge')
            ->requiresConfirmation()->modalDescription(trans('tenant_ai.verify_confirmation'))
            ->action(function (Action $action): void {
                $this->run(fn () => app(TenantAiConfiguration::class)->verify(
                    Filament::auth()->user(), Filament::getTenant(),
                ), $action);
            });
    }

    public function createStoreAction(): Action
    {
        return Action::make('createStore')->label(trans('tenant_ai.actions.create_store'))->icon('heroicon-o-plus')
            ->schema([
                TextInput::make('name')->label(trans('tenant_ai.fields.name'))->required()->maxLength(160),
                Select::make('purpose')->label(trans('tenant_ai.fields.purpose'))->required()->default('training')
                    ->options(['training' => trans('tenant_ai.purposes.training'), 'club' => trans('tenant_ai.purposes.club')]),
            ])
            ->action(function (array $data, Action $action): void {
                $this->run(fn () => app(TenantAiKnowledge::class)->createStore(
                    Filament::auth()->user(), Filament::getTenant(), $data['name'], $data['purpose'],
                ), $action);
            });
    }

    public function removeStoreAction(): Action
    {
        return Action::make('removeStore')->label(trans('tenant_ai.actions.remove_store'))->icon('heroicon-o-trash')
            ->color('danger')->iconButton()->tooltip(trans('tenant_ai.actions.remove_store'))
            ->requiresConfirmation()->modalDescription(trans('tenant_ai.remove_store_confirmation'))
            ->action(function (array $arguments, Action $action): void {
                $this->run(fn () => app(TenantAiKnowledge::class)->removeStore(
                    Filament::auth()->user(), Filament::getTenant(), (int) ($arguments['id'] ?? 0),
                ), $action);
            });
    }

    public function attachStoreAction(): Action
    {
        return Action::make('attachStore')->label(trans('tenant_ai.actions.attach_store'))->icon('heroicon-o-link')
            ->requiresConfirmation()->modalDescription(trans('tenant_ai.attach_store_confirmation'))
            ->schema([
                TextInput::make('name')->label(trans('tenant_ai.fields.name'))->required()->maxLength(160),
                Select::make('purpose')->label(trans('tenant_ai.fields.purpose'))->required()->default('training')
                    ->options(['training' => trans('tenant_ai.purposes.training'), 'club' => trans('tenant_ai.purposes.club')]),
                TextInput::make('remote_id')->label(trans('tenant_ai.fields.remote_id'))->required()->maxLength(93)
                    ->regex('/^vs_[A-Za-z0-9_-]{1,90}$/D'),
            ])
            ->action(function (array $data, Action $action): void {
                $this->run(fn () => app(TenantAiKnowledge::class)->attachStore(
                    Filament::auth()->user(), Filament::getTenant(), $data['name'], $data['purpose'], $data['remote_id'],
                ), $action);
            });
    }

    public function uploadAction(): Action
    {
        return Action::make('upload')->label(trans('tenant_ai.actions.upload'))->icon('heroicon-o-arrow-up-tray')
            ->modalDescription(trans('tenant_ai.replacement'))
            ->disabled(fn (): bool => ! $this->privateTemporaryUploads())
            ->schema([
                Select::make('store_id')->label(trans('tenant_ai.fields.store'))->required()->options(function (): array {
                    $this->assertContext();

                    return collect(app(TenantAiKnowledge::class)->stores(Filament::auth()->user(), Filament::getTenant()))
                        ->where('state', 'ready')->pluck('name', 'id')->all();
                }),
                FileUpload::make('file')->label(trans('tenant_ai.fields.file'))->required()
                    ->disk('local')->visibility('private')->storeFiles(false)->previewable(false)
                    ->maxSize(12288),
            ])
            ->action(function (array $data, Action $action): void {
                $this->run(function () use ($data): void {
                    abort_unless($this->privateTemporaryUploads() && ($data['file'] ?? null) instanceof UploadedFile, 422);
                    app(TenantAiKnowledge::class)->upload(
                        Filament::auth()->user(), Filament::getTenant(), (int) $data['store_id'], $data['file'],
                    );
                }, $action);
            });
    }

    public function refreshDocumentAction(): Action
    {
        return Action::make('refreshDocument')->label(trans('tenant_ai.actions.refresh'))->icon('heroicon-o-arrow-path')
            ->iconButton()->tooltip(trans('tenant_ai.actions.refresh'))
            ->requiresConfirmation()->modalDescription(trans('tenant_ai.refresh_confirmation'))
            ->action(function (array $arguments, Action $action): void {
                $this->run(fn () => app(TenantAiKnowledge::class)->refresh(
                    Filament::auth()->user(), Filament::getTenant(), (int) ($arguments['id'] ?? 0),
                ), $action);
            });
    }

    public function removeDocumentAction(): Action
    {
        return Action::make('removeDocument')->label(trans('tenant_ai.actions.remove'))->icon('heroicon-o-trash')
            ->iconButton()->tooltip(trans('tenant_ai.actions.remove'))
            ->color('danger')->requiresConfirmation()->modalDescription(trans('tenant_ai.remove_confirmation'))
            ->action(function (array $arguments, Action $action): void {
                $this->run(fn () => app(TenantAiKnowledge::class)->remove(
                    Filament::auth()->user(), Filament::getTenant(), (int) ($arguments['id'] ?? 0),
                ), $action);
            });
    }

    protected function getViewData(): array
    {
        $this->assertContext();
        $actor = Filament::auth()->user();
        $tenant = Filament::getTenant();
        $configuration = app(TenantAiConfiguration::class);
        $knowledge = app(TenantAiKnowledge::class);
        $configuration->authorize($actor, $tenant);

        return [
            'tenant' => $tenant,
            'configuration' => Arr::only($configuration->summary($tenant), [
                'mode', 'project_id', 'key_present', 'enabled', 'privacy_reviewed', 'monthly_limit_microusd', 'revision', 'verified',
            ]),
            'stores' => array_map(fn ($row): array => (array) $row, $knowledge->stores($actor, $tenant)),
            'documents' => array_map(fn ($row): array => (array) $row, $knowledge->documents($actor, $tenant)),
            'usage' => array_map(fn ($row): array => (array) $row, $configuration->usage($actor, $tenant)),
            'privateUploads' => $this->privateTemporaryUploads(),
        ];
    }

    public function stateLabel(?string $state): string
    {
        $key = 'tenant_ai.states.'.($state ?? 'unknown');

        return trans()->has($key) ? trans($key) : trans('tenant_ai.states.unknown');
    }

    private function platformAdministrator(): bool
    {
        return app(PlatformAdministrationService::class)->allowed(Filament::auth()->user());
    }

    private function managedConfigurationLocked(): bool
    {
        $this->assertContext();

        return ! $this->platformAdministrator()
            && app(TenantAiConfiguration::class)->summary(Filament::getTenant())['mode'] === 'clubunio';
    }

    private function assertContext(): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(isset($this->contextTenantId, $this->contextAccountId)
            && $this->contextTenantId === (int) Filament::getTenant()->getKey()
            && $this->contextAccountId === (int) Filament::auth()->id(), 409);
    }

    private function privateTemporaryUploads(): bool
    {
        // FileUpload::disk does not control Livewire's temporary upload destination.
        $disk = config('livewire.temporary_file_upload.disk') ?: config('filesystems.default');

        return $disk !== 'public' && config("filesystems.disks.$disk")
            && config("filesystems.disks.$disk.visibility", 'private') === 'private';
    }

    private function run(callable $operation, Action $action): void
    {
        $this->assertContext();
        try {
            $operation();
        } catch (\Throwable $error) {
            if ($error instanceof HttpExceptionInterface && $error->getStatusCode() === 403) {
                abort(403);
            }
            // Provider errors and validation payloads can contain credentials.
            Notification::make()->title(trans('tenant_ai.failed'))->danger()->send();
            $action->halt();
        }
        Notification::make()->title(trans('tenant_ai.completed'))->success()->send();
    }
}
