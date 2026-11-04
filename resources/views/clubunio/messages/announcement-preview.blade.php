<section class="space-y-4">
    <p><strong>{{ __('Interne Benachrichtigungen:') }} {{ $count }} {{ __('Empfänger') }}</strong></p>
    @foreach ($variants ?? [['locale' => app()->getLocale(), 'title' => $title, 'body' => $body]] as $variant)
    <section lang="{{ str_replace('_', '-', $variant['locale']) }}" class="space-y-2">
        <h2 class="font-semibold" lang="{{ str_replace('_', '-', app()->getLocale()) }}">{{ __('Sprache') }}: <span lang="{{ str_replace('_', '-', $variant['locale']) }}">{{ config('clubunio-languages.enabled')[$variant['locale']] ?? $variant['locale'] }}</span></h2>
        <h3 class="text-lg font-semibold break-words">{{ $variant['title'] }}</h3>
        <p class="whitespace-pre-wrap break-words">{{ $variant['body'] }}</p>
    </section>
    @endforeach
    @if($names)<details><summary>{{ __('Empfängervorschau (maximal 20)') }}</summary><ul>@foreach($names as $name)<li>{{ $name }}</li>@endforeach</ul></details>@endif
</section>
