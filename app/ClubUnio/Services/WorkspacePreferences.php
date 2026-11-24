<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, SavedView, Tenant};
use Illuminate\Support\Facades\DB;

class WorkspacePreferences
{
    public function query(Account $actor, Tenant $tenant)
    {
        abort_unless(app(WorkspaceAccess::class)->allowed($actor, $tenant), 403);
        return SavedView::where('tenant_id', $tenant->id)->where('account_id', $actor->id)->where('list_key', 'workspace')->where('name', 'personal');
    }

    public function read(Account $actor, Tenant $tenant): array
    {
        $state = $this->query($actor, $tenant)->first()?->state_encrypted ?? [];
        return ['favorites' => array_values(array_filter($state['favorites'] ?? [], 'is_string')), 'recent' => array_slice($state['recent'] ?? [], 0, 12)];
    }

    private function change(Account $actor, Tenant $tenant, \Closure $change): void
    {
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $change) {
            Account::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $query = $this->query($actor, $tenant);
            $record = $query->lockForUpdate()->first() ?? new SavedView;
            $state = $record->exists ? $record->state_encrypted : ['favorites' => [], 'recent' => []];
            $record->forceFill(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'list_key' => 'workspace', 'name' => 'personal',
                'state_encrypted' => $change($state), 'is_default' => false, 'revision' => $record->exists ? $record->revision + 1 : 1])->save();
        });
    }

    public function toggle(Account $actor, Tenant $tenant, string $key): void
    {
        abort_unless(array_key_exists($key, app(WorkspaceLinks::class)->available()), 403);
        $this->change($actor, $tenant, function ($state) use ($key) {
            $keys = $state['favorites'] ?? [];
            $state['favorites'] = in_array($key, $keys, true) ? array_values(array_diff($keys, [$key])) : array_slice([...$keys, $key], -20);
            return $state;
        });
    }

    public function remember(Account $actor, Tenant $tenant, string $type, int $id): void
    {
        $resource = WorkspaceLinks::RECORDS[$type] ?? null;
        if (! $resource || ! $resource::canViewAny() || ! $resource::getEloquentQuery()->where('tenant_id',$tenant->id)->whereKey($id)->exists()) return;
        $recent = $this->read($actor, $tenant)['recent'];
        if (($recent[0]['type'] ?? null) === $type && (int) ($recent[0]['id'] ?? 0) === $id) return;
        $this->change($actor, $tenant, function ($state) use ($type, $id) {
            $rest = array_values(array_filter($state['recent'] ?? [], fn ($r) => ($r['type'] ?? null) !== $type || (int) ($r['id'] ?? 0) !== $id));
            $state['recent'] = array_slice([['type' => $type, 'id' => $id], ...$rest], 0, 12);
            return $state;
        });
    }

    public function clear(Account $actor, Tenant $tenant): void
    {
        $this->change($actor, $tenant, function ($state) { $state['recent'] = []; return $state; });
    }

    public function recent(Account $actor, Tenant $tenant): array
    {
        $rows = [];
        foreach ($this->read($actor, $tenant)['recent'] as $ref) {
            $resource = WorkspaceLinks::RECORDS[$ref['type'] ?? ''] ?? null;
            if (! $resource || ! $resource::canViewAny()) continue;
            $record = $resource::getEloquentQuery()->where('tenant_id',$tenant->id)->find((int) ($ref['id'] ?? 0));
            if (! $record || ! $resource::canView($record)) continue;
            $rows[] = ['label' => $record instanceof \App\ClubUnio\Models\Member ? $record->display_name : $resource::getModelLabel().' #'.$record->id,
                'url' => $resource::getUrl('view', ['record' => $record])];
        }
        return $rows;
    }

    public function rememberCurrent(): void
    {
        if (! request()->isMethod('GET') || ! request()->route() || ! app(WorkspaceAccess::class)->allowed(\Filament\Facades\Filament::auth()->user(), \Filament\Facades\Filament::getTenant())) return;
        foreach (WorkspaceLinks::RECORDS as $key => $resource) {
            if (request()->routeIs('filament.clubunio.resources.'.$key.'.view') && ctype_digit((string) request()->route('record'))) {
                [$actor, $tenant] = app(WorkspaceAccess::class)->context();
                $this->remember($actor, $tenant, $key, (int) request()->route('record'));
                return;
            }
        }
    }
}
