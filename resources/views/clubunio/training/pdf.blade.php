<!doctype html><html lang="de"><head><meta charset="utf-8"><style>
@page{margin:32px}body{font-family:"DejaVu Sans",sans-serif;font-size:10pt;color:#25342f}h1{font-size:19pt}h2{font-size:15pt}h3{font-size:12pt}h4{margin-bottom:4px}p{margin-top:4px}.training-exercise{border-top:1px solid #bbc7c2;padding-top:10px}h3,h4{page-break-after:avoid}.pre-line{white-space:pre-line}footer{font-size:8pt;color:#5d6863}
.training-diagram{width:400px;height:auto;page-break-inside:avoid}
</style></head><body><header><strong>{{ $tenant->name }}</strong><p>{{ $row->input['teamName'] }} · {{ $row->input['durationMinutes'] }} Minuten · {{ $row->input['playerCount'] }} Spieler</p></header>
@include('clubunio.training.content',['content'=>$row->plan])
@if($row->legacy_source_id ?? false)<p>Übernommener Altplan: schematische Grafiken, keine Originalgrafiken.</p>@endif
@if($row->legacy_quality_pending ?? false)<p>Aktuelle SV-Sissach-/SFV-Qualitätsprüfung ausstehend. Privater Prüfstand.</p>@endif
<footer>Trainingsplan {{ $row->id }} · Revision {{ $row->revision }}</footer></body></html>
