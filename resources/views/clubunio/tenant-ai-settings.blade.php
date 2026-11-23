<x-filament-panels::page>
    @pushOnce('styles')
        <link rel="stylesheet" href="{{ asset('css/clubunio-tenant-ai.css') }}?v=20261123-2">
    @endPushOnce
    <div class="cu-tenant-ai">
    <x-filament::section :heading="trans('tenant_ai.sections.configuration')">
        <div class="space-y-4">
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach(['mode', 'project_id', 'key_present', 'verified', 'enabled', 'privacy_reviewed', 'monthly_limit_microusd', 'revision'] as $field)
                    <div class="min-w-0">
                        <dt class="text-sm text-gray-500 dark:text-gray-400">{{ trans('tenant_ai.fields.'.($field === 'monthly_limit_microusd' ? 'monthly_limit_usd' : $field)) }}</dt>
                        <dd class="break-words text-sm font-medium">
                            @if(in_array($field, ['key_present', 'verified', 'enabled', 'privacy_reviewed'], true))
                                {{ trans('tenant_ai.'.(!empty($configuration[$field]) ? 'yes' : 'no')) }}
                            @elseif($field === 'mode')
                                {{ trans('tenant_ai.modes.'.(in_array($configuration['mode'] ?? '', ['own', 'clubunio'], true) ? $configuration['mode'] : 'unknown')) }}
                            @elseif($field === 'monthly_limit_microusd')
                                {{ number_format(($configuration[$field] ?? 0) / 1000000, ($configuration[$field] ?? 0) < 10000 ? 6 : 2, '.', '') }}
                            @else
                                {{ $configuration[$field] ?? trans('tenant_ai.not_available') }}
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
            <div class="flex flex-wrap gap-2">
                {{ $this->configureAction }}
                {{ $this->verifyAction }}
            </div>
        </div>
    </x-filament::section>

    <x-filament::section :heading="trans('tenant_ai.sections.storage')">
        <div class="space-y-4">
            <div class="flex flex-wrap gap-2">
                {{ $this->createStoreAction }}
                {{ $this->attachStoreAction }}
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">{{ trans('tenant_ai.sections.storage') }}</caption>
                    <thead><tr>
                        @foreach(['name', 'purpose', 'remote_id', 'state', 'usage_bytes', 'actions'] as $field)
                            <th scope="col" class="px-3 py-2">{{ trans('tenant_ai.fields.'.$field) }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse($stores as $store)
                            <tr wire:key="ai-store-{{ $store['id'] }}">
                                <td class="px-3 py-2 break-words">{{ $store['name'] }}</td>
                                <td class="px-3 py-2">{{ in_array($store['purpose'] ?? '', ['training', 'club'], true) ? trans('tenant_ai.purposes.'.$store['purpose']) : trans('tenant_ai.not_available') }}</td>
                                <td class="px-3 py-2 break-all">{{ $store['remote_id'] ?? trans('tenant_ai.not_available') }}</td>
                                <td class="px-3 py-2"><x-filament::badge>{{ $this->stateLabel($store['state'] ?? null) }}</x-filament::badge></td>
                                <td class="px-3 py-2">{{ number_format((int) ($store['usage_bytes'] ?? 0)) }}</td>
                                <td class="px-3 py-2">{{ ($this->removeStoreAction)(['id' => (int) $store['id']]) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-4">{{ trans('tenant_ai.empty.stores') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section :heading="trans('tenant_ai.sections.index')">
        <div class="space-y-4">
            {{ $this->uploadAction }}
            @unless($privateUploads)
                <p role="alert" class="text-sm">{{ trans('tenant_ai.private_upload_required') }}</p>
            @endunless
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">{{ trans('tenant_ai.sections.index') }}</caption>
                    <thead><tr>
                        @foreach(['name', 'store', 'state', 'error_code', 'size_bytes', 'actions'] as $field)
                            <th scope="col" class="px-3 py-2">{{ trans('tenant_ai.fields.'.$field) }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse($documents as $document)
                            <tr wire:key="ai-document-{{ $document['id'] }}">
                                <td class="px-3 py-2 break-words">{{ $document['name'] }}</td>
                                <td class="px-3 py-2 break-words">{{ $document['store_name'] }}</td>
                                <td class="px-3 py-2"><x-filament::badge>{{ $this->stateLabel($document['state'] ?? null) }}</x-filament::badge></td>
                                <td class="px-3 py-2 break-all">{{ $document['error_code'] ?? trans('tenant_ai.not_available') }}</td>
                                <td class="px-3 py-2">{{ number_format((int) ($document['size_bytes'] ?? 0)) }}</td>
                                <td class="px-3 py-2">
                                    <div class="flex flex-wrap gap-2">
                                        <x-filament::icon-button tag="a" icon="heroicon-o-arrow-down-tray"
                                            :label="trans('tenant_ai.actions.download')" :tooltip="trans('tenant_ai.actions.download')"
                                            :href="route('clubunio.ai.documents.download', ['tenant' => $tenant, 'document' => (int) $document['id']])" />
                                        {{ ($this->refreshDocumentAction)(['id' => (int) $document['id']]) }}
                                        {{ ($this->removeDocumentAction)(['id' => (int) $document['id']]) }}
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-4">{{ trans('tenant_ai.empty.documents') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section :heading="trans('tenant_ai.sections.usage')">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">{{ trans('tenant_ai.sections.usage') }}</caption>
                <thead><tr>
                    @foreach(['task', 'mode', 'requests', 'cost_microusd', 'search_calls'] as $field)
                        <th scope="col" class="px-3 py-2">{{ trans('tenant_ai.fields.'.($field === 'cost_microusd' ? 'cost_usd' : $field)) }}</th>
                    @endforeach
                </tr></thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse($usage as $row)
                        <tr>
                            @foreach(['task', 'mode', 'requests', 'cost_microusd', 'search_calls'] as $field)
                                <td class="px-3 py-2">
                                    @if($field === 'mode')
                                        {{ trans('tenant_ai.modes.'.(in_array($row['mode'] ?? '', ['own', 'clubunio'], true) ? $row['mode'] : 'unknown')) }}
                                    @elseif($field === 'task')
                                        {{ trans('tenant_ai.tasks.'.(in_array($row['task'] ?? '', ['training', 'assistant', 'mobile'], true) ? $row['task'] : 'unknown')) }}
                                    @elseif($field === 'cost_microusd')
                                        {{ number_format(($row[$field] ?? 0) / 1000000, 6, '.', '') }}
                                    @else
                                        {{ $row[$field] ?? trans('tenant_ai.not_available') }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-4">{{ trans('tenant_ai.empty.usage') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
    </div>
</x-filament-panels::page>
