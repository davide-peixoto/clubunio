<?php

namespace App\ClubUnio\Assistant;

use App\ClubUnio\Mobile\MobileReadContext;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Registration;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\BusReservationService;
use App\ClubUnio\Services\DashboardService;
use App\ClubUnio\Services\ImpersonationService;
use App\ClubUnio\Services\LookupTranslations;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\ModulePermissionService;
use Carbon\CarbonImmutable as Time;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssistantReadTools
{
    private const LIMIT = 20;

    private const TIMEZONE = 'Europe/Zurich';

    public function definitions(): array
    {
        $date = ['type' => ['string', 'null'], 'pattern' => '^\d{4}-\d{2}-\d{2}$',
            'description' => 'YYYY-MM-DD; null or omitted means today in Europe/Zurich.'];
        $status = fn (array $values) => ['type' => ['string', 'null'], 'enum' => [null, ...$values]];
        $tools = [
            'read_daily_briefing' => ['Current active members and pending work, plus reservations overlapping the selected Zurich day. Not a historical snapshot. Invoice overdue counts are unavailable.', ['date' => $date]],
            'read_members' => ['Search active members by literal name or company. No contacts, birth dates, notes or account data. At most 20 results.', [
                'query' => ['type' => 'string', 'maxLength' => 120],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::LIMIT],
            ]],
            'read_registrations' => ['Current active registrations. Null status means pending (submitted, review, trial); all removes the status filter. Query searches literal applicant names.', [
                'status' => $status(['all', 'pending', 'submitted', 'review', 'trial', 'accepted', 'rejected', 'pool']),
                'query' => ['type' => ['string', 'null'], 'maxLength' => 120],
            ]],
            'read_expenses' => ['Current active expenses. Null status means pending review/payment (F/Z). Status codes: O draft, F review, Z payment, R rejected, B paid. No bank or receipt data.', [
                'status' => $status(['all', 'pending', 'O', 'F', 'Z', 'R', 'B']),
            ]],
            'read_invoices' => ['Current active invoices. Null means open balances; pending means draft/released workflow. Overdue cannot be determined because no authoritative due date is stored. Never treat open as overdue.', [
                'status' => $status(['all', 'open', 'pending', 'overdue']),
            ]],
            'read_reservations' => ['Null date lists pending bus/pitch requests across all dates, including older undecided requests. An explicit date lists pending/approved bookings overlapping that Zurich day. Pitch excludes recurring training, matches and blocks; not a complete availability calendar.', [
                'kind' => ['type' => 'string', 'enum' => ['bus', 'pitch', 'all']],
                'date' => array_replace($date, ['description' => 'YYYY-MM-DD, or null/omitted for pending requests across all dates.']),
            ]],
        ];

        $result = [];
        foreach ($tools as $name => [$description, $properties]) {
            $result[] = ['type' => 'function', 'name' => $name, 'description' => $description,
                'parameters' => ['type' => 'object', 'properties' => $properties,
                    'required' => array_keys($properties), 'additionalProperties' => false], 'strict' => true];
        }

        return $result;
    }

    public function execute(Account $actor, Tenant $tenant, string $name, array $arguments): array
    {
        abort_if(request()->attributes->has('clubunio_impersonation')
            || (request()->hasSession() && request()->session()->has(ImpersonationService::KEY)), 403);
        // Bypass both request-local permission snapshots, even when called inside a GET.
        $frames = [];
        foreach ([BackofficeReadContext::class, MobileReadContext::class] as $key) {
            $frames[$key] = request()->attributes->get($key);
            request()->attributes->remove($key);
        }
        try {
            $fresh = Account::query()->select(['id', 'active', 'api_only'])->find($actor->getKey());
            abort_unless($fresh?->active && ! $fresh->api_only
                && Tenant::query()->whereKey($tenant->getKey())->exists()
                && DB::connection('clubunio')->table('clubunio.tenant_accounts')
                    ->where('account_id', $fresh->id)->where('tenant_id', $tenant->id)
                    ->whereIn('role', ['admin', 'office'])->exists(), 403);
            $args = $this->arguments($name, $arguments);
            $modules = match ($name) {
                'read_daily_briefing' => ['members', 'registrations', 'expenses', 'invoices', 'bus', 'competitions'],
                'read_reservations' => match ($args['kind']) {
                    'bus' => ['bus'], 'pitch' => ['competitions'], default => ['bus', 'competitions'],
                },
                default => [substr($name, 5)],
            };
            foreach ($modules as $module) {
                app(ModulePermissionService::class)->authorize($fresh, $tenant, $module, 'view');
            }

            return match ($name) {
                'read_daily_briefing' => $this->briefing($fresh, $tenant, $args['date']),
                'read_members' => $this->members($tenant, $args['query'], $args['limit']),
                'read_registrations' => $this->registrations($tenant, $args['status'] ?? 'pending', $args['query'] ?? ''),
                'read_expenses' => $this->expenses($tenant, $args['status'] ?? 'pending'),
                'read_invoices' => $this->invoices($tenant, $args['status'] ?? 'open'),
                'read_reservations' => $this->reservations($fresh, $tenant, $args['kind'], $args['date']),
            };
        } finally {
            foreach ($frames as $key => $value) {
                request()->attributes->remove($key);
                if ($value !== null) {
                    request()->attributes->set($key, $value);
                }
            }
        }
    }

    private function arguments(string $name, array $arguments): array
    {
        $definition = collect($this->definitions())->firstWhere('name', $name);
        if (! $definition || array_diff(array_keys($arguments), array_keys($definition['parameters']['properties']))) {
            $this->invalid('arguments');
        }
        foreach ($definition['parameters']['properties'] as $key => $schema) {
            $value = $arguments[$key] ?? null;
            if ($value === null && in_array('null', (array) $schema['type'], true)) {
                continue;
            }
            if ($key === 'limit' && ! array_key_exists($key, $arguments)) {
                continue;
            }
            if (($schema['type'] === 'integer' && (! is_int($value) || $value < 1))
                || ($schema['type'] !== 'integer' && ! is_string($value))
                || (is_string($value) && isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength'])
                || (isset($schema['enum']) && ! in_array($value, $schema['enum'], true))) {
                $this->invalid($key);
            }
        }
        if (array_key_exists('date', $definition['parameters']['properties'])) {
            $date = $arguments['date'] ?? ($name === 'read_daily_briefing' ? Time::now(self::TIMEZONE)->toDateString() : null);
            if ($date !== null && (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts)
                || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]))) {
                $this->invalid('date');
            }
            $arguments['date'] = $date;
        }
        if ($name === 'read_members') {
            $arguments['limit'] = min(self::LIMIT, $arguments['limit'] ?? self::LIMIT);
        }

        return $arguments;
    }

    private function invalid(string $field): never
    {
        throw ValidationException::withMessages([$field => __('assistant.read.invalid_arguments')]);
    }

    private function result(string $title, array $rows, ?int $total, array $coverage = [], array $counts = []): array
    {
        return ['title' => __($title), 'summary' => $total === null ? __('assistant.read.overdue_unavailable')
            : __('Showing :shown of :total matches.', ['shown' => count($rows), 'total' => $total]),
            'rows' => $rows, 'total' => $total, 'returned' => count($rows), 'has_more' => $total !== null && $total > count($rows),
            'counts' => ['matching' => $total, 'returned' => count($rows)] + $counts,
            'coverage' => $coverage + ['scope' => 'current_active_records', 'timezone' => self::TIMEZONE,
                'as_of' => Time::now(self::TIMEZONE)->toIso8601String(), 'historical_snapshot' => false]];
    }

    private function search(Builder $query, string $search, array $columns): void
    {
        $search = trim($search);
        if ($search === '') {
            return;
        }
        // Literal substring search: wildcard characters and all model input remain bound data.
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
        $query->where(function ($query) use ($columns, $pattern) {
            foreach ($columns as $column) {
                $query->orWhereRaw("LOWER($column) LIKE ? ESCAPE '!'", [$pattern]);
            }
        });
    }

    private function text(?string $value): string
    {
        return mb_substr((string) $value, 0, 160);
    }

    private function status(?object $status, string $table, Tenant $tenant): array
    {
        return ['status' => $status?->code, 'status_label' => $status
            ? $this->text(app(LookupTranslations::class)->label($table, $status, null, (int) $tenant->id)) : __('Unbekannt')];
    }

    private function members(Tenant $tenant, string $search, int $limit): array
    {
        $query = app(MemberAccessService::class)->activeMembers($tenant);
        $this->search($query, $search, ['first_name', 'last_name', 'company']);
        $total = (clone $query)->count();
        $rows = $query->select(['id', 'tenant_id', 'first_name', 'last_name', 'company', 'status_id'])
            ->with(['status' => fn ($q) => $q->where('tenant_id', $tenant->id)->select(['id', 'tenant_id', 'code', 'name'])])
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')->limit($limit)->get()->map(fn ($row) => [
                'id' => (int) $row->id, 'label' => $this->text($row->company ?: trim($row->first_name.' '.$row->last_name)),
                'url' => '/members/'.$row->id,
            ] + $this->status($row->status, 'member_statuses', $tenant))->all();

        return $this->result('Mitglieder', $rows, $total, ['scope' => 'current_active_members']);
    }

    private function registrations(Tenant $tenant, string $status, string $search, int $limit = self::LIMIT): array
    {
        $query = Registration::query()->where('tenant_id', $tenant->id)->where('active', true);
        if ($status !== 'all') {
            $query->whereHas('status', fn ($q) => $q->where('tenant_id', $tenant->id)
                ->whereIn('workflow_key', $status === 'pending' ? ['submitted', 'review', 'trial'] : [$status]));
        }
        $this->search($query, $search, ['first_name', 'last_name']);
        $total = (clone $query)->count();
        $rows = $query->select(['id', 'tenant_id', 'first_name', 'last_name', 'submitted_at', 'status_id', 'revision'])
            ->with(['status' => fn ($q) => $q->where('tenant_id', $tenant->id)->select(['id', 'tenant_id', 'code', 'name', 'workflow_key'])])
            ->orderBy('submitted_at')->orderBy('id')->limit($limit)->get()->map(fn ($row) => [
                'id' => (int) $row->id, 'revision' => (int) $row->revision, 'label' => $this->text(trim($row->first_name.' '.$row->last_name)),
                'submitted_on' => $row->submitted_at?->toDateString(), 'workflow' => $row->status?->workflow_key,
                'url' => '/registrations',
            ] + $this->status($row->status, 'registration_statuses', $tenant))->all();

        return $this->result('Anmeldungen', $rows, $total, ['status_filter' => $status]);
    }

    private function expenses(Tenant $tenant, string $status, int $limit = self::LIMIT): array
    {
        $query = Expense::query()->where('tenant_id', $tenant->id)->where('active', true);
        if ($status !== 'all') {
            $query->whereHas('status', fn ($q) => $q->where('tenant_id', $tenant->id)
                ->whereIn('code', $status === 'pending' ? ['F', 'Z'] : [$status]));
        }
        $total = (clone $query)->count();
        $rows = $query->select(['id', 'tenant_id', 'status_id', 'released_on', 'payment_released_on', 'paid_on', 'revision'])
            ->with(['status' => fn ($q) => $q->where('tenant_id', $tenant->id)->select(['id', 'tenant_id', 'code', 'name'])])
            ->withSum(['activeLines' => fn ($q) => $q->where('tenant_id', $tenant->id)], 'amount')
            ->orderBy('id')->limit($limit)->get()->map(fn ($row) => [
                'id' => (int) $row->id, 'revision' => (int) $row->revision, 'label' => __('Spesen').' #'.$row->id, 'url' => '/expenses/'.$row->id,
                'amount' => (string) ($row->active_lines_sum_amount ?? '0'), 'currency' => 'CHF',
                'released_on' => $row->released_on?->toDateString(), 'payment_released_on' => $row->payment_released_on?->toDateString(),
                'paid_on' => $row->paid_on?->toDateString(),
            ] + $this->status($row->status, 'expense_statuses', $tenant))->all();

        return $this->result('Spesen', $rows, $total, ['status_filter' => $status]);
    }

    private function invoices(Tenant $tenant, string $status, int $limit = self::LIMIT): array
    {
        $coverage = ['status_filter' => $status, 'overdue_supported' => false,
            'note' => __('assistant.read.overdue_unavailable'), 'provider_refreshed' => false];
        if ($status === 'overdue') {
            return $this->result('Rechnungen', [], null, $coverage, ['overdue' => null]);
        }
        $query = Invoice::query()->where('tenant_id', $tenant->id)->where('active', true);
        if ($status === 'open') {
            $query = app(DashboardService::class)->scopeOpenInvoices($query, $tenant);
        } elseif ($status === 'pending') {
            $query->whereHas('workflowStatus', fn ($q) => $q->where('tenant_id', $tenant->id)->whereIn('code', ['draft', 'released']));
        }
        $total = (clone $query)->count();
        // Retain the domain's open-balance expression, but never hydrate invoice notes or remote payloads.
        $columns = ['clubunio.invoices.id', 'clubunio.invoices.tenant_id', 'status_id', 'workflow_status_id', 'rounding_cents', 'revision'];
        if ($status === 'open') {
            $query->getQuery()->columns = array_merge($columns, array_slice($query->getQuery()->columns, 1));
        } else {
            $query->select($columns);
        }
        $rows = $query->with([
            'status' => fn ($q) => $q->where('tenant_id', $tenant->id)->select(['id', 'tenant_id', 'code', 'name']),
            'workflowStatus' => fn ($q) => $q->where('tenant_id', $tenant->id)->select(['id', 'tenant_id', 'code', 'name']),
            'bexioBalance' => fn ($q) => $q->where('tenant_id', $tenant->id)->select(['object_id', 'tenant_id', 'observed_at']),
        ])->orderBy('clubunio.invoices.id')->limit($limit)->get()->map(fn ($row) => [
            'id' => (int) $row->id, 'revision' => (int) $row->revision, 'label' => __('Rechnung').' #'.$row->id, 'url' => '/invoices/'.$row->id,
            'open_amount' => $status === 'open' ? (string) $row->open_amount : null, 'currency' => 'CHF',
            'workflow' => $row->workflowStatus?->code, 'workflow_label' => $row->workflowStatus
                ? $this->status($row->workflowStatus, 'invoice_workflow_statuses', $tenant)['status_label'] : __('Importiert'),
            'balance_observed_at' => $row->bexioBalance?->observed_at, 'overdue' => null,
        ] + $this->status($row->status, 'invoice_statuses', $tenant))->all();

        return $this->result('Rechnungen', $rows, $total, $coverage, ['overdue' => null]);
    }

    private function reservations(Account $actor, Tenant $tenant, string $kind, ?string $date, int $limit = self::LIMIT): array
    {
        $from = Time::parse($date ?? Time::now(self::TIMEZONE)->toDateString(), self::TIMEZONE)->startOfDay();
        $to = $from->addDay();
        $rows = [];
        $counts = [];
        $total = 0;
        foreach ($kind === 'all' ? ['bus', 'pitch'] : [$kind] as $type) {
            $pendingCode = $type === 'bus' ? 'ANGEFRAGT' : 'pending';
            $base = $type === 'bus' ? app(BusReservationService::class)->visible($actor, $tenant)
                : DB::connection('clubunio')->table('clubunio.pitch_requests')->where('tenant_id', $tenant->id);
            $pending = (clone $base)->where('status', $pendingCode);
            $counts[$type.'_pending_all_dates'] = (clone $pending)->count();
            $counts[$type.'_pending_before_date'] = (clone $pending)
                ->where($type === 'bus' ? 'starts_at' : 'date', '<', $type === 'bus' ? $from->toIso8601String() : $from->toDateString())->count();
            $query = $date === null ? clone $pending
                : (clone $base)->whereIn('status', [$pendingCode, $type === 'bus' ? 'GENEHMIGT' : 'approved']);
            if ($type === 'bus') {
                if ($date !== null) {
                    $query->where('starts_at', '<', $to->toIso8601String())->where('ends_at', '>', $from->toIso8601String());
                }
                $columns = ['id', 'revision', 'status', 'starts_at', 'ends_at', 'location'];
            } else {
                if ($date !== null) {
                    $query->whereBetween('date', [$from->subDay()->toDateString(), $date]);
                    $end = DB::connection('clubunio')->getDriverName() === 'sqlite'
                        ? "datetime(date || ' ' || starts_at, '+' || duration_minutes || ' minutes')"
                        : "(date + starts_at + duration_minutes * interval '1 minute')";
                    $query->whereRaw($end.' > ?', [$from->toDateTimeString()]);
                }
                $columns = ['id', 'revision', 'status', 'date', 'starts_at', 'duration_minutes', 'opponent'];
                $query->orderBy('date');
            }
            $countKey = $type.($date === null ? '_matching' : '_on_date');
            $counts[$countKey] = (clone $query)->count();
            $total += $counts[$countKey];
            // Each kind gets a bounded candidate page; merge chronologically before the final cap.
            foreach ($query->orderBy('starts_at')->orderBy('id')->limit($limit)->get($columns) as $row) {
                $start = Time::parse($type === 'bus' ? $row->starts_at : $row->date.' '.$row->starts_at, self::TIMEZONE)->setTimezone(self::TIMEZONE);
                $finish = $type === 'bus' ? Time::parse($row->ends_at)->setTimezone(self::TIMEZONE) : $start->addMinutes($row->duration_minutes);
                $rows[] = ['id' => (int) $row->id, 'revision' => (int) $row->revision, 'kind' => $type,
                    'label' => $this->text(($type === 'bus' ? $row->location : $row->opponent) ?: __($type === 'bus' ? 'Busreservierungen' : 'Sportplatzbelegung')),
                    'status' => $row->status, 'status_label' => __($row->status === $pendingCode ? 'Angefragt' : 'Genehmigt'),
                    'starts_at' => $start->toIso8601String(), 'ends_at' => $finish->toIso8601String(),
                    'url' => $type === 'bus' ? route('clubunio.workspace.bus.show', ['reservation' => $row->id], false)
                        : route('filament.clubunio.pages.pitch-planning', ['date' => $start->toDateString(), 'booking' => $row->id], false)];
            }
        }
        usort($rows, fn ($a, $b) => [$a['starts_at'], $a['kind'], $a['id']] <=> [$b['starts_at'], $b['kind'], $b['id']]);

        return $this->result(match ($kind) {
            'bus' => 'Busreservierungen', 'pitch' => 'Sportplatzbelegung', default => 'Reservationen'
        }, array_slice($rows, 0, $limit), $total,
            ['scope' => $date === null ? 'pending_all_dates' : 'day_overlap', 'date' => $date, 'reference_date' => $from->toDateString(),
                'from' => $date === null ? null : $from->toIso8601String(), 'to_exclusive' => $date === null ? null : $to->toIso8601String(),
                'kind' => $kind, 'pitch_sources' => $kind === 'bus' ? [] : ['pitch_requests'],
                'complete_availability_calendar' => false, 'note' => __('assistant.read.reservation_coverage')], $counts);
    }

    private function briefing(Account $actor, Tenant $tenant, string $date): array
    {
        $sections = [
            'members' => $this->members($tenant, '', 3),
            'registrations' => $this->registrations($tenant, 'pending', '', 3),
            'expenses' => $this->expenses($tenant, 'pending', 3),
            'invoices' => $this->invoices($tenant, 'open', 3),
            'bus' => $this->reservations($actor, $tenant, 'bus', $date, 3),
            'pitch' => $this->reservations($actor, $tenant, 'pitch', $date, 3),
        ];
        $rows = [];
        foreach ($sections as $key => $section) {
            foreach ($section['rows'] as $row) {
                $rows[] = $row + ['module' => $key];
            }
        }
        $counts = array_map(fn ($section) => $section['counts'], $sections);
        $result = $this->result('assistant.read.briefing_title', $rows, array_sum(array_column($sections, 'total')),
            ['scope' => 'current_work_and_selected_day', 'date' => $date,
                'modules' => array_map(fn ($section) => $section['coverage'], $sections)], $counts);
        $result['summary'] = __('Current membership and work status; reservations on :date (Europe/Zurich). Not a historical snapshot.', ['date' => $date]);

        return $result;
    }
}
