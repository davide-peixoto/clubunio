<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\BillingRecipient;
use App\ClubUnio\Models\Country;
use App\ClubUnio\Models\DocumentType;
use App\ClubUnio\Models\Gender;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\MemberType;
use App\ClubUnio\Services\MemberService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class RegistrationFields
{
    public static function applicant(): array
    {
        $fields = [Hidden::make('revision')];
        foreach (['first_name' => 'Vorname', 'last_name' => 'Nachname', 'street' => 'Strasse', 'house_number' => 'Hausnummer',
            'postal_code' => 'PLZ', 'city' => 'Ort', 'email' => 'E-Mail', 'mobile' => 'Mobile', 'phone_private' => 'Telefon',
            'mother_name' => 'Mutter', 'mother_email' => 'E-Mail Mutter', 'mother_mobile' => 'Mobile Mutter',
            'father_name' => 'Vater', 'father_email' => 'E-Mail Vater', 'father_mobile' => 'Mobile Vater',
            'birth_place' => 'Geburtsort', 'previous_club' => 'Vorverein'] as $name => $label) {
            $field = TextInput::make($name)->label($label)->maxLength(255);
            if (str_contains($name, 'email')) {
                $field->email();
            }
            if (in_array($name, ['first_name', 'last_name', 'street', 'postal_code', 'city', 'email'], true)) {
                $field->required();
            }
            $fields[] = $field;
        }
        $fields[] = DatePicker::make('birth_date')->label('Geburtsdatum')->required()->maxDate(now());
        foreach (['country_id' => ['Land', Country::class], 'nationality_id' => ['Nationalität', Country::class],
            'gender_id' => ['Geschlecht', Gender::class], 'billing_recipient_id' => ['Rechnung an', BillingRecipient::class]] as $field => [$label, $model]) {
            $fields[] = Select::make($field)->label($label)->searchable()->required()->options(fn () => $model::where('tenant_id', Filament::getTenant()->id)->where('active', true)->orderBy('name')->pluck('name', 'id'));
        }
        $fields[] = Textarea::make('notes')->label('Bemerkung')->maxLength(10000)->columnSpanFull();

        return $fields;
    }

    public static function acceptance(): array
    {
        return [
            MemberResource::memberSelect('member_id')->label('Bestehendes Mitglied')->placeholder('Neues Mitglied')->live()
                ->afterStateUpdated(function ($state, Set $set) {
                    $member = Member::where('tenant_id', Filament::getTenant()->id)->whereNull('archived_at')->find($state);
                    $set('member_version', $member ? MemberService::version($member) : null);
                    if ($member) {
                        $set('member_type_id', $member->type_id);
                    }
                }),
            Hidden::make('member_version'),
            TextEntry::make('member_comparison')->label('Ausgewähltes Mitglied')->state(function (Get $get) {
                $m = Member::where('tenant_id', Filament::getTenant()->id)->whereNull('archived_at')->find($get('member_id'));

                return $m ? implode(' · ', array_filter([$m->display_name, $m->birth_date?->format('d.m.Y'), trim($m->street.' '.$m->house_number), trim($m->postal_code.' '.$m->city), $m->status?->name])) : 'Neues Mitglied';
            }),
            Select::make('member_type_id')->label('Mitgliederart')->searchable()->options(fn () => MemberType::where('tenant_id', Filament::getTenant()->id)->where('active', true)->pluck('name', 'id')),
            Select::make('document_type_id')->label('Dokumententyp Ausweise')->searchable()->options(fn () => DocumentType::where('tenant_id', Filament::getTenant()->id)->where('active', true)->pluck('name', 'id')),
        ];
    }
}
