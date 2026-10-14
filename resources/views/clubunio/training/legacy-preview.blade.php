<div class="space-y-6">
<h3 class="text-lg font-semibold">{{ $row->title ?? 'Kein fertiger Trainingsplan' }}</h3>
<p>{{ $row->source_team_name }}</p>
@if($row->state==='excluded')<p>Dieser Eintrag ist ein Fehler-/Zwischenstand oder erfüllt den geprüften Inhaltsvertrag nicht.</p>@endif
@foreach(($row->plan_json['exercises']??[]) as $exercise)
<section><h4 class="font-semibold">{{ $exercise['phase'] }} · {{ $exercise['title'] }} · {{ $exercise['durationMinutes'] }} Min.</h4>
<p>{{ $exercise['setup'] }}</p><p>{{ $exercise['flow'] }}</p><p>{{ $exercise['diagramDescription'] }}</p></section>
@endforeach
<p>Private Archivübernahme. Original-SVG wird nicht ausgeführt; Grafiken bleiben schematisch.</p>
</div>
