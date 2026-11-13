<x-filament-panels::page>
    @vite('resources/js/social-studio.js')
    <link rel="stylesheet" href="{{ asset('css/clubunio-social-list.css') }}?v=2">
    <section class="cu-social" data-social-studio data-config="{{ json_encode($this->studioConfig(), JSON_THROW_ON_ERROR) }}" wire:ignore>
        <nav class="cu-social-toolbar cu-admin-toolbar cu-social-navigation" aria-label="{{ trans('social.title') }}">
            <div class="cu-social-tabs">
            @foreach (['today', 'posts', 'templates', 'media'] as $tab)
                <button type="button" data-tab="{{ $tab }}" class="fi-tabs-item"><span class="fi-tabs-item-label">{{ trans('social.'.$tab) }}</span></button>
            @endforeach
            <button type="button" data-tab="jobs" class="fi-tabs-item" hidden><span class="fi-tabs-item-label">{{ trans('social.jobs') }}</span></button>
            </div>
            <label data-archive hidden><input type="checkbox" data-archived> {{ trans('social.archived') }}</label>
            <label data-date-label>{{ trans('social.date') }} <x-filament::input.wrapper><x-filament::input type="date" data-date /></x-filament::input.wrapper></label>
        </nav>
        <p data-message role="status" aria-live="polite" hidden></p>
        <div data-workspace aria-busy="true">{{ trans('social.loading') }}</div>
        <dialog data-studio-dialog aria-labelledby="cu-social-dialog-title">
            <header class="cu-social-toolbar">
                <h2 id="cu-social-dialog-title">{{ trans('social.editor') }}</h2>
                <span class="cu-social-spacer"></span>
                <button type="button" data-close title="{{ trans('social.close') }}" aria-label="{{ trans('social.close') }}"><x-filament::icon icon="heroicon-o-x-mark" /></button>
            </header>
            <p data-editor-error role="alert" hidden></p>
            <div class="cu-social-details" data-details></div>
            <div class="cu-social-editor">
                <aside class="cu-social-tools" data-tools></aside>
                <div class="cu-social-board" data-board tabindex="0" aria-label="{{ trans('social.preview') }}"><canvas data-canvas></canvas></div>
                <aside class="cu-social-properties" data-properties></aside>
            </div>
            <footer class="cu-social-toolbar" data-editor-footer></footer>
        </dialog>
        <template data-icon="undo"><x-filament::icon icon="heroicon-o-arrow-uturn-left" /></template>
        <template data-icon="redo"><x-filament::icon icon="heroicon-o-arrow-uturn-right" /></template>
        <template data-icon="forward"><x-filament::icon icon="heroicon-o-chevron-up" /></template>
        <template data-icon="backward"><x-filament::icon icon="heroicon-o-chevron-down" /></template>
        <template data-icon="remove"><x-filament::icon icon="heroicon-o-trash" /></template>
        <template data-icon="crop"><x-filament::icon icon="heroicon-o-scissors" /></template>
    </section>
</x-filament-panels::page>
