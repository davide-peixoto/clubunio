<dl class="cu-module-stats" @if(!empty($scope)) aria-label="{{ $scope }}" @endif>
@foreach($metrics as $metric)
<div @if(!empty($metric['tone'])) data-tone="{{ $metric['tone'] }}" @endif><dt>{{ $metric['label'] }}</dt><dd>{{ $metric['value'] }}</dd></div>
@endforeach
</dl>
@if(!empty($scope))<p class="cu-module-note">{{ $scope }}</p>@endif
