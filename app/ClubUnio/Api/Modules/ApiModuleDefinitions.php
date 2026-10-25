<?php

namespace App\ClubUnio\Api\Modules;

use App\ClubUnio\Models;
use App\ClubUnio\Services\PermissionCatalog;

/** Explicit API surface, independent of Eloquent fillable/hidden attributes. */
final class ApiModuleDefinitions
{
    public static function all(): array
    {
        $definitions = [];
        $add = function (string $key, string $model, string $permission, string $service, array $fields,
            array $search = [], array $filters = [], array $immutable = [], array $extraRead = [], ?string $version = null) use (&$definitions): void {
            if ($service === 'catalog') {
                $version = '_version';
            }
            $definitions[$key] = [
                'key' => $key, 'model' => $model, 'permission_module' => $permission, 'service' => $service,
                'read_fields' => array_values(array_unique(['id', ...$fields, ...$extraRead])),
                'search_fields' => $search, 'filter_fields' => $filters,
                'sort_fields' => array_values(array_unique(['id', ...$search])),
                'create_fields' => $fields,
                'update_fields' => array_values(array_diff([...$fields, ...($version ? [$version] : [])], $immutable)),
                'version' => $version,
                'actions' => $key === 'members' ? ['archive' => ['function' => 'deactivate', 'fields' => ['_version']]] : [],
            ];
        };

        $add('members', Models\Member::class, 'members', 'member',
            ['first_name', 'last_name', 'company', 'email', 'city', 'birth_date', 'status_id', 'type_id',
                'street', 'house_number', 'postal_code', 'country_id', 'nationality_id', 'gender_id',
                'phone_private', 'phone_business', 'mobile', 'website', 'pass_number', 'exit_date', 'billing_recipient_id'],
            ['first_name', 'last_name', 'company', 'email', 'city'], ['status_id', 'type_id', 'country_id', 'gender_id'],
            [], ['member_number', 'archived_at'], '_version');
        $add('teams', Models\Team::class, 'teams', 'catalog',
            ['name', 'active', 'description', 'sfv_team_id', 'laundry_allowance'], ['name', 'description'], ['active']);
        $add('seasons', Models\Season::class, 'seasons', 'catalog',
            ['name', 'active', 'starts_on', 'ends_on'], ['name'], ['active']);
        $add('team_seasons', Models\TeamSeason::class, 'teams', 'assignment',
            ['team_id', 'season_id', 'active'], [], ['team_id', 'season_id', 'active'], [], ['label'], '_version');
        $add('team_players', Models\TeamPlayer::class, 'teams', 'assignment',
            ['member_id', 'team_season_id', 'active'], [], ['member_id', 'team_season_id', 'active'], [], [], '_version');
        $add('team_staff', Models\TeamStaff::class, 'teams', 'assignment',
            ['member_id', 'team_season_id', 'staff_function_id', 'active'], [], ['member_id', 'team_season_id', 'staff_function_id', 'active'], [], [], '_version');
        $add('technical_leads', Models\TechnicalLead::class, 'teams', 'assignment',
            ['member_id', 'team_season_id', 'active'], [], ['member_id', 'team_season_id', 'active'], [], [], '_version');
        $add('training_slots', Models\TrainingSlot::class, 'training_slots', 'training',
            ['team_season_id', 'starts_at', 'ends_at', 'hall', 'period_id', 'weekday_id', 'field_id', 'room_id', 'side_id',
                'all_fields', 'without_main', 'synthetic_only', 'website_visible', 'active'], ['hall'],
            ['team_season_id', 'period_id', 'weekday_id', 'field_id', 'active', 'website_visible'], [], ['revision'], 'revision');
        $add('competitions', Models\Competition::class, 'competitions', 'facility',
            ['plays_on', 'starts_at', 'host_name', 'guest_name', 'host_category', 'guest_category', 'host_club_number',
                'guest_club_number', 'remarks', 'show_on_signage', 'field_id', 'host_room_id', 'guest_room_id',
                'host_side_id', 'guest_side_id', 'active'], ['host_name', 'guest_name'], ['plays_on', 'field_id', 'active', 'show_on_signage'],
            [], ['revision'], 'revision');
        $add('facility_statuses', Models\FacilityStatus::class, 'facilities', 'facility',
            ['name', 'code', 'blocks_training', 'sort_order', 'active'], ['name'], ['active', 'blocks_training'], ['code'], ['revision'], 'revision');
        $add('field_availability', Models\FieldAvailability::class, 'field_availability', 'facility',
            ['field_id', 'status_id', 'note', 'active'], ['note'], ['field_id', 'status_id', 'active'], [], ['revision'], 'revision');
        $add('football_clubs', Models\FootballClub::class, 'catalogs', 'facility',
            ['name', 'club_number', 'logo_enabled', 'active'], ['name'], ['club_number', 'active'], ['club_number'], ['revision'], 'revision');
        $add('training_options', Models\TrainingOption::class, 'catalogs', 'training',
            ['name', 'category', 'code', 'sort_order', 'active'], ['name'], ['category', 'active'], ['category', 'code'], ['revision'], 'revision');
        foreach (['club_functions' => Models\ClubFunction::class, 'qualifications' => Models\Qualification::class] as $key => $model) {
            $add($key, $model, 'catalogs', 'dossier', ['name', 'active'], ['name'], ['active'], [], ['revision'], 'revision');
        }
        $catalogs = [
            'member_statuses' => [Models\MemberStatus::class, ['ends_membership', 'restores_membership', 'spots_eligible']],
            'member_types' => [Models\MemberType::class, ['team_required', 'passive_membership']],
            'registration_statuses' => [Models\RegistrationStatus::class, []],
            'staff_functions' => [Models\StaffFunction::class, []],
            'countries' => [Models\Country::class, ['iso3']], 'genders' => [Models\Gender::class, []],
            'billing_recipients' => [Models\BillingRecipient::class, ['email_field']],
            'document_types' => [Models\DocumentType::class, []],
            'expense_types' => [Models\ExpenseType::class, ['receipt_required', 'amount_source']],
            'expense_rounds' => [Models\ExpenseRound::class, []], 'expense_statuses' => [Models\ExpenseStatus::class, []],
            'game_types' => [Models\GameType::class, []],
        ];
        foreach ($catalogs as $key => [$model, $extra]) {
            $add($key, $model, 'catalogs', 'catalog', ['name', 'code', 'sort_order', 'active', ...$extra],
                ['name'], ['code', 'active'], ['code', ...array_intersect($extra, ['iso3', 'email_field'])],
                $key === 'registration_statuses' ? ['workflow_key'] : []);
        }
        $readOnlyCatalogs = [
            'invoice_statuses' => [Models\InvoiceStatus::class, ['name', 'code', 'active', 'position_code']],
            'invoice_line_statuses' => [Models\InvoiceLineStatus::class, ['name', 'code', 'active']],
            'invoice_workflow_statuses' => [Models\InvoiceWorkflowStatus::class, ['name', 'code', 'sort_order', 'active']],
            'invoice_categories' => [Models\InvoiceCategory::class, ['name', 'code', 'sort_order', 'active']],
            'financial_adjustment_statuses' => [Models\FinancialAdjustmentStatus::class, ['name', 'code', 'sort_order', 'active']],
            'member_change_statuses' => [Models\MemberChangeStatus::class, ['name', 'code', 'sort_order', 'active', 'color']],
            'banks' => [Models\Bank::class, ['name', 'code', 'sort_order', 'active']],
        ];
        foreach ($readOnlyCatalogs as $key => [$model, $read]) {
            $add($key, $model, 'catalogs', 'read_only', [], ['name'], ['code', 'active'], [], $read);
        }
        foreach ($definitions as &$definition) {
            [$createRequired, $updateRequired] = self::requiredFields($definition);
            foreach (['create', 'update'] as $operation) {
                if (! isset(PermissionCatalog::functions($definition['permission_module'])[$operation])) {
                    $definition[$operation.'_fields'] = [];
                }
                $definition[$operation.'_required'] = array_values(array_intersect(
                    $operation === 'create' ? $createRequired : $updateRequired,
                    $definition[$operation.'_fields'],
                ));
            }
        }
        unset($definition);

        $definitions['members']['record_rules'] = [
            'first_name' => 'present|nullable|required_without:company|string|max:255',
            'last_name' => 'present|nullable|required_without:company|string|max:255',
            'company' => 'present|nullable|string|max:255',
        ];
        $definitions['members']['validation_notes'] = [
            'record_rules apply to the merged resulting member, not mandatory PATCH payload keys. Missing input keys inherit existing values (null for a new member).',
            'A member needs a nonempty company or both nonempty first_name and last_name. A company member may have null first_name and last_name.',
            'The selected billing recipient requires its configured email field on the resulting member. The email field is not unconditionally required in every payload.',
        ];
        $definitions['team_staff']['validation_notes'] = [
            'staff_function_id is required on create and normally on update. An existing legacy assignment with no staff function may retain null on update.',
        ];
        $definitions['training_slots']['validation_notes'] = ['Either field_id or a nonempty hall is required.'];
        $definitions['field_availability']['validation_notes'] = [
            'With facilities:update, the update payload must also include field_id and active. Narrow field_availability:update grants inherit both from the record and cannot change them.',
        ];

        return $definitions;
    }

    /** Unconditional payload requirements only; conditional/merged-record rules stay separate. */
    private static function requiredFields(array $definition): array
    {
        if ($definition['service'] === 'read_only') {
            return [[], []];
        }
        $key = $definition['key'];
        if ($definition['service'] === 'catalog') {
            $create = in_array($key, ['teams', 'seasons'], true)
                ? ['name', 'active'] : ['name', 'code', 'sort_order', 'active'];
            $create = [...$create, ...match ($key) {
                'countries' => ['iso3'], 'billing_recipients' => ['email_field'],
                'expense_types' => ['receipt_required', 'amount_source'], default => [],
            }];

            return [$create, [...$create, '_version']];
        }
        $create = match ($key) {
            'members' => ['status_id', 'type_id', 'billing_recipient_id'],
            'team_seasons' => ['team_id', 'season_id', 'active'],
            'team_players', 'technical_leads' => ['member_id', 'team_season_id', 'active'],
            'team_staff' => ['member_id', 'team_season_id', 'staff_function_id', 'active'],
            'training_slots' => ['team_season_id', 'starts_at', 'ends_at', 'period_id', 'weekday_id',
                'all_fields', 'without_main', 'synthetic_only', 'website_visible', 'active'],
            'competitions' => ['plays_on', 'host_name', 'guest_name', 'show_on_signage', 'active'],
            'facility_statuses' => ['name', 'code', 'blocks_training', 'sort_order', 'active'],
            'field_availability' => [],
            'football_clubs' => ['name', 'club_number', 'logo_enabled', 'active'],
            'training_options' => ['name', 'category', 'code', 'sort_order', 'active'],
            'club_functions', 'qualifications' => ['name', 'active'],
            default => throw new \LogicException('Missing required-field metadata for '.$key),
        };
        $update = match ($key) {
            'members' => [],
            'team_staff' => ['member_id', 'team_season_id', 'active'],
            'field_availability' => ['status_id'],
            default => $create,
        };

        return [$create, [...$update, $definition['version']]];
    }

    public static function unsupported(): array
    {
        return [
            'personal_roles' => 'No trainer, parent or member impersonation; technical access profiles only.',
            'families' => 'No family/dossier relationship adapter yet.',
            'registrations' => 'Review, documents and acceptance require a dedicated workflow adapter.',
            'member_changes' => 'No approval/conflict workflow adapter yet.',
            'documents' => 'No private files, storage paths, downloads or uploads in generic DTOs.',
            'contributions' => 'No price, invoice generation or finance adapter yet.',
            'invoices' => 'No financial records, provider calls or payment actions.',
            'expenses' => 'No financial records, bank details or payment actions.',
            'credits' => 'No credit, refund or allocation actions.',
            'bexio' => 'No connection/provider mutation or synchronization adapter. Authorized PDF reads use dedicated file endpoints.',
            'planning' => 'No calendar/absence/series workflow adapter yet.',
            'training' => 'No AI or training-plan adapter yet.',
            'season_plans' => 'No wizard completion or bulk season transfer adapter yet.',
            'bus' => 'No reservations or hardware actions.',
            'signage' => 'No notice publication adapter; competitions retain separate publication permission.',
            'announcements' => 'No mail, push or message delivery.',
            'events' => 'No event/guest workflow adapter yet.',
            'sponsor_runs' => 'Separate work in progress; deliberately excluded.',
            'reports' => 'No bulk export adapter.', 'templates' => 'No template or dispatch adapter.',
            'administration' => 'Accounts, profiles, settings and secrets are never module DTOs.',
            'catalog_mutations' => 'Bank institutions and financial/member-change status lookups are read-only. No provider mapping writes or member bank account DTOs.',
        ];
    }
}
