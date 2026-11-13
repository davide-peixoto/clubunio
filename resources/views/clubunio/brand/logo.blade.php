@php($domainBrand = app(\App\ClubUnio\Services\TenantDomainService::class)->brand())
<div class="clubunio-brand-lockup">
    <img class="clubunio-brand-logo" src="{{ $domainBrand['logo'] ?? asset('brand/blue-v1/clubunio-logo-horizontal.svg') }}" alt="{{ $domainBrand['name'] ?? __('ClubUnio') }}" width="180" height="50">
    @include('clubunio.brand.attribution')
</div>
