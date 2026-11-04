<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Resources\TrainingSlotResource;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\SignageBrandService;
use App\ClubUnio\Services\SignageFeedService;
use App\ClubUnio\Services\SignageService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;

class SignagePreview extends Page
{
    use LocalizedTitle;
    protected function getHeaderActions(): array
    {
        return [Action::make('publicDisplay')->label('Öffentliche Signage')->icon('heroicon-o-arrow-top-right-on-square')
            ->url(fn () => route('clubunio.public.signage', ['tenant' => Filament::getTenant()->slug]))->openUrlInNewTab(),
            Action::make('design')->label('Vereinsdesign')->icon('heroicon-o-swatch')
                ->visible(fn () => BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant()))
                ->fillForm(function () {
                    $data = app(SignageBrandService::class)->read(Filament::getTenant());
                    unset($data['period_id']);

                    return $data + ['version' => app(SignageBrandService::class)->version(Filament::getTenant())];
                })
                ->schema([ColorPicker::make('color')->label('Vereinsfarbe')->required(), TextInput::make('title')->label('Vereinsname im Display')->required()->maxLength(100),
                    TextInput::make('logo')->label('Logo-Bildadresse')->required()->maxLength(2048),
                    Hidden::make('version')->required()])
                ->action(function (array $data) {
                    FormMutation::run($this, fn () => app(SignageBrandService::class)->save(Filament::auth()->user(), Filament::getTenant(), $data));
                    $this->brandValues = null;
                    Notification::make()->title(__('Vereinsdesign gespeichert'))->success()->send();
                }),
            Action::make('checkInstagram')->label('Instagram prüfen')->icon('heroicon-o-check-circle')
                ->visible(fn () => BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant()))
                ->action(function () {
                    $result = app(SignageFeedService::class)->verifyInstagram(Filament::auth()->user(), Filament::getTenant());
                    $notice = Notification::make()->title($result['state'] === 'ready' ? __('Instagram-Verbindung geprüft') : __('Instagram-Verbindung nicht verfügbar'))
                        ->body($result['state'] === 'ready' ? __(':count Beiträge verfügbar.', ['count' => $result['item_count']]) : __('Zugang prüfen. Referenz: :reference', ['reference' => $result['reference']]));
                    if ($result['state'] === 'ready') {
                        $notice->success();
                    } else {
                        $notice->warning();
                    }
                    $notice->send();
                })];
    }

    protected string $view = 'clubunio.signage-preview';

    protected static ?string $title = 'Signage-Vorschau';

    public string $date = '';

    public ?string $periodId = null;

    public bool $followToday = true;

    private ?array $periodChoices = null;

    private ?array $brandValues = null;

    private function periods(): array
    {
        return $this->periodChoices ??= TrainingSlotResource::choices('period');
    }

    private function brand(): array
    {
        return $this->brandValues ??= app(SignageBrandService::class)->read(Filament::getTenant());
    }

    public function updatedDate(): void
    {
        $this->followToday = false;
    }

    public function updatedFollowToday(): void
    {
        $this->refreshDisplay();
    }

    public function refreshDisplay(): void
    {
        abort_unless(static::canAccess(), 403);
        if ($this->followToday) {
            $this->date = now('Europe/Zurich')->toDateString();
        }
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Anlagen und Spielbetrieb';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-tv';
    }

    public static function canAccess(): bool
    {
        return \App\ClubUnio\Resources\ProfileUi::allows('signage');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->date = now('Europe/Zurich')->toDateString();
        $this->periodId = null;
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        try {
            $data = app(SignageService::class)->preview(Filament::auth()->user(), Filament::getTenant(), ['date' => $this->date, 'period_id' => $this->periodId]);
        } catch (ValidationException $e) {
            $data = ['competitions' => collect(), 'clubs' => collect(), 'notices' => collect(), 'fields' => collect(), 'trainings' => collect(), 'selectionError' => __('Bitte ein gültiges Datum und eine verfügbare Trainingsperiode auswählen.')];
        }

        $periods = $this->periods();
        $active = app(\App\ClubUnio\Services\ActiveTrainingPlan::class)->period(Filament::getTenant());

        return $data + ['periods' => $periods, 'tenant' => Filament::getTenant(), 'brand' => $this->brand(),
            'activePlan' => $active ? ($periods[$active->id] ?? $active->name) : __('Nicht festgelegt')];
    }
}
