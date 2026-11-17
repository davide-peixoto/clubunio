@if(request()->attributes->get('clubunioWorkspace'))
@include('clubunio.workspace.layout', ['module' => 'bus'])
@else
@include('clubunio.portal.layout', ['portalModule' => 'bus', 'portalTitle' => __('Busreservierungen · ClubUnio')])
@endif
