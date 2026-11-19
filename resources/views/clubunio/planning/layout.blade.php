@if(request()->attributes->get('clubunioWorkspace'))
@include('clubunio.workspace.layout', ['module' => 'planning'])
@else
@include('clubunio.portal.layout', ['portalModule' => 'planning', 'portalTitle' => __('Teamplanung · ClubUnio')])
@endif
