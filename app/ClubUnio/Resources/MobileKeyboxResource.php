<?php
namespace App\ClubUnio\Resources;
use App\ClubUnio\Models\MobileKeybox;
use App\ClubUnio\Mobile\MobileKeyboxAdministration;
use Filament\Facades\Filament;
use Filament\Actions\{Action,CreateAction,EditAction};
use Filament\Forms\Components\{TextInput,Textarea,Select,Toggle,Hidden};
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\{TextColumn,IconColumn};
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Real personal configuration; deliberately separate from simulation BusKeyboxResource. */
class MobileKeyboxResource extends CatalogResource {
    protected static ?string $model=MobileKeybox::class;
    protected static ?string $modelLabel='Persönliche Schlüsselbox';
    protected static ?string $pluralModelLabel='Persönliche Schlüsselboxen';
    protected static ?string $recordTitleAttribute='name';
    protected static ?string $tenantRelationshipName='mobileKeyboxes';
    public static function getNavigationGroup(): ?string { return 'Anlagen und Spielbetrieb'; }
    private static function service(): MobileKeyboxAdministration { return app(MobileKeyboxAdministration::class); }
    public static function canViewAny(): bool { return Filament::getTenant()&&Filament::auth()->user()&&self::service()->allowed(Filament::auth()->user(),Filament::getTenant()); }
    public static function canCreate(): bool { return static::canViewAny(); }
    public static function canEdit(Model $record): bool { return static::canViewAny()&&(int)$record->tenant_id===(int)Filament::getTenant()->id; }
    public static function form(Schema $schema): Schema {
        return $schema->components([TextInput::make('revision')->label('Angezeigte Boxversion')->default(0)->readOnly()->required(),
            TextInput::make('name')->label('Name')->required()->maxLength(160),
            TextInput::make('provider_device_id')->label('Provider-Gerätezuordnung')->password()->revealable()->maxLength(120)->required(fn ($record)=>$record===null)
                ->helperText(__('Nur bei Neuanlage oder Änderung eingeben. Leer lassen behält die verschlüsselte Zuordnung; der Wert wird nicht an die App ausgegeben.')),
            Select::make('bus_id')->label('Bus (optional)')->options(fn ()=>DB::connection('clubunio')->table('clubunio.buses')->where('tenant_id',Filament::getTenant()->id)->where('active',true)->localizedPluck('name','id')),
            Toggle::make('active')->label('Persönliche Zuordnung aktiv')->default(false)->required(),
            Textarea::make('reason')->label('Begründung')->required()->minLength(10)->maxLength(500)->helperText(__('Jede Änderung invalidiert lokale PINs; bereits bekannte Offlinecodes brauchen physische Maßnahmen.'))]);
    }
    public static function table(Table $table): Table {
        $save=fn ($record,$data,$livewire)=>FormMutation::run($livewire,fn ()=>self::service()->saveBox(Filament::auth()->user(),Filament::getTenant(),$record?->id,$data));
        return $table->columns([TextColumn::make('id')->label('Box-ID'),TextColumn::make('name')->label('Name')->searchable(),TextColumn::make('bus.name')->label('Bus'),
            IconColumn::make('active')->label('Aktiv')->boolean(),TextColumn::make('revision')->label('Version'),
            TextColumn::make('physical_revocation')->label('Physischer Widerruf')->state(fn ($record)=>DB::connection('clubunio')->table('clubunio.mobile_keybox_commands')
                ->where('tenant_id',$record->tenant_id)->where('keybox_id',$record->id)->where('remote_revocation_state','required')->where('valid_until','>',now()->toIso8601String())->exists()?__('Erforderlich – keine weitere Ausgabe'):__('Kein offener lokaler Nachweis'))])
            ->headerActions([CreateAction::make()->using(fn ($data,$livewire)=>$save(null,$data,$livewire)),
                Action::make('provider')->label('Providerzugang')->fillForm(fn ()=>self::service()->providerSnapshot(Filament::auth()->user(),Filament::getTenant()))
                    ->schema([Hidden::make('fingerprint')->required(),Toggle::make('configured')->label('Zugangswerte hinterlegt (Abnahme separat)')->disabled()->dehydrated(false),
                        TextInput::make('client_id')->label('Client ID')->password()->revealable()->maxLength(4096),TextInput::make('client_secret')->label('Client Secret')->password()->revealable()->maxLength(4096),
                        Textarea::make('reason')->label('Begründung')->required()->minLength(10)->maxLength(500)])
                    ->action(fn ($data,$livewire)=>FormMutation::run($livewire,fn ()=>self::service()->saveProvider(Filament::auth()->user(),Filament::getTenant(),$data)))])
            ->recordActions([EditAction::make()->using(fn ($record,$data,$livewire)=>$save($record,$data,$livewire)),
                Action::make('grant')->label('Persönliche Freigabe')->fillForm(fn ($record)=>['revision'=>0,'box_revision'=>$record->revision,'active'=>false])
                    ->schema([TextInput::make('box_revision')->label('Angezeigte Boxversion')->readOnly()->required(),
                        Select::make('account_id')->label('Eigenes Vereinskonto')->options(fn ()=>self::service()->accountOptions(Filament::auth()->user(),Filament::getTenant()))->searchable()->required()->live()
                            ->afterStateUpdated(function ($state,Set $set,$record) {
                                if(!$state) { $set('revision',0);$set('active',false);return; }
                                $snapshot=self::service()->grantSnapshot(Filament::auth()->user(),Filament::getTenant(),(int)$record->id,(int)$state);
                                $set('revision',$snapshot['revision']);$set('active',$snapshot['active']);
                            }),
                        TextInput::make('revision')->label('Angezeigte Freigabeversion')->readOnly()->required(),Toggle::make('active')->label('Zugriff erlauben')->required(),
                        Textarea::make('reason')->label('Begründung')->required()->minLength(10)->maxLength(500)])
                    ->action(fn ($record,$data,$livewire)=>FormMutation::run($livewire,fn ()=>self::service()->saveGrant(Filament::auth()->user(),Filament::getTenant(),(int)$record->id,$data)))]);
    }
    public static function getPages(): array { return ['index'=>MobileKeyboxResource\Pages\ManageRecords::route('/')]; }
}
