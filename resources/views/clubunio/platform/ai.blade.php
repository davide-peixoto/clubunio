@extends('clubunio.platform.layout')
@section('content')
<div class="platform-heading"><h1>{{ trans('tenant_ai.title') }}: {{ $tenant->name }}</h1><a class="icon-button" href="{{ route('clubunio.platform.show',$tenant->id) }}" title="{{ trans('tenant_ai.actions.back') }}" aria-label="{{ trans('tenant_ai.actions.back') }}">@svg('heroicon-o-arrow-left')</a></div>
<section class="platform-section">
<form method="post" class="platform-form">@csrf
<input type="hidden" name="revision" value="{{ $configuration['revision'] }}">
<label>{{ trans('tenant_ai.fields.project_id') }}<input name="project_id" value="{{ $configuration['project_id'] }}" maxlength="100"></label>
<label>{{ trans('tenant_ai.fields.api_key') }}<input name="api_key" type="password" autocomplete="new-password" maxlength="1024"></label>
<label>{{ trans('tenant_ai.fields.monthly_limit_usd') }}<input name="monthly_limit_usd" type="number" min="0.01" max="1000" step="0.01" value="{{ $configuration['monthly_limit_microusd']/1000000 }}" required></label>
<label><input name="privacy_reviewed" type="checkbox" value="1" @checked($configuration['privacy_reviewed'])>{{ trans('tenant_ai.fields.privacy_reviewed') }}</label>
<label><input name="enabled" type="checkbox" value="1" @checked($configuration['enabled'])>{{ trans('tenant_ai.fields.enabled') }}</label>
<div class="platform-actions"><button name="operation" value="save" class="primary">@svg('heroicon-o-check') {{ trans('tenant_ai.actions.configure') }}</button></div>
</form>
<form method="post">@csrf<button name="operation" value="verify">@svg('heroicon-o-check-badge') {{ trans('tenant_ai.actions.verify') }}</button></form>
</section>
@endsection
