<section class="space-y-4">
    <p><strong>Interne Benachrichtigungen: {{ $count }} Empfänger</strong></p>
    <h3 class="text-lg font-semibold break-words">{{ $title }}</h3>
    <p class="whitespace-pre-wrap break-words">{{ $body }}</p>
    @if($names)<details><summary>Empfängervorschau (maximal 20)</summary><ul>@foreach($names as $name)<li>{{ $name }}</li>@endforeach</ul></details>@endif
</section>
