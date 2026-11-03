@if(\App\ClubUnio\Resources\ProfileUi::allows('documents', 'download'))
@include('clubunio.documents.viewer-assets')
@include('clubunio.documents.viewer-content')
@endif
