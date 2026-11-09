<!DOCTYPE html>
<html lang="es"><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><title>{{ $title }}</title>
<style>
    @page { margin: 25mm 14mm 22mm; }
    body { font-family: DejaVu Sans, sans-serif; color: #263d31; font-size: 8pt; line-height: 1.45; }
    .pdf-header { position: fixed; top: -18mm; left: 0; right: 0; height: 16mm; border-bottom: 1px solid #b9cbbb; }
    .pdf-header table { width: 100%; border-collapse: collapse; }
    .pdf-header img { width: 29mm; height: 16mm; }
    .pdf-header td { border: 0; padding: 0; vertical-align: middle; }
    .pdf-brand { font-size: 8pt; color: #637363; text-align: right; }
    .pdf-footer { position: fixed; bottom: -15mm; left: 0; right: 0; border-top: 1px solid #cdd8cc; padding-top: 3mm; color: #657662; font-size: 7pt; }
    h1 { font-size: 18pt; color: #24523a; margin: 0 0 2mm; } h2 { font-size: 11pt; margin: 6mm 0 3mm; color: #34583a; page-break-after: avoid; }
    p { margin: 0 0 3mm; }.pdf-subtitle { color: #687b64; margin-bottom: 4mm; }.pdf-meta { font-size: 7pt; color: #687b64; }
    .pdf-filters { background: #f3f6ef; border: 1px solid #dce5d5; padding: 3mm; margin: 4mm 0; }.pdf-filters p { margin: 0 0 1mm; }
    .pdf-notice { border-left: 2px solid #9fb28b; padding-left: 3mm; color: #5c6f53; margin: 3mm 0; }
    .pdf-summary { width: 100%; border-collapse: collapse; margin: 4mm 0; }.pdf-summary td { border: 1px solid #d5e1cc; width: 25%; padding: 3mm; vertical-align: top; font-size: 7pt; }.pdf-summary strong { display: block; font-size: 13pt; color: #33583a; }
    .pdf-table { border-collapse: collapse; width: 100%; table-layout: fixed; margin-top: 4mm; font-size: 7pt; }.pdf-table thead { display: table-header-group; }.pdf-table th { background: #eaf0e3; color: #385739; font-size: 7pt; text-align: left; }.pdf-table th,.pdf-table td { border: 1px solid #cbd7c3; padding: 2mm; vertical-align: top; overflow-wrap: break-word; word-wrap: break-word; }.pdf-table small { display: block; font-size: 6pt; font-weight: normal; }.pdf-table tr { page-break-inside: avoid; }
    .pdf-number { text-align: right; }.report-unknown { color: #7b8773; }
    .pdf-field { margin: 0 0 3mm; border-bottom: 1px solid #e1e8db; padding-bottom: 2mm; overflow-wrap: break-word; word-wrap: break-word; }.pdf-field dt { color: #66785e; font-size: 7pt; margin-bottom: 1mm; page-break-after: avoid; }.pdf-field dd { margin: 0; white-space: pre-wrap; orphans: 2; widows: 2; }.pdf-empty { padding: 5mm; background: #f4f7f0; color: #64745b; }
</style></head><body>
    <header class="pdf-header"><table><tr><td>@if ($logoDataUri)<img src="{{ $logoDataUri }}" alt="SYSFOREST">@else<strong>SYSFOREST</strong>@endif</td><td class="pdf-brand">{{ $isPublic ? 'CONSULTA CIUDADANA · INFORMACIÓN PUBLICADA' : 'INVENTARIO FORESTAL INSTITUCIONAL' }}<br>{{ $scopeLabel }}</td></tr></table></header>
    <footer class="pdf-footer">SYSFOREST · Generado (Bogotá): {{ \App\Support\Time\BogotaDateTime::format($generatedAt, 'd/m/Y H:i:s') }}</footer>
    <h1>{{ $title }}</h1><p class="pdf-subtitle">{{ $scopeLabel }}</p>
    <p class="pdf-meta">{{ $isPublic ? 'Información publicada. Mediciones de la última inspección publicada, cuando corresponde.' : 'Mediciones de la última inspección, cuando corresponde.' }} Los valores conservan la precisión registrada.</p>
    @if (count($appliedFilters))<section class="pdf-filters">@foreach ($appliedFilters as $filter)<p><strong>{{ $filter['label'] }}:</strong> {{ $filter['value'] }}</p>@endforeach</section>@endif
    @foreach ($notices as $notice)<p class="pdf-notice">{{ $notice }}</p>@endforeach
    @yield('report')
</body></html>
