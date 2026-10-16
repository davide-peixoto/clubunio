<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\FamilyMember;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\MemberBankAccount;
use App\ClubUnio\Models\MemberKey;
use App\ClubUnio\Models\Season;
use App\ClubUnio\Models\SpotsRecipient;
use App\ClubUnio\Models\TeamPlayer;
use App\ClubUnio\Models\TeamStaff;
use App\ClubUnio\Models\Tenant;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportService
{
    private const MODELS = ['spots' => SpotsRecipient::class, 'keys' => MemberKey::class, 'players' => TeamPlayer::class, 'player_contacts' => TeamPlayer::class, 'staff' => TeamStaff::class, 'families' => FamilyMember::class, 'banks' => MemberBankAccount::class, 'unassigned' => Member::class, 'passive' => Member::class, 'member_reports' => Member::class];

    public static function action(string $kind): Action
    {
        return Action::make('export')->label('CSV exportieren')->icon('heroicon-o-arrow-down-tray')
            ->action(fn ($livewire) => app(self::class)->download(Filament::auth()->user(), Filament::getTenant(), $kind, $livewire->getFilteredSortedTableQuery(), $livewire->tableFilters['season']['value'] ?? null));
    }

    public function download(Account $actor, Tenant $tenant, string $kind, Builder $filtered, mixed $season = null): StreamedResponse
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'reports', 'export');
        $model = self::MODELS[$kind] ?? null;
        abort_unless($model, 404);
        abort_unless($filtered->getModel() instanceof $model, 422);
        $query = clone $filtered;
        // Keep filters/order, but do not hydrate display-only relations from the table.
        $query->withoutEagerLoads();
        $query->where($query->getModel()->qualifyColumn('tenant_id'), $tenant->id);
        $membership = in_array($kind, ['unassigned', 'passive', 'member_reports'], true);
        $seasonName = null;
        if ($membership) {
            $query->select(array_map($query->getModel()->qualifyColumn(...),
                ['id', 'legacy_id', 'first_name', 'last_name', 'company', 'type_id', 'status_id']));
            $reports = app(MembershipReportService::class);
            $eligible = match ($kind) {
                'member_reports' => Member::where('tenant_id', $tenant->id)->whereNull('archived_at'),
                'passive' => $reports->passive($actor, $tenant),
                default => $reports->withoutTeam($reports->teamCandidates($actor, $tenant), $tenant, $season),
            };
            $query->whereIn($query->getModel()->qualifyColumn('id'), $eligible->select('clubora.members.id'));
            $relations = ['status' => fn ($q) => $q->where('tenant_id', $tenant->id), 'type' => fn ($q) => $q->where('tenant_id', $tenant->id)];
            if ($kind === 'unassigned' && is_scalar($season) && ctype_digit((string) $season)) {
                $seasonName = Season::where('tenant_id', $tenant->id)->whereKey($season)->value('name');
            }
        } elseif ($kind === 'spots') {
            $relations = [];
        } else {
            // Tenant predicates also protect relation data in administrative exports.
            $query->whereHas('member', fn ($q) => $q->where('tenant_id', $tenant->id));
            if (in_array($kind, ['families', 'banks'], true)) {
                $query->whereHas('member', fn ($q) => $q->whereNull('archived_at'));
            }
            $memberColumns = ['id', 'legacy_id', 'first_name', 'last_name', 'company'];
            if (in_array($kind, ['keys', 'banks', 'families'], true)) {
                $memberColumns[] = 'status_id';
            }
            if ($kind === 'families') {
                $memberColumns = [...$memberColumns, 'type_id', 'birth_date'];
            } elseif ($kind === 'player_contacts') {
                $memberColumns = [...$memberColumns, 'email', 'mobile', 'mother_name', 'mother_email', 'mother_mobile', 'father_name', 'father_email', 'father_mobile'];
            }
            $relations = ['member' => fn ($q) => $q->where('tenant_id', $tenant->id)->select($memberColumns)];
            if (in_array($kind, ['keys', 'banks', 'families'], true)) {
                $relations['member.status'] = fn ($q) => $q->where('tenant_id', $tenant->id);
            }
            if ($kind === 'families') {
                $query->whereHas('family', fn ($q) => $q->where('tenant_id', $tenant->id)->where('active', true));
                $relations[] = 'family';
                $relations['member.type'] = fn ($q) => $q->where('tenant_id', $tenant->id);
            } elseif ($kind === 'banks') {
                $relations['bank'] = fn ($q) => $q->where('tenant_id', $tenant->id);
            } elseif ($kind !== 'keys') {
                $query->whereHas('teamSeason', fn ($q) => $q->where('tenant_id', $tenant->id));
                $relations[] = 'teamSeason.season';
                if ($kind === 'staff') {
                    $relations['staffFunction'] = fn ($q) => $q->where('tenant_id', $tenant->id);
                }
            }
        }
        $rows = $query->limit(5001)->get();
        if ($rows->count() > 5000) {
            throw ValidationException::withMessages(['export' => 'Bitte die Auswahl auf höchstens 5000 Einträge eingrenzen.']);
        }
        $rows->load($relations);
        // Materialize only whitelisted fields before sending headers; errors cannot yield partial CSV.
        $values = $rows->map(function ($row) use ($kind, $membership, $seasonName) {
            if ($kind === 'spots') {
                return [$row->kind === 'family' ? 'Familie' : 'Einzelmitglied', $row->company, $row->name,
                    $row->street, $row->postal_code, $row->city, $row->country];
            }
            if ($membership) {
                return [$row->legacy_id ?? $row->id, $row->display_name, $row->type?->name, $row->status?->name, ...($kind === 'unassigned' ? [$seasonName] : [])];
            }
            $member = $row->member;
            $common = [$member->legacy_id ?? $member->id, $member->display_name];

            return match ($kind) {
                'keys' => [$row->number, $row->name, $row->color, ...$common, $member->status?->name, $row->active ? 'Ja' : 'Nein'],
                'players' => [...$common, $row->teamSeason?->season?->name, $row->teamSeason?->label, $row->active ? 'Ja' : 'Nein', $row->invoice_line_id === null ? 'Nein' : 'Ja'],
                'player_contacts' => [...$common, $row->teamSeason?->season?->name, $row->teamSeason?->label, $member->email, $member->mobile,
                    $member->mother_name, $member->mother_email, $member->mother_mobile, $member->father_name, $member->father_email, $member->father_mobile],
                'staff' => [...$common, $row->teamSeason?->season?->name, $row->teamSeason?->label, $row->staffFunction?->name, $row->active ? 'Ja' : 'Nein'],
                'families' => [$row->family?->name, ...$common, $row->family?->street, $row->family?->postal_code, $row->family?->city, $member->birth_date?->format('d.m.Y'), $member->type?->name, $member->status?->name, $row->discount, $row->active ? 'Ja' : 'Nein'],
                'banks' => [...$common, $row->bank?->name, $row->masked_iban, $row->bank?->city, $row->bank?->swift, $member->status?->name, $row->active ? 'Ja' : 'Nein',
                    $row->bank?->street, $row->bank?->postal_address, $row->bank?->postal_code, $row->bank?->country_code, $row->bank?->clearing_number, $row->bank?->postal_account],
            };
        });
        DB::connection('clubunio')->table('clubora.object_changes')->insert([
            'tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'report_exports', 'object_id' => $tenant->id,
            'after_value' => json_encode(['action' => 'export_prepared', 'kind' => $kind, 'row_count' => $values->count()], JSON_THROW_ON_ERROR),
        ]);
        $headers = match ($kind) {
            'spots' => ['Empfänger', 'Firma', 'Name', 'Strasse', 'PLZ', 'Ort', 'Land'],
            'keys' => ['Schlüsselnummer', 'Bezeichnung', 'Farbe', 'Mitglied-Nr.', 'Mitglied', 'Mitgliederstatus', 'Aktiv'],
            'players' => ['Mitglied-Nr.', 'Mitglied', 'Saison', 'Mannschaft', 'Aktiv', 'Beitragsposition vorhanden'],
            'player_contacts' => ['Mitglied-Nr.', 'Mitglied', 'Saison', 'Mannschaft', 'E-Mail Spieler', 'Mobile Spieler', 'Mutter', 'E-Mail Mutter', 'Mobile Mutter', 'Vater', 'E-Mail Vater', 'Mobile Vater'],
            'staff' => ['Mitglied-Nr.', 'Mitglied', 'Saison', 'Mannschaft', 'Funktion', 'Aktiv'],
            'families' => ['Familie', 'Mitglied-Nr.', 'Mitglied', 'Strasse', 'PLZ', 'Ort', 'Geburtsdatum', 'Mitgliederart', 'Mitgliederstatus', 'Rabatt', 'Aktiv'],
            'banks' => ['Mitglied-Nr.', 'Mitglied', 'Bank', 'IBAN (maskiert)', 'Bankort', 'SWIFT', 'Mitgliederstatus', 'Aktiv', 'Bankdomizil', 'Bankpostadresse', 'Bank-PLZ', 'Bank-Land', 'Clearing-Nr.', 'Bankpostkonto'],
            'unassigned' => ['Mitglied-Nr.', 'Mitglied', 'Mitgliederart', 'Mitgliederstatus', 'Saison'],
            'passive' => ['Mitglied-Nr.', 'Mitglied', 'Mitgliederart', 'Mitgliederstatus'],
            'member_reports' => ['Mitglied-Nr.', 'Mitglied', 'Mitgliederart', 'Mitgliederstatus'],
        };
        $name = ['spots' => 'spots-versandliste', 'keys' => 'schluesselliste', 'players' => 'spielerliste', 'player_contacts' => 'spieler-kontaktliste', 'staff' => 'staffliste', 'families' => 'familienliste', 'banks' => 'bankkontenliste', 'unassigned' => 'mitglieder-ohne-mannschaft', 'passive' => 'passivmitglieder', 'member_reports' => 'mitgliederliste'][$kind];

        return response()->streamDownload(function () use ($headers, $values) {
            $file = fopen('php://output', 'wb');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, $headers, ';', '"', '');
            foreach ($values as $row) {
                fputcsv($file, array_map(function ($value) {
                    $value = (string) $value;

                    return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'".$value : $value;
                }, $row), ';', '"', '');
            }
            fclose($file);
        }, $name.'-'.now('Europe/Zurich')->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
