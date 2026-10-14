@if ($paginator->hasPages())
<nav class="planning-pagination" aria-label="Seitennavigation">
    <p>{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} von {{ $paginator->total() }}</p>
    <div class="planning-pagination-pages">
        @if ($paginator->onFirstPage())
            <span class="planning-page disabled" aria-disabled="true" aria-label="Vorherige Seite">@svg('heroicon-o-chevron-left')</span>
        @else
            <a class="planning-page" href="{{ $paginator->previousPageUrl() }}" rel="prev" title="Vorherige Seite" aria-label="Vorherige Seite">@svg('heroicon-o-chevron-left')</a>
        @endif
        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="planning-page-gap">{{ $element }}</span>
            @else
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="planning-page" aria-current="page" aria-label="Seite {{ $page }}">{{ $page }}</span>
                    @else
                        <a class="planning-page" href="{{ $url }}" aria-label="Seite {{ $page }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach
        @if ($paginator->hasMorePages())
            <a class="planning-page" href="{{ $paginator->nextPageUrl() }}" rel="next" title="Nächste Seite" aria-label="Nächste Seite">@svg('heroicon-o-chevron-right')</a>
        @else
            <span class="planning-page disabled" aria-disabled="true" aria-label="Nächste Seite">@svg('heroicon-o-chevron-right')</span>
        @endif
    </div>
</nav>
@endif
