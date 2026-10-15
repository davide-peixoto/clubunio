<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Resources\TrainingSlotResource;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\SignageBrandService;
use App\ClubUnio\Services\SignageFeedService;
use App\ClubUnio\Services\SignageService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;

class SignagePreview extends Page
{
    protected function getHeaderActions(): array
    {
        return [Action::make('publicDisplay')->label('Öffentliche Signage')->icon('heroicon-o-arrow-top-right-on-square')
            ->url(fn () => route('clubunio.public.signage', ['tenant' => Filament::getTenant()->slug, 'period_id' => $this->periodId]))->openUrlInNewTab(),
            Action::make('design')->label('Vereinsdesign')->icon('heroicon-o-swatch')
                ->visible(fn () => BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant()))
                ->fillForm(function () {
                    $data = app(SignageBrandService::class)->read(Filament::getTenant());
                    $data['period_id'] = $data['period_id'] ?: null;

                    return $data + ['version' => app(SignageBrandService::class)->version(Filament::getTenant())];
                })
                ->schema([ColorPicker::make('color')->label('Vereinsfarbe')->required(), TextInput::make('title')->label('Vereinsname im Display')->required()->maxLength(100),
                    TextInput::make('logo')->label('Logo-Bildadresse')->required()->maxLength(2048),
                    Select::make('period_id')->label('Standard-Trainingsperiode')->options(fn () => TrainingSlotResource::choices('period'))->placeholder('Keine Standardperiode'),
                    Hidden::make('version')->required()])
                ->action(function (array $data) {
                    FormMutation::run($this, fn () => app(SignageBrandService::class)->save(Filament::auth()->user(), Filament::getTenant(), $data));
                    $this->periodId = $data['period_id'] ? (string) $data['period_id'] : null;
                    Notification::make()->title('Vereinsdesign gespeichert')->success()->send();
                }),
            Action::make('checkInstagram')->label('Instagram prüfen')->icon('heroicon-o-check-circle')
                ->visible(fn () => BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant()))
                ->action(function () {
                    $result = app(SignageFeedService::class)->verifyInstagram(Filament::auth()->user(), Filament::getTenant());
                    $notice = Notification::make()->title($result['state'] === 'ready' ? 'Instagram-Verbindung geprüft' : 'Instagram-Verbindung nicht verfügbar')
                        ->body($result['state'] === 'ready' ? $result['item_count'].' Beiträge verfügbar.' : 'Zugang prüfen. Referenz: '.$result['reference']);
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
        return Filament::getTenant() && (Filament::auth()->user()?->fresh()?->canAccessTenant(Filament::getTenant()) ?? false);
    }

    public function mount(): void
    {
        $this->date = now('Europe/Zurich')->toDateString();
        $period = app(SignageBrandService::class)->read(Filament::getTenant())['period_id'];
        $this->periodId = isset(TrainingSlotResource::choices('period')[$period]) ? (string) $period : null;
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        try {
            $data = app(SignageService::class)->preview(Filament::auth()->user(), Filament::getTenant(), ['date' => $this->date, 'period_id' => $this->periodId]);
        } catch (ValidationException $e) {
            $data = ['competitions' => collect(), 'clubs' => collect(), 'notices' => collect(), 'fields' => collect(), 'trainings' => collect(), 'selectionError' => 'Bitte ein gültiges Datum und eine verfügbare Trainingsperiode auswählen.'];
        }

        return $data + ['periods' => TrainingSlotResource::choices('period'), 'tenant' => Filament::getTenant(), 'brand' => app(SignageBrandService::class)->read(Filament::getTenant())];
    }
}
