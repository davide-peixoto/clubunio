<div class="table-scroll"><table class="w-full text-sm"><thead><tr><th class="p-2 text-left">Feld</th><th class="p-2 text-left">Bisher</th><th class="p-2 text-left">Beantragt</th></tr></thead><tbody>
@foreach($rows as $row)<tr><td class="p-2">{{ $row['field'] }}</td><td class="p-2">{{ $row['before'] ?? '–' }}</td><td class="p-2">{{ $row['after'] ?? '–' }}</td></tr>@endforeach
</tbody></table></div>
@if($note)<p class="mt-4">{{ $note }}</p>@endif
