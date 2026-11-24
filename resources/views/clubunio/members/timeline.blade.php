<div class="cu-workflow">
<p class="cu-module-note">{{ trans('workspace.timeline_scope',['sources'=>implode(', ',$timeline['sources'])]) }}</p>
<ol class="cu-member-timeline">@forelse($timeline['rows'] as $entry)<li><time>{{ $entry['at']->tz('Europe/Zurich')->format('d.m.Y H:i') }}</time><span>{{ $entry['kind'] }}</span><h3>{{ $entry['title'] }}</h3><p>{{ $entry['detail'] }}</p>@if($entry['url'])<a href="{{ $entry['url'] }}">{{ trans('workspace.open') }} ↗</a>@endif</li>@empty<li>{{ trans('workspace.empty') }}</li>@endforelse</ol>
</div>
