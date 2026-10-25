@php($domainBrand = app(\App\ClubUnio\Services\TenantDomainService::class)->brand())
<img class="clubunio-brand-logo" src="{{ $domainBrand['logo'] ?? asset('brand/blue-v1/clubunio-logo-horizontal.svg') }}" alt="{{ $domainBrand['name'] ?? 'ClubUnio' }}" width="180" height="50">
