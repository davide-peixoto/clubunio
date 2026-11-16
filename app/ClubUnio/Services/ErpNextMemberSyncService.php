<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;

class ErpNextMemberSyncService
{
    public function __construct(private ErpNextConfiguration $configuration, private ErpNextClient $client) {}

    public function sync(Account $actor, Tenant $tenant, int $memberId, string $type): void
    {
        abort_unless(in_array($type, ['Customer', 'Supplier'], true), 422, trans('erpnext.errors.identity'));

        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $memberId, $type) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'members', 'update');
            abort_unless(ErpNextConfiguration::canManage($actor, $tenant), 403);
            AccountingProvider::require($tenant, AccountingProvider::ERPNEXT);
            $member = Member::where('tenant_id', $tenant->id)->whereNull('archived_at')->findOrFail($memberId);
            abort_if(trim($member->first_name.' '.$member->last_name) === '', 422, trans('erpnext_members.missing_name'));
            $config = $this->configuration->connection($tenant);
            abort_unless($config && ($config['origin'] ?? null) === $this->configuration->origin(), 409, trans('erpnext.errors.destination'));

            $party = $this->party($config, $tenant, $member, $type, true);
            $this->address($config, $tenant, $member, $type, $party, true);
            $this->contact($config, $tenant, $member, $type, $party);
        });
    }

    public function supplierForExpense(array $config, Tenant $tenant, Member $member): array
    {
        $supplier = $this->party($config, $tenant, $member, 'Supplier', false);
        $address = $this->address($config, $tenant, $member, 'Supplier', $supplier, false);

        return ['supplier' => $supplier, 'supplier_address' => $address];
    }

    private function party(array $config, Tenant $tenant, Member $member, string $type, bool $update): string
    {
        abort_unless((int) $member->tenant_id === (int) $tenant->id, 409, trans('erpnext.errors.identity'));
        $name = trim((string) ($member->company ?: trim($member->first_name.' '.$member->last_name)));
        abort_if($name === '', 422, trans('erpnext.errors.identity'));
        $individual = ! $member->company;
        if ($type === 'Customer') {
            $key = 'member';
            $data = ['customer_name' => $name, 'customer_type' => $individual ? 'Individual' : 'Company',
                'customer_group' => $config['customer_group'], 'territory' => $config['territory']];
        } else {
            abort_if(empty($config['supplier_group']), 409, trans('erpnext.errors.destination'));
            $key = 'supplier';
            $data = ['supplier_name' => $name, 'supplier_type' => $individual ? 'Individual' : 'Company',
                'supplier_group' => $config['supplier_group']];
        }

        return $this->upsert($config, $type, $this->configuration->reference($tenant, $key, (int) $member->id), $data, $update);
    }

    private function address(array $config, Tenant $tenant, Member $member, string $type, string $party, bool $update): ?string
    {
        if (trim((string) $member->street) === '' || trim((string) $member->postal_code) === ''
            || trim((string) $member->city) === '' || $member->country?->code !== 'CH'
            || (int) $member->country?->tenant_id !== (int) $tenant->id) {
            return null;
        }

        $key = $type === 'Customer' ? 'address' : 'supplier-address';
        $links = [['link_doctype' => $type, 'link_name' => $party]];

        return $this->upsert($config, 'Address', $this->configuration->reference($tenant, $key, (int) $member->id), [
            'address_title' => trim((string) ($member->company ?: $member->first_name.' '.$member->last_name)),
            'address_type' => 'Billing',
            'address_line1' => trim($member->street.' '.$member->house_number),
            'pincode' => (string) $member->postal_code,
            'city' => (string) $member->city,
            'country' => 'Switzerland',
        ], $update, $links);
    }

    private function contact(array $config, Tenant $tenant, Member $member, string $type, string $party): void
    {
        $firstName = trim((string) $member->first_name);
        $lastName = trim((string) $member->last_name);
        $email = trim((string) $member->email);
        $phone = trim((string) $member->mobile);
        $links = [$type => $party];
        $otherType = $type === 'Customer' ? 'Supplier' : 'Customer';
        $otherRef = $this->configuration->reference($tenant, $otherType === 'Customer' ? 'member' : 'supplier', (int) $member->id);
        $other = $this->client->find($config, $otherType, $otherRef);
        if ($other !== null) {
            $links[$otherType] = $this->verifiedName($other, $otherRef, []);
        }
        ksort($links);

        $this->upsert($config, 'Contact', $this->configuration->reference($tenant, 'member-contact', (int) $member->id), [
            'first_name' => $firstName !== '' ? $firstName : $lastName,
            'last_name' => $firstName !== '' ? $lastName : '',
            'email_ids' => $email === '' ? [] : [['email_id' => $email, 'is_primary' => 1]],
            'phone_nos' => $phone === '' ? [] : [['phone' => $phone, 'is_primary_mobile_no' => 1]],
        ], true, array_map(fn ($docType) => ['link_doctype' => $docType, 'link_name' => $links[$docType]], array_keys($links)));
    }

    private function upsert(array $config, string $type, string $reference, array $data, bool $update, array $links = []): string
    {
        $remote = $this->client->find($config, $type, $reference);
        if ($remote === null) {
            $remote = $this->client->create($config, $type, $data + ($links ? ['links' => $links] : [])
                + ['custom_clubunio_reference' => $reference]);

            return $this->verifiedName($remote, $reference, $links);
        }

        $name = $this->verifiedName($remote, $reference, $links, $type === 'Contact');
        if ($update) {
            $changes = array_filter($data, function ($value, $key) use ($remote) {
                if (is_array($value)) {
                    $current = array_map(fn ($row) => array_intersect_key($row, array_flip(array_keys($value[0] ?? []))), $remote[$key] ?? []);

                    return $current != $value;
                }

                return (string) ($remote[$key] ?? '') !== $value;
            }, ARRAY_FILTER_USE_BOTH);
            if ($type === 'Contact' && count($remote['links']) !== count($links)) {
                $changes['links'] = $links;
            }
            if ($changes !== []) {
                $remote = $this->client->update($config, $type, $name, $changes);
                abort_unless($this->verifiedName($remote, $reference, $links) === $name, 409, trans('erpnext.errors.identity'));
            }
        }

        return $name;
    }

    private function verifiedName(array $remote, string $reference, array $links, bool $allowSubset = false): string
    {
        $name = $remote['name'] ?? null;
        abort_unless(is_string($name) && $name !== '' && strlen($name) <= 140
            && ($remote['custom_clubunio_reference'] ?? null) === $reference, 409, trans('erpnext.errors.identity'));
        if ($links !== []) {
            $actual = [];
            $expected = array_column($links, 'link_name', 'link_doctype');
            foreach ($remote['links'] ?? [] as $link) {
                $type = $link['link_doctype'] ?? '';
                abort_unless(isset($expected[$type]) && ! isset($actual[$type])
                    && ($link['link_name'] ?? null) === $expected[$type], 409, trans('erpnext.errors.identity'));
                $actual[$type] = true;
            }
            abort_unless($actual && ($allowSubset || count($actual) === count($links)), 409, trans('erpnext.errors.identity'));
        }

        return $name;
    }
}
