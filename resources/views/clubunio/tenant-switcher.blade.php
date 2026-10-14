@php
    $actor = auth('clubunio')->user();
    $active = \Filament\Facades\Filament::getTenant();
    $tenants = $actor?->getTenants(\Filament\Facades\Filament::getPanel('clubunio')) ?? collect();
    $context = $actor ? session(app(\App\ClubUnio\Services\ActiveTenant::class)->key(request())) : null;
@endphp
@if($active && $context)
    @if(count($tenants) > 1)
    <form method="post" action="{{ route('clubunio.tenant.switch') }}" class="px-4 py-2">
        @csrf
        <input type="hidden" name="revision" value="{{ $context['revision'] }}">
        <label for="active-club" class="sr-only">Verein</label>
        <select id="active-club" name="tenant" onchange="this.form.requestSubmit()" class="w-full rounded border border-gray-300 p-2 text-sm dark:bg-gray-900">
            @foreach($tenants as $tenant)<option value="{{ $tenant->id }}" @selected($tenant->id === $active->id)>{{ $tenant->name }}</option>@endforeach
        </select>
        <noscript><button type="submit">Verein wechseln</button></noscript>
    </form>
    @else
    <div class="px-4 py-2 text-sm">{{ $active->name }}</div>
    @endif
@endif
