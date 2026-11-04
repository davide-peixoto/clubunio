<?php
namespace App\ClubUnio\Resources;
use App\ClubUnio\Models\MobileAppAnnouncement;
use App\ClubUnio\Mobile\MobileAppAnnouncements;
use App\ClubUnio\Services\BexioConnectionService;
use Filament\Facades\Filament;
use Filament\Forms\Components\{TextInput,Textarea,Select,DateTimePicker,Toggle,Checkbox};
use Filament\Actions\{CreateAction,EditAction};
use Filament\Schemas\Schema;
use Filament\Tables\Columns\{TextColumn,IconColumn};
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MobileAppAnnouncementResource extends CatalogResource {
    protected static ?string $model=MobileAppAnnouncement::class;
    protected static ?string $modelLabel='Appbanner';
    protected static ?string $pluralModelLabel='Appbanner';
    protected static ?string $recordTitleAttribute='title';
    protected static ?string $tenantRelationshipName='mobileAppAnnouncements';
    public static function getNavigationGroup(): ?string { return 'Kommunikation'; }
    public static function canViewAny(): bool { return app(\App\ClubUnio\Mobile\MobileBootstrapState::class)->enabled()&&Filament::getTenant()&&BexioConnectionService::canManage(Filament::auth()->user()->fresh(),Filament::getTenant()); }
    public static function canCreate(): bool { return static::canViewAny()&&app(MobileAppAnnouncements::class)->allowed(Filament::auth()->user(),Filament::getTenant()); }
    public static function canEdit(Model $record): bool { return static::canCreate()&&(int)$record->tenant_id===(int)Filament::getTenant()->id; }
    public static function form(Schema $schema): Schema {
        return $schema->components([TextInput::make('revision')->label('Angezeigte Version')->default(0)->readOnly()->required(),
            TextInput::make('title')->label('Titel')->required()->maxLength(255),Textarea::make('body')->label('Bannertext (Klartext)')->required()->maxLength(10000),
            Select::make('audience')->label('Sichtbarkeit')->options(['members'=>'Nur angemeldete berechtigte Vereinsmitglieder','public'=>'Öffentlich auf der verifizierten Vereinsdomain'])->default('members')->required(),
            DateTimePicker::make('starts_at')->label('Sichtbar ab')->timezone('Europe/Zurich')->required(),DateTimePicker::make('ends_at')->label('Sichtbar bis')->timezone('Europe/Zurich')->required(),
            Toggle::make('active')->label('Veröffentlichen')->default(false)->required(),
            Checkbox::make('public_acknowledged')->label('Ich bestätige die anonyme Veröffentlichung, falls Öffentlichkeit gewählt ist.')->default(false)]);
    }
    public static function table(Table $table): Table {
        $save=fn ($record,$data,$livewire)=>FormMutation::run($livewire,fn ()=>app(MobileAppAnnouncements::class)->save(Filament::auth()->user(),Filament::getTenant(),$record?->id,$data));
        return $table->columns([TextColumn::make('title')->label('Titel')->wrap(),TextColumn::make('audience')->label('Sichtbarkeit')->formatStateUsing(fn ($state)=>$state==='public'?'Öffentlich':'Nur Mitglieder'),
            TextColumn::make('starts_at')->label('Ab')->dateTime('d.m.Y H:i','Europe/Zurich'),TextColumn::make('ends_at')->label('Bis')->dateTime('d.m.Y H:i','Europe/Zurich'),
            IconColumn::make('active')->label('Veröffentlicht')->boolean(),TextColumn::make('revision')->label('Version')])
            ->headerActions([CreateAction::make()->using(fn ($data,$livewire)=>$save(null,$data,$livewire))])
            ->recordActions([EditAction::make()->using(fn ($record,$data,$livewire)=>$save($record,$data,$livewire))]);
    }
    public static function getPages(): array { return ['index'=>MobileAppAnnouncementResource\Pages\ManageRecords::route('/')]; }
}
