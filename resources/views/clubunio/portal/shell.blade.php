@php
    $portalActor = $actor ?? auth('portal')->user();
    $portalSession = auth('portal')->id() !== null && (string) auth('portal')->id() === (string) $portalActor->id;
    $access = app(\App\ClubUnio\Services\MemberAccessService::class);
    $allows = fn (string $module) => \App\ClubUnio\Resources\ProfileUi::profileAllows($portalActor, $tenant, $module, 'view');
    $club = $tenant->slug;
    $links = [];
    $moduleLinks = [];
    if ($portalSession) {
        $links[__('Verein')] = [
            ['clubunio.portal.members', 'heroicon-o-users', __('Mitglieder'), 'clubunio.portal.member.*'],
            ['clubunio.portal.announcements', 'heroicon-o-megaphone', __('Ankündigungen'), 'clubunio.portal.announcement'],
            ['clubunio.portal.sponsor-runs', 'heroicon-o-flag', __('Sponsorenlauf'), 'clubunio.portal.sponsor-run'],
        ];
        if ($access->canEnter($portalActor, $tenant) && $allows('documents')) {
            $links[__('Verein')][] = ['clubunio.portal.material.index', 'heroicon-o-cube', __('Material'), 'clubunio.portal.material.*'];
        }
        if (app(\App\ClubUnio\Services\ClubhouseAccess::class)->allows($portalActor, $tenant, 'view')) {
            $links[__('Verein')][] = ['clubunio.portal.clubhouse', 'heroicon-o-building-storefront', trans('clubhouse.title'), 'clubunio.portal.clubhouse*'];
        }
        if (in_array($access->membership($portalActor, $tenant)?->role, ['admin', 'office', 'technical_lead'], true)) {
            $links[__('Verein')][] = ['clubunio.portal.registrations', 'heroicon-o-clipboard-document-list', __('Anmeldungen'), 'clubunio.portal.registration*'];
        }
    }
    if ($portalSession || (($portalModule ?? null) === 'planning' && $allows('planning'))) {
        $links[__('Sport')][] = ['clubunio.planning.index', 'heroicon-o-clipboard-document-list', __('Teamplanung'), 'clubunio.planning.*|clubunio.training.*'];
    }
    if (($portalModule ?? null) === 'planning') {
        if ($allows('planning')) {
            $moduleLinks[] = ['clubunio.planning.calendar', 'heroicon-o-calendar-days', __('Kalender')];
            $moduleLinks[] = ['clubunio.planning.absences', 'heroicon-o-calendar', __('Absenzen')];
            if (count($managed)) {
                $moduleLinks[] = ['clubunio.planning.sfv', 'heroicon-o-trophy', __('SFV-Spiele'), 'clubunio.planning.sfv.*'];
            }
        }
        if ((count($managed) || app(\App\ClubUnio\Services\ModulePermissionService::class)->allows($portalActor, $tenant, 'training', 'view')) && $allows('training')) {
            $moduleLinks[] = ['clubunio.training.index', 'heroicon-o-academic-cap', __('Trainingspläne'), 'clubunio.training.*'];
        }
        if ($portalActor->canAccessTenant($tenant) && $allows('planning')) {
            $moduleLinks[] = ['clubunio.planning.options', 'heroicon-o-adjustments-horizontal', __('Auswahlwerte')];
        }
    }
    if ($portalSession || ($portalModule ?? null) === 'bus') {
        $links[__('Sport')][] = ['clubunio.bus.index', 'heroicon-o-truck', __('Busreservierungen'), 'clubunio.bus.index|clubunio.bus.show|clubunio.bus.create|clubunio.bus.edit|clubunio.bus.assignment'];
    }
    if (($portalModule ?? null) === 'bus' && $portalActor->canAccessTenant($tenant) && $allows('bus')) {
        $moduleLinks[] = ['clubunio.bus.options', 'heroicon-o-adjustments-horizontal', __('Busse und Status')];
    }
    if ($portalSession) {
        if ($allows('expenses')) {
            $links[__('Finanzen')][] = ['clubunio.portal.expenses', 'heroicon-o-receipt-percent', __('Spesen'), 'clubunio.portal.expense.*|clubunio.portal.expense'];
        }
        if ($allows('invoices')) {
            $links[__('Finanzen')][] = ['clubunio.portal.invoices', 'heroicon-o-document-text', __('Rechnungen'), 'clubunio.portal.invoice'];
        }
        $links[__('Finanzen')][] = ['clubunio.portal.banks', 'heroicon-o-building-library', __('Bankkonten')];
    }
@endphp
<div class="cu-portal-shell">
    <header class="cu-portal-topbar">
        <button type="button" class="icon-button cu-portal-menu-toggle" aria-controls="cu-portal-sidebar" aria-expanded="false" aria-label="{{ trans('portal-shell.navigation') }}" title="{{ trans('portal-shell.navigation') }}">@svg('heroicon-o-bars-3')</button>
        <a class="cu-portal-brand" href="{{ $portalSession ? route('clubunio.portal.members', $club) : url('/') }}">
            @include('clubunio.brand.logo')
        </a>
        <div class="cu-portal-topbar-actions">
            @include('clubunio.language-switcher')
            @if($portalSession)
                @if(config('clubunio-verein-oidc.only') && !request()->attributes->has('clubunio_impersonation'))
                    <a class="icon-button" href="{{ config('clubunio-verein-oidc.issuer').'/account/' }}" title="{{ trans('account-security.setup') }}" aria-label="{{ trans('account-security.setup') }}">@svg('heroicon-o-shield-check')</a>
                @elseif(app(\App\ClubUnio\Services\AccountSecurity::class)->enabled() && !request()->attributes->has('clubunio_impersonation'))
                    <a class="icon-button" href="{{ route('clubunio.security.show', ['mode' => 'portal']) }}" title="{{ trans('account-security.setup') }}" aria-label="{{ trans('account-security.setup') }}">@svg('heroicon-o-shield-check')</a>
                @endif
                <a class="icon-button" href="{{ route('clubunio.portal.notifications', $club) }}" title="{{ __('Benachrichtigungen') }}" aria-label="{{ __('Benachrichtigungen') }}" @if(request()->routeIs('clubunio.portal.notifications')) aria-current="page" @endif>@svg('heroicon-o-bell')</a>
                <form method="post" action="{{ route('clubunio.portal.logout') }}">@csrf
                    <button class="icon-button" type="submit" title="{{ __('Abmelden') }}" aria-label="{{ __('Abmelden') }}">@svg('heroicon-o-arrow-right-on-rectangle')</button>
                </form>
            @endif
        </div>
    </header>
    <aside class="cu-portal-sidebar" id="cu-portal-sidebar">
        <div class="cu-portal-context">
            @if($portalSession && isset($tenants) && $tenants->count() > 1)
                <details class="cu-portal-clubs">
                    <summary>{{ $tenant->name }} @svg('heroicon-o-chevron-down')</summary>
                    @foreach($tenants as $availableTenant)
                        <a href="{{ route('clubunio.portal.members', $availableTenant->slug) }}" @if($availableTenant->id === $tenant->id) aria-current="true" @endif>{{ $availableTenant->name }}</a>
                    @endforeach
                </details>
            @else
                <strong>{{ $tenant->name }}</strong>
            @endif
            <span>{{ $portalActor->display_name }}</span>
        </div>
        <nav class="cu-portal-navigation" aria-label="{{ trans('portal-shell.navigation') }}">
            @foreach($links as $group => $items)
                <div class="cu-portal-group">
                    <p>{{ $group }}</p>
                    @foreach($items as $item)
                        @php
                            [$route, $icon, $label] = $item;
                            $patterns = explode('|', $item[3] ?? $route);
                        @endphp
                        <a href="{{ route($route, $club) }}" @if(request()->routeIs($route)) aria-current="page" @elseif(request()->routeIs(...$patterns)) aria-current="location" @endif>@svg($icon) <span>{{ $label }}</span></a>
                    @endforeach
                </div>
            @endforeach
            @if($portalSession && config('clubunio-support.enabled'))
                <a href="{{ route('clubunio.support.center', ['club' => $tenant->id]) }}">@svg('heroicon-o-question-mark-circle') <span>{{ trans('support.title') }}</span></a>
            @endif
        </nav>
    </aside>
    <main class="cu-portal-content">
        @if($moduleLinks !== [])
            <nav class="cu-portal-module-nav" aria-label="{{ ($portalModule ?? null) === 'bus' ? __('Busreservierungen') : __('Teamplanung') }}">
                @foreach($moduleLinks as $item)
                    @php([$route, $icon, $label] = $item)
                    <a href="{{ route($route, $club) }}" @if(request()->routeIs($route)) aria-current="page" @elseif(request()->routeIs($item[3] ?? $route)) aria-current="location" @endif>@svg($icon) {{ $label }}</a>
                @endforeach
            </nav>
        @endif
        @isset($portalModule)
            @if(session('notice'))<div role="status" class="notice">{{ session('notice') }}</div>@endif
            @if($errors->any())<div role="alert" class="errors"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @endisset
        @yield('content')
    </main>
</div>
