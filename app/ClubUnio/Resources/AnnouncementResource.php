<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\AccountRole;
use App\ClubUnio\Models\Announcement;
use App\ClubUnio\Services\AnnouncementChoices;
use App\ClubUnio\Services\AnnouncementService;
use App\ClubUnio\Services\StatusAppearance;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnnouncementResource extends CatalogResource
{
    protected static ?string $model = Announcement::class;

    protected static ?string $modelLabel = 'Ankündigung';

    protected static ?string $pluralModelLabel = 'Ankündigungen und Rundnachrichten';

    protected static ?string $tenantRelationshipName = 'announcements';

    public static function getNavigationGroup(): ?string
    {
        return 'Verein';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-megaphone';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->select('clubora.announcements.*')->selectSub(
            DB::connection('clubunio')->table('clubora.notifications')->selectRaw('count(*)')->whereColumn('announcement_id', 'clubora.announcements.id')
                ->whereColumn('tenant_id', 'clubora.announcements.tenant_id')->whereNotNull('read_at'), 'read_count');
    }

    public static function fields(): array
    {
        return [Hidden::make('revision')->default(0), TextInput::make('title')->label('Titel')->required()->maxLength(200),
            Textarea::make('body')->label('Nachricht')->required()->rows(8)->maxLength(10000),
            Select::make('target_type')->label('Empfängergruppe')->options(AnnouncementService::TARGETS)->default('all')->required()->live()
                ->afterStateUpdated(fn (Set $set) => $set('target_ids', [])),
            Select::make('target_ids')->label('Auswahl')->multiple()->searchable()->default([])->required(fn (Get $get) => $get('target_type') !== 'all')
                ->key(fn (Get $get) => 'announcement-target-'.($get('target_type') ?? 'all'))
                ->visible(fn (Get $get) => $get('target_type') !== 'all')->dehydrated(true)
                ->options(fn (Get $get) => match ($get('target_type')) {
                    'roles' => AccountRole::where('active', true)->orderBy('name')->pluck('name', 'code')->all(),
                    default => [],
                })
                ->getSearchResultsUsing(fn (Get $get, string $search) => app(AnnouncementChoices::class)->search(Filament::getTenant(), $get('target_type') ?? '', $search))
                ->getOptionLabelsUsing(fn (Get $get, array $values) => app(AnnouncementChoices::class)->labels(Filament::getTenant(), $get('target_type') ?? '', $values)),
        ];
    }

    public static function table(Table $table): Table
    {
        $save = fn ($id, $data) => app(AnnouncementService::class)->save(Filament::auth()->user(), Filament::getTenant(), $id, $data + ['target_ids' => []]);
        $preview = fn (Announcement $record) => view('clubunio.messages.announcement-preview', app(AnnouncementService::class)->preview(Filament::auth()->user(), Filament::getTenant(), $record->id));
        $states = AnnouncementService::states(Filament::getTenant());

        return $table->columns([
            TextColumn::make('title')->label('Titel')->searchable()->wrap(),
            TextColumn::make('state')->label('Status')->formatStateUsing(fn ($state) => $states[$state] ?? $state)->badge()
                ->color(fn ($state) => StatusAppearance::palette($states[$state] ?? $state)),
            TextColumn::make('target_type')->label('Empfängergruppe')->formatStateUsing(fn ($state) => AnnouncementService::TARGETS[$state] ?? $state)->wrap(),
            TextColumn::make('recipient_count')->label('Empfänger')->numeric(),
            TextColumn::make('read_count')->label('Als gelesen markiert')->numeric(),
            TextColumn::make('published_at')->label('Veröffentlicht')->dateTime('d.m.Y H:i', 'Europe/Zurich')->sortable(),
            TextColumn::make('revision')->label('Version'),
        ])->filters([SelectFilter::make('state')->label('Status')->options($states)])
            ->headerActions([Action::make('newAnnouncement')->label('Entwurf erstellen')->icon('heroicon-o-plus')->slideOver()->modalSubmitActionLabel('Entwurf speichern')->schema(self::fields())
                ->action(fn (array $data, $livewire) => FormMutation::run($livewire, fn () => $save(null, $data)))])
            ->recordActions([
                Action::make('duplicate')->label('Als Entwurf kopieren')->icon('heroicon-o-document-duplicate')->iconButton()->tooltip('Als neuen Entwurf kopieren')->requiresConfirmation()
                    ->action(fn ($record, $livewire) => FormMutation::run($livewire, fn () => app(AnnouncementService::class)->duplicate(Filament::auth()->user(), Filament::getTenant(), $record->id))),
                Action::make('editDraft')->label('Bearbeiten')->icon('heroicon-o-pencil-square')->iconButton()->tooltip('Entwurf bearbeiten')->slideOver()->visible(fn ($record) => $record->state === 'draft')
                    ->fillForm(fn ($record) => $record->only(['revision', 'title', 'body', 'target_type', 'target_ids']))->schema(self::fields())
                    ->action(fn ($record, array $data, $livewire) => FormMutation::run($livewire, fn () => $save($record->id, $data))),
                Action::make('publish')->label('Vorschau und Veröffentlichung')->icon('heroicon-o-paper-airplane')->iconButton()->tooltip('Vorschau und interne Veröffentlichung')->slideOver()
                    ->visible(fn ($record) => $record->state === 'draft')->modalHeading('Intern veröffentlichen')->modalSubmitActionLabel('Intern veröffentlichen')
                    ->fillForm(fn ($record, $livewire) => self::publicationAttempt($livewire, fn () => app(AnnouncementService::class)->prepare(Filament::auth()->user(), Filament::getTenant(), $record->id, $record->revision)))
                    ->modalContent($preview)->schema([Hidden::make('revision'), Hidden::make('preview_token'), Checkbox::make('confirmed')->label('Text und Empfänger geprüft; intern veröffentlichen')->accepted()->required()])
                    ->action(function ($record, array $data, $livewire) {
                        $count = self::publicationAttempt($livewire, fn () => app(AnnouncementService::class)->publish(Filament::auth()->user(), Filament::getTenant(), $record->id, (int) $data['revision'], $data['preview_token']));
                        Notification::make()->title('Intern veröffentlicht: '.$count.' Empfänger')->success()->send();
                    }),
                Action::make('details')->label('Details')->icon('heroicon-o-eye')->iconButton()->tooltip('Veröffentlichte Nachricht')->slideOver()->visible(fn ($record) => $record->state !== 'draft')->modalContent($preview)->modalSubmitAction(false),
                Action::make('withdraw')->label('Zurückziehen')->icon('heroicon-o-archive-box-x-mark')->iconButton()->tooltip('Zurückziehen')->visible(fn ($record) => $record->state !== 'withdrawn')
                    ->requiresConfirmation()->modalDescription('Der Inhalt wird im Portal und in den internen Benachrichtigungen zurückgezogen. Das Protokoll bleibt erhalten.')
                    ->fillForm(fn ($record) => ['revision' => $record->revision])->schema([Hidden::make('revision')])
                    ->action(fn ($record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(AnnouncementService::class)->withdraw(Filament::auth()->user(), Filament::getTenant(), $record->id, (int) $data['revision']))),
            ])->defaultSort('id', 'desc')->deferFilters(false)->persistFiltersInSession()->persistSearchInSession();
    }

    public static function getPages(): array
    {
        return ['index' => AnnouncementResource\Pages\ListAnnouncements::route('/')];
    }

    private static function publicationAttempt($livewire, \Closure $work): mixed
    {
        try {
            return FormMutation::run($livewire, $work);
        } catch (ValidationException $e) {
            Notification::make()->title('Veröffentlichung nicht möglich')->body(collect($e->errors())->flatten()->first())->danger()->send();
            throw $e;
        }
    }
}
