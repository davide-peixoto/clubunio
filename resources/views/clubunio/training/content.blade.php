<h2>{{ $content['title'] }}</h2><p>{{ $content['focus'] }}</p>
<ul>@foreach($content['principles'] as $value)<li>{{ $value }}</li>@endforeach</ul>
@foreach($content['exercises'] as $exercise)
<section class="training-exercise"><h3>{{ $exercise['phase'] }} · {{ $exercise['title'] }} · {{ $exercise['durationMinutes'] }} Min.</h3>
<img class="training-diagram" src="{{ app(\App\ClubUnio\Services\TrainingPlanContent::class)->diagram($exercise) }}" alt="{{ $exercise['diagramDescription'] }}" width="640" height="390">
<h4>Aufbau</h4><p class="pre-line">{{ $exercise['setup'] }}</p><h4>Ablauf</h4><p class="pre-line">{{ $exercise['flow'] }}</p>
<h4>Coaching</h4><ul>@foreach($exercise['coachingPoints'] as $value)<li>{{ $value }}</li>@endforeach</ul>
<p><strong>Einfacher:</strong> {{ $exercise['easier'] }}</p><p><strong>Schwieriger:</strong> {{ $exercise['harder'] }}</p><p><strong>Spielfeldaufteilung:</strong> {{ $exercise['diagramDescription'] }}</p></section>
@endforeach
<h3>Qualitätsprüfung</h3><ul>@foreach($content['qualityChecks'] as $value)<li>{{ $value }}</li>@endforeach</ul>
