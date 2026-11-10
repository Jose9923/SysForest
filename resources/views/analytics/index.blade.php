@extends('layouts.institutional')
@section('title', 'Diagramas y gráficas')
@include('partials.catalog-select')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/maps.css') }}">
    <link rel="stylesheet" href="{{ asset('css/analytics.css').'?v='.filemtime(public_path('css/analytics.css')) }}">
@endpush
@push('scripts')
    <script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}" defer></script>
    <script src="{{ asset('js/analytics/dashboard.js').'?v='.filemtime(public_path('js/analytics/dashboard.js')) }}" type="module"></script>
@endpush
@section('content')
<section class="general-map analytics-page" data-analytics-page>
    <header class="analytics-hero">
        <div><p class="eyebrow">INTELIGENCIA FORESTAL · {{ $scopeLabel }}</p><h1>Diagramas y gráficas</h1><p>Conoce la composición de tu arbolado, compara inventarios y explora sus registros en el tiempo.</p><span class="analytics-live"><i aria-hidden="true"></i> Datos de SysForest · consulta actual</span></div>
        <div class="analytics-hero-actions"><a class="button" href="{{ route('maps.index', array_diff_key($filters, array_flip(['physical_condition_category_ids', 'phytosanitary_condition_category_ids']))) }}">Explorar cartografía ↗</a><a class="analytics-text-link" href="{{ route('reports.index', ['report' => 'species'] + $filters) }}">Ir a reportes y descargas →</a></div>
    </header>
    <div class="analytics-metrics" aria-label="Resumen del arbolado filtrado">
        <article><span>Árboles seleccionados</span><strong>{{ number_format($summary['totalTrees'], 0, ',', '.') }}</strong><small>Individuos únicos</small></article>
        <article><span>Con ubicación</span><strong>{{ number_format($summary['georeferenced'], 0, ',', '.') }}</strong><small>{{ $summary['totalTrees'] ? number_format(100 * $summary['georeferenced'] / $summary['totalTrees'], 1, ',', '.') : '0' }} % del conjunto seleccionado</small></article>
        <article><span>Especies identificadas</span><strong>{{ number_format($summary['speciesCount'], 0, ',', '.') }}</strong><small>Especies distintas registradas</small></article>
        <article><span>Sin ubicación</span><strong>{{ number_format($summary['withoutGeometry'], 0, ',', '.') }}</strong><small>Pendientes de georreferenciar</small></article>
    </div>
    <p class="analytics-feedback" data-analytics-status role="status" hidden></p>
    <div class="analytics-layout">
        @include('analytics.filters')
        <div class="analytics-results">
            @if (count($appliedFilters))<div class="map-active-filters" aria-label="Filtros aplicados">@foreach ($appliedFilters as $filter)<span class="map-filter-chip"><strong>{{ $filter['label'] }}:</strong> {{ $filter['value'] }}</span>@endforeach</div>@endif
            <nav class="analytics-tabs" aria-label="Secciones del tablero" data-analytics-tabs hidden>
                @foreach ($chartSections as $section)
                    <button type="button" data-category="{{ $section['key'] }}" aria-pressed="false">{{ $section['title'] }}</button>
                @endforeach
                <button type="button" data-category="all" aria-pressed="false">Ver todos</button>
            </nav>
            <section class="analytics-coverage" aria-labelledby="coverage-title">
                <header><div><p class="eyebrow">CALIDAD DEL INVENTARIO</p><h2 id="coverage-title">Del registro al territorio</h2></div><span class="analytics-badge">Diagrama de cobertura</span></header>
                <div class="coverage-diagram">
                    <div class="coverage-source"><span>Conjunto seleccionado</span><strong>{{ number_format($coverage['total'], 0, ',', '.') }}</strong><small>árboles únicos</small></div>
                    <div class="coverage-branches">
                        <div class="coverage-branch"><div><span>Ubicación registrada</span><strong>{{ number_format($coverage['georeferenced'], 0, ',', '.') }}</strong></div><progress max="{{ max(1, $coverage['total']) }}" value="{{ $coverage['georeferenced'] }}" aria-label="Árboles con ubicación"></progress><small>{{ number_format($coverage['withoutGeometry'], 0, ',', '.') }} sin ubicación</small></div>
                        @if ($coverage['withInspection'] !== null)<div class="coverage-branch"><div><span>Con inspección pertinente</span><strong>{{ number_format($coverage['withInspection'], 0, ',', '.') }}</strong></div><progress max="{{ max(1, $coverage['total']) }}" value="{{ $coverage['withInspection'] }}" aria-label="Árboles con inspección"></progress><small>{{ number_format($coverage['withoutInspection'], 0, ',', '.') }} sin inspección</small></div>@endif
                    </div>
                </div>
                <p class="analytics-note">{{ $coverage['notice'] }}</p>
            </section>
            <details class="analytics-method"><summary>Cómo interpretar este análisis</summary>@foreach ($notices as $notice)<p class="analytics-note">{{ $notice }}</p>@endforeach</details>
            @if ($summary['totalTrees'] === 0)<div class="analytics-empty"><h2>No hay árboles para esta selección</h2><p>Prueba otro inventario o limpia los filtros para ampliar la consulta.</p></div>@endif
            @foreach ($chartSections as $section)
                <section class="analytics-topic" data-chart-section="{{ $section['key'] }}" aria-labelledby="topic-{{ $section['key'] }}">
                    <header class="analytics-topic-heading"><h2 id="topic-{{ $section['key'] }}">{{ $section['title'] }}</h2><p>{{ $section['description'] }}</p></header>
                    <div class="analytics-chart-grid">
                @foreach ($section['charts'] as $chartKey)
                    @php
                        $chart = collect($charts)->firstWhere('key', $chartKey);
                    @endphp
                    @continue ($chart === null)
                    @php
                        $category = $section['key'];
                        $canDownload = ($chart['exportable'] ?? false) || count($chart['downloads']) > 0;
                    @endphp
                    <article class="analytics-card" data-chart-card="{{ $chart['key'] }}" data-chart-category="{{ $category }}">
                        <header><span class="analytics-chart-number">{{ $chart['unit'] }} · {{ $chart['totalGroups'] }} grupos</span><h3>{{ $chart['title'] }}</h3>@if (!empty($chart['context']))<p class="analytics-chart-context">{{ $chart['context'] }}</p>@endif</header>
                        <p class="analytics-description">{{ $chart['description'] }}</p>
                        @if (count($chart['labels']))<div class="analytics-canvas-wrap" data-chart-visual hidden><canvas data-chart="{{ $chart['key'] }}" role="img" aria-label="{{ $chart['title'] }}. Los valores están disponibles en la tabla de datos inferior."></canvas></div>
                        @else <div class="analytics-chart-empty">Sin registros para esta selección.</div>@endif
                        @foreach ($chart['notices'] as $notice)<p class="analytics-note">{{ $notice }}</p>@endforeach
                        <div class="analytics-chart-actions">
                            @if ($canDownload)<button type="button" class="analytics-action" data-chart-png="{{ $chart['key'] }}" hidden>↓ Imagen PNG</button><button type="button" class="analytics-action" data-chart-csv="{{ $chart['key'] }}" hidden>↓ Datos CSV</button>@endif
                            @if ($chart['reportUrl'])<a class="analytics-action" href="{{ $chart['reportUrl'] }}">Ver reporte ↗</a>@endif
                            @foreach ($chart['downloads'] as $download)@if ($download['format'] === 'xlsx')<a class="analytics-action" href="{{ $download['url'] }}">↓ Excel</a>@endif @endforeach
                        </div>
                        <details class="analytics-data"><summary>Ver tabla de datos · {{ count($chart['rows']) }} filas</summary><div class="analytics-table-wrap" tabindex="0" role="region" aria-label="Datos de {{ $chart['title'] }}"><table><thead><tr>@foreach ($chart['columns'] as $column)<th scope="col">{{ $column['label'] }}</th>@endforeach</tr></thead><tbody>@forelse (array_slice($chart['rows'], 0, 100) as $row)<tr>@foreach ($chart['columns'] as $column)<td>{{ ($column['type'] ?? '') === 'integer' ? number_format((int) ($row[$column['key']] ?? 0), 0, ',', '.') : ($row[$column['key']] ?? '—') }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($chart['columns']) }}">No hay datos para mostrar.</td></tr>@endforelse</tbody></table></div>@if (count($chart['rows']) > 100)<p class="analytics-note">La tabla se presenta en páginas de 100 filas. Activa JavaScript para recorrerlas.</p>@endif</details>
                    </article>
                @endforeach
                    </div>
                </section>
            @endforeach
            <p class="analytics-empty" data-category-empty hidden>No hay gráficas disponibles en esta sección para tus permisos.</p>
            <p class="analytics-footnote">Cada gráfica responde a los filtros aplicados. Mover el mapa no limita este tablero. Las capas de referencia y las mediciones locales del mapa no alteran las estadísticas.</p>
        </div>
    </div>
</section>
<script type="application/json" id="analytics-bootstrap">{!! \Illuminate\Support\Js::encode(['config' => $filterConfig, 'filters' => $filters, 'charts' => $charts, 'scopeLabel' => $scopeLabel, 'generatedAt' => $generatedAt, 'appliedFilters' => $appliedFilters]) !!}</script>
@endsection
