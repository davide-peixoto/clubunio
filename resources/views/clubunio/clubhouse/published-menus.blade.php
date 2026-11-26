<ul class="cu-ch-menu-list">@forelse($publishedMenus as $menu)
    <li><header><h3>{{ $menu['title'] }}</h3><span>{{ $menu['valid_from'] }} – {{ $menu['valid_until'] }}</span></header>
        @if($menu['body'])<p class="cu-ch-menu-text">{{ $menu['body'] }}</p>@endif
        @if($menu['language_fallback'])<small>{{ trans('clubhouse.language_fallback') }}</small>@endif
        @if($menu['has_document'])<a class="cu-ch-link" href="{{ $publicView ? route('clubunio.clubhouse.public.pdf', ['tenant' => $tenant->slug, 'menu' => $menu['id']]) : ($guard === 'portal' ? route('clubunio.portal.clubhouse.pdf', ['tenant' => $tenant->slug, 'menu' => $menu['id'], 'channel' => $previewChannel]) : route('clubunio.clubhouse.pdf', ['menu' => $menu['id'], 'channel' => $previewChannel])) }}" target="_blank" rel="noopener">@svg('heroicon-o-document-arrow-down') {{ trans('clubhouse.open_pdf') }}</a>@endif
    </li>
@empty<li class="cu-ch-empty">{{ trans('clubhouse.no_current_menus') }}</li>@endforelse</ul>
