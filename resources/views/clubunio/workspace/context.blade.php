@if(request()->attributes->get('clubunioWorkspace'))
<input type="hidden" name="_workspace" value="{{ request()->attributes->get('workspaceToken') }}">
@endif
