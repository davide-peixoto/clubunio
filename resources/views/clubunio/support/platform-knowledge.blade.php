<div class="sp-knowledge">
    <section class="sp-reading" aria-labelledby="sp-library-title">
        <h2 id="sp-library-title">{{ trans('support.workspace.knowledge') }}</h2>
        <form method="get" action="{{ $inbox }}" class="sp-inline">
            <input type="hidden" name="tab" value="knowledge">
            <label>{{ trans('support-wiki.surface') }}<select name="surface" onchange="this.form.submit()"><option value="web" @selected($surface === 'web')>{{ trans('support-wiki.web') }}</option><option value="app" @selected($surface === 'app')>{{ trans('support-wiki.app') }}</option></select></label><label>{{ trans('support.search') }}<input type="search" name="q" maxlength="120" value="{{ request('q') }}"></label>
            <button class="icon-button" title="{{ trans('support.search') }}" aria-label="{{ trans('support.search') }}">@svg('heroicon-o-magnifying-glass')</button>
            @if(request()->filled('q'))<a class="icon-button" href="{{ route('clubunio.platform.support', ['tab' => 'knowledge', 'surface' => $surface]) }}" title="{{ trans('support.workspace.clear_filters') }}" aria-label="{{ trans('support.workspace.clear_filters') }}">@svg('heroicon-o-x-mark')</a>@endif
        </form>
        <div class="sp-articles">
            @foreach($faq as $key => $item)
                <details class="sp-disclosure" id="faq-{{ $key }}"><summary>@svg('heroicon-o-document-text') {{ $item['title'] }}</summary>@include('clubunio.support.guide', ['item' => $item])</details>
            @endforeach
            @foreach($articles as $item)
                <details class="sp-disclosure" id="article-{{ $item->id }}">
                    <summary>@svg('heroicon-o-document-text') {{ $item->title }}</summary><p>{{ $item->body }}</p>
                    @if($item->video_url)<a class="sp-file" href="{{ $item->video_url }}" target="_blank" rel="noopener noreferrer">@svg('heroicon-o-play-circle') {{ trans('support.video') }}</a>@endif
                </details>
            @endforeach
            @if(!$faq && $articles->isEmpty())<p class="sp-empty">{{ trans('support.workspace.no_articles') }}</p>@endif
        </div>
    </section>
    <section class="sp-reading" aria-labelledby="sp-editor-title">
        <h2 id="sp-editor-title">{{ trans('support.workspace.editorial') }}</h2>
        @foreach(collect([null])->concat($editableArticles) as $edit)
            @include('clubunio.support.article-edit')
        @endforeach
    </section>
</div>
