@extends('layouts.institutional')
@section('title', 'Gestión del riesgo')
@include('forestry.assets')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/maps.css') }}">
    <link rel="stylesheet" href="{{ asset('css/reports.css') }}">
    <link rel="stylesheet" href="{{ asset('css/risk.css') }}">
@endpush
@push('scripts')<script type="module" src="{{ asset('js/risk/filters.js') }}"></script>@endpush
@section('content')
<section class="general-map risk-workspace" data-risk-page>
    <header class="page-heading forestry-heading"><div><p class="eyebrow">{{ $organization->name }}</p><h1>Gestión del riesgo</h1><p class="muted">Consulta cada árbol y la prioridad fitosanitaria de su última inspección.</p></div></header>
    @include('risk.navigation')
    <p class="notice">La prioridad profesional y la sugerencia automática se muestran por separado. Esta prioridad orienta la gestión; no expresa probabilidad de caída.</p>
    @if (($currentAccessMode ?? '') === 'department_read')<p class="forestry-readonly">Consulta departamental de la institución seleccionada. Los registros se muestran en modo de lectura.</p>@endif
    <section class="risk-counts" aria-label="Resumen de árboles por prioridad">
        <div class="risk-count risk-count-total"><span>Árboles seleccionados</span><strong>{{ number_format($summary['totalTrees'], 0, ',', '.') }}</strong></div>
        @foreach ($riskSummary as $group)<a class="risk-count risk-count-{{ $group['key'] }}" href="{{ $group['url'] }}"><span>{{ $group['label'] }}</span><strong>{{ number_format($group['total'], 0, ',', '.') }}</strong></a>@endforeach
    </section>
    <p class="field-hint">Los conteos corresponden a los filtros aplicados sobre la última inspección de cada árbol.</p>
    <p class="report-feedback" data-risk-status role="status" hidden></p>
    @include('risk.filters')
    @if (count($appliedFilters))<section class="report-applied" aria-label="Filtros aplicados"><h2>Filtros aplicados</h2><ul>@foreach ($appliedFilters as $filter)<li><strong>{{ $filter['label'] }}:</strong> {{ $filter['value'] }}</li>@endforeach</ul></section>@endif
    @if (count($notices))<details class="risk-reading-help"><summary>Cómo leer el riesgo y el reporte</summary><ul>@foreach ($notices as $notice)<li>{{ $notice }}</li>@endforeach</ul></details>@endif
    @if ($canViewReport)
    <section class="panel report-download-panel risk-report-panel" aria-label="Reporte de riesgo por árbol">
        <div><h2>Reporte de riesgo por árbol</h2><p>Descarga todos los árboles de esta selección. La paginación de la bandeja no limita el reporte.</p><div class="risk-report-links"><a href="{{ $reportUrl }}">Ver reporte detallado</a><a href="{{ $aggregateReportUrl }}">Ver resumen por prioridad</a></div></div>
        @if ($canExport)@include('reports.downloads')@else<p class="muted">Tu perfil permite consultar el reporte; la descarga requiere permiso de exportación.</p>@endif
        @if ($canExport && $rows->total() > (int) config('reports.max_pdf_rows', 200) && !collect($downloads)->contains('format', 'pdf'))<p class="risk-pdf-limit">El PDF admite hasta {{ number_format((int) config('reports.max_pdf_rows', 200), 0, ',', '.') }} árboles. Acota los filtros para descargarlo; Excel y CSV permiten conjuntos mayores.</p>@endif
    </section>
    @endif
    <section class="panel forestry-table-panel risk-tree-panel" aria-label="Árboles y prioridad fitosanitaria">
        <div class="forestry-table-summary"><strong>{{ number_format($rows->total(), 0, ',', '.') }} {{ $rows->total() === 1 ? 'árbol encontrado' : 'árboles encontrados' }}</strong><span class="muted">Una fila por árbol · última inspección</span></div>
        @if ($rows->isEmpty())
            <div class="forestry-empty"><h2>No hay árboles para esta selección</h2><p>Revisa los filtros para consultar otra prioridad, campaña o fecha de inspección.</p><a class="button secondary" href="{{ route('risk.index') }}">Limpiar filtros</a></div>
        @else
            <div class="forestry-table-wrap"><table class="forestry-table risk-tree-table"><thead><tr><th scope="col">Árbol</th><th scope="col">Última inspección</th><th scope="col">Prioridad profesional</th><th scope="col">Sugerencia automática</th><th scope="col">Confirmación</th><th scope="col">Acciones</th></tr></thead><tbody>
            @foreach ($rows as $row)
                <tr>
                    <td data-label="Árbol"><div>@if ($canViewTree)<a class="tree-code" href="{{ route('trees.show', $row['_source']['tree_id']) }}">{{ $row['code'] }}</a>@else<strong class="tree-code">{{ $row['code'] }}</strong>@endif<small class="preserve-lines">{{ $row['common_names'] ?: 'Sin nombre común registrado' }}</small><small><i>{{ $row['scientific_name'] ?: 'Sin identificar' }}</i></small><span class="pill neutral">{{ $row['current_status_label'] }}</span>@if ($row['location_description'])<small>{{ $row['location_description'] }}</small>@endif</div></td>
                    <td data-label="Última inspección"><div>@if ($row['latest_inspection_date'])<strong>{{ \Carbon\CarbonImmutable::parse($row['latest_inspection_date'])->format('d/m/Y') }}</strong><small>{{ $row['inventory'] ?: 'Sin campaña formal' }}</small>@else<span>Sin inspección registrada</span>@endif</div></td>
                    <td data-label="Prioridad profesional"><span class="risk-priority" data-priority="{{ $row['risk_priority'] }}">{{ $row['risk_priority'] }}</span></td>
                    <td data-label="Sugerencia automática"><div><strong>{{ $row['risk_suggestion'] ?: 'Sin sugerencia' }}</strong>@if ($row['risk_methodology'])<small>{{ $row['risk_methodology'] }}</small>@endif @if ($row['risk_explanation'])<details class="risk-row-detail"><summary>Ver explicación</summary><p class="preserve-lines">{{ $row['risk_explanation'] }}</p>@if ($row['risk_observations'])<p class="preserve-lines"><strong>Observaciones profesionales:</strong> {{ $row['risk_observations'] }}</p>@endif</details>@endif</div></td>
                    <td data-label="Confirmación"><div>@if ($row['risk_confirmed_at'])<span>{{ $row['risk_confirmed_by'] ?: 'Responsable no disponible' }}</span><small>{{ \Carbon\CarbonImmutable::parse($row['risk_confirmed_at'])->utc()->format('d/m/Y H:i') }} UTC</small>@else<span>Sin confirmación registrada</span>@endif</div></td>
                    <td data-label="Acciones"><div class="row-actions">@if ($canChangeRisk && $row['_source']['inspection_id'])<a href="{{ route('risk.trees.decision', $row['_source']['tree_id']) }}" aria-label="Cambiar riesgo de {{ $row['code'] }}">Cambiar riesgo</a>@endif @if ($canViewTree)<a href="{{ route('trees.show', $row['_source']['tree_id']) }}" aria-label="Ver ficha de {{ $row['code'] }}">Ver ficha</a>@endif @if ($canViewInspection && $row['_source']['inspection_id'])<a href="{{ route('inspections.show', $row['_source']['inspection_id']) }}" aria-label="Ver última inspección de {{ $row['code'] }}">Ver última inspección</a>@endif @if ($canCreateInspection)<a href="{{ route('trees.inspections.create', $row['_source']['tree_id']) }}" aria-label="Nueva inspección de {{ $row['code'] }}">Nueva inspección</a>@endif</div></td>
                </tr>
            @endforeach
            </tbody></table></div>
        @endif
    </section>
    @include('partials.pagination', ['paginator' => $rows])
</section>
<script type="application/json" id="risk-bootstrap">{!! \Illuminate\Support\Js::encode(['config' => $filterConfig, 'filters' => $filters]) !!}</script>
@endsection
