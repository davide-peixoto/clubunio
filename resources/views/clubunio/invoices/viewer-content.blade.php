@php
    $pdfActor = auth('clubunio')->user();
    $pdfTenant = $tenant ?? request()->route('tenant') ?? \Filament\Facades\Filament::getTenant();
    $canReadPdf = ! $pdfActor || ($pdfTenant instanceof \App\ClubUnio\Models\Tenant
        && \App\ClubUnio\Resources\ProfileUi::profileAllows($pdfActor, $pdfTenant, 'invoices', 'download'));
@endphp
@if($canReadPdf)
@include('clubunio.documents.viewer-content', ['mediaType' => 'application/pdf', 'downloadLabel' => __('PDF herunterladen')])
@endif
