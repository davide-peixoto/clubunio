<div class="space-y-6">
@foreach ($revisions as $revision)
    <section class="clubunio-message-section">
        <h3 class="font-semibold">{{ __('Version') }} {{ $revision->revision }} · {{ $revision->actor?->display_name ?? __('Systemvorlage') }} · {{ $revision->created_at->format('d.m.Y H:i') }} · {{ $revision->active ? __('Aktiv') : __('Inaktiv') }}</h3>
        <p class="mt-2 font-semibold">{{ $revision->title }}</p>
        <p class="clubunio-message-body">{{ $revision->body }}</p>
    </section>
@endforeach
</div>
