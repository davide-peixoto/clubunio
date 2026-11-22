<div class="table-scroll"><table class="cu-admin-journal w-full text-sm"><thead><tr><th class="p-2 text-left">{{ __('Feld') }}</th><th class="p-2 text-left">{{ __('Bisher') }}</th><th class="p-2 text-left">{{ __('Beantragt') }}</th></tr></thead><tbody>
@foreach($rows as $row)<tr><td class="p-2">{{ $row['field'] }}</td><td class="p-2">{{ $row['before'] ?? '–' }}</td><td class="p-2">{{ $row['after'] ?? '–' }}</td></tr>@endforeach
</tbody></table></div>
@if($note)<p class="mt-4">{{ $note }}</p>@endif
