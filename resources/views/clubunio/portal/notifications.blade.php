@extends('clubunio.portal.layout')
@section('content')
<h1>Benachrichtigungen <small>({{ $unread }} ungelesen)</small></h1>
<form method="get" class="toolbar"><label><input type="checkbox" name="unreadOnly" value="1" @checked(request()->boolean('unreadOnly'))> Nur ungelesene</label><button type="submit" class="icon-button" title="Filtern" aria-label="Filtern">@svg('heroicon-o-funnel')</button></form>
<form method="post" action="{{ route('clubunio.portal.notifications.read-all', ['tenant'=>$tenant->slug]) }}">@csrf<button type="submit" class="primary">Alle als gelesen markieren</button></form>
@forelse($notifications as $notification)
<article class="notification-row">
    <h2>{{ $notification->title }}</h2><p>{{ $notification->body }}</p><time>{{ $notification->created_at }}</time>
    @if($notification->object_type === 'team_event')<a href="{{ route('clubunio.planning.show',[$tenant->slug,$notification->object_id]) }}">Termin öffnen</a>@endif
    @if($notification->object_type === 'bus_reservation' && app(\App\ClubUnio\Services\BusReservationService::class)->visible(auth('portal')->user(),$tenant)->where('id',$notification->object_id)->exists())<a href="{{ route('clubunio.bus.show',[$tenant->slug,$notification->object_id]) }}">Reservation öffnen</a>@endif
    @if(!$notification->read_at)<form method="post" action="{{ route('clubunio.portal.notifications.read', ['tenant'=>$tenant->slug, 'notification'=>$notification->id]) }}">@csrf<button type="submit" class="icon-button" title="Als gelesen markieren" aria-label="Als gelesen markieren">@svg('heroicon-o-envelope-open')</button></form>@endif
    @if($notification->object_type === 'registration' && app(\App\ClubUnio\Services\RegistrationReviewService::class)->visible(auth('portal')->user(), $tenant)->whereKey($notification->object_id)->exists())
        <a href="{{ route('clubunio.portal.registration', ['tenant'=>$tenant->slug, 'registration'=>$notification->object_id]) }}">Anmeldung öffnen</a>
    @endif
    @if($notification->object_type === 'expense' && app(\App\ClubUnio\Services\ExpenseService::class)->visible(auth('portal')->user(), $tenant)->whereKey($notification->object_id)->exists())
        <a href="{{ route('clubunio.portal.expense', ['tenant'=>$tenant->slug, 'expense'=>$notification->object_id]) }}">Spesenbeleg öffnen</a>
    @endif
</article>
@empty<p>Keine Benachrichtigungen vorhanden.</p>@endforelse
<div class="pagination">{{ $notifications->links('pagination::simple-default') }}</div>
@endsection
