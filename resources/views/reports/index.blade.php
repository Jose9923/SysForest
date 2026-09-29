@extends($isPublic ? 'public.layout' : 'layouts.institutional')
@section('title', 'Reportes')
@include('partials.catalog-select')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/maps.css') }}">
    <link rel="stylesheet" href="{{ asset('css/reports.css') }}">
@endpush
@push('scripts')
    <script type="module" src="{{ asset('js/reports/filters.js') }}"></script>
@endpush
@section('content')
    <section class="general-map reports-page" data-reports-page>
        <header class="page-heading reports-heading"><div><p class="eyebrow">{{ $isPublic ? 'CONSULTA CIUDADANA' : 'INFORMACIÓN INSTITUCIONAL' }} · {{ $scopeLabel }}</p><h1>Reportes del arbolado</h1><p class="muted">Consulta y descarga los resultados de tus filtros. {{ $isPublic ? 'Solo se incluye información publicada.' : 'La información corresponde a tu alcance autorizado.' }}</p></div></header>
        <div class="report-selector panel"><div class="field"><label for="report-kind">Selecciona un reporte</label><select class="catalog-select" id="report-kind" name="report" form="reports-filter-form">@foreach ($reports as $option)<option value="{{ $option['key'] }}" @selected($report['key'] === $option['key'])>{{ $option['title'] }}{{ !($option['available'] ?? true) ? ' · No disponible' : '' }}</option>@endforeach</select><button class="button secondary" type="submit" form="reports-filter-form">Ver reporte</button></div><div><h2>{{ $report['title'] }}</h2><p>{{ $report['description'] }}</p></div></div>
        <p class="report-feedback" data-reports-status role="status" hidden></p>
        <div class="map-layout report-layout">
            @include('reports.filters')
            <div class="report-results">
                @if (count($appliedFilters))<section class="report-applied" aria-label="Filtros aplicados"><h2>Filtros aplicados</h2><ul>@foreach ($appliedFilters as $filter)<li><strong>{{ $filter['label'] }}:</strong> {{ $filter['value'] }}</li>@endforeach</ul></section>@endif
                @foreach ($notices as $notice)<p class="report-notice">{{ $notice }}</p>@endforeach
                @if ($report['available'] ?? true)
                    <div class="report-metrics" aria-label="Resumen de la selección"><div><span>Árboles seleccionados</span><strong>{{ number_format($summary['totalTrees'], 0, ',', '.') }}</strong></div><div><span>Filas del reporte</span><strong>{{ number_format($summary['totalRows'], 0, ',', '.') }}</strong></div><div><span>Con ubicación</span><strong>{{ number_format($summary['georeferenced'], 0, ',', '.') }}</strong></div><div><span>Sin ubicación</span><strong>{{ number_format($summary['withoutGeometry'], 0, ',', '.') }}</strong></div></div>
                    <section class="panel report-download-panel" aria-label="Descargar reporte"><div><h2>Descargar resultados</h2><p>Se incluyen todos los resultados de los filtros aplicados. La paginación de esta vista no limita la descarga.</p></div>@include('reports.downloads')</section>
                    <section class="panel report-preview"><header><div><h2>Vista previa</h2><p>{{ $report['unit'] }} · {{ $isPublic ? 'Última inspección publicada cuando corresponde.' : 'Última inspección cuando corresponde.' }}</p></div><span>{{ number_format($rows->total(), 0, ',', '.') }} {{ $rows->total() === 1 ? 'fila' : 'filas' }}</span></header>
                        @if ($rows->isEmpty())<div class="report-empty"><h3>No hay resultados para esta selección</h3><p>Modifica los filtros o selecciona otro reporte.</p></div>@else @include('reports.table') @endif
                    </section>
                    @include('partials.pagination', ['paginator' => $rows])
                @else
                    <section class="panel report-empty"><h2>{{ $report['title'] }}</h2><p>{{ $report['notice'] ?? 'Este reporte no está disponible con la información actual.' }}</p></section>
                @endif
            </div>
        </div>
    </section>
    <script type="application/json" id="reports-bootstrap">{!! \Illuminate\Support\Js::encode(['isPublic' => $isPublic, 'config' => $filterConfig, 'filters' => $filters, 'report' => $report['key']]) !!}</script>
@endsection
