@extends('public.layout')
@include('public.assets', ['mode' => 'list'])
@include('reports.assets')
@section('content')
    <section class="general-map" data-public-list>
        <div class="page-heading"><div><p class="eyebrow">CONSULTA CIUDADANA</p><h1>Inventario del arbolado</h1><p class="muted">Árboles e información forestal publicados por las organizaciones participantes.</p></div><div class="report-entry-actions"><a class="button secondary" href="{{ $mapUrl }}" data-public-map-link>Ver en el mapa</a><a class="button secondary" href="{{ route('public.reports.index', array_merge(array_diff_key($filters, array_flip(['page', 'render_mode', 'term', 'format', 'trashed', 'include_deleted', 'include_voided_trees', 'tree_id'])), ['report' => 'inventory'])) }}">Descargar resultados</a></div></div>
        @include('public.indicators')
        <div class="map-layout public-list-layout">
            @include('maps.filters', ['publicMap' => true, 'publicList' => true, 'filterAction' => route('public.trees.index'), 'filterTitle' => 'Filtrar inventario', 'filterHidden' => ['sort' => $filters['sort'] ?? 'code', 'direction' => $filters['direction'] ?? 'asc']])
            <div class="public-list-results">
                <p class="public-notice" data-public-filter-status role="status" hidden></p>
                <div class="public-list-heading"><p><strong>{{ number_format($trees->total(), 0, ',', '.') }}</strong> {{ $trees->total() === 1 ? 'árbol encontrado' : 'árboles encontrados' }}</p><form method="GET" action="{{ route('public.trees.index') }}" class="public-order" data-public-order>
                    @foreach ($filters as $key => $value)
                        @unless (in_array($key, ['sort', 'direction', 'page', 'render_mode'], true))
                            @if (is_array($value))@foreach ($value as $item)<input type="hidden" name="{{ $key }}[]" value="{{ $item }}">@endforeach
                            @elseif ($value !== null && $value !== '')<input type="hidden" name="{{ $key }}" value="{{ is_bool($value) ? (int) $value : $value }}">@endif
                        @endunless
                    @endforeach
                    <div class="field"><label for="public-sort">Ordenar por</label><select name="sort" id="public-sort">@foreach ($sortOptions as $value => $label)<option value="{{ $value }}" @selected(($filters['sort'] ?? 'code') === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div class="field"><label for="public-direction">Dirección</label><select name="direction" id="public-direction"><option value="asc" @selected(($filters['direction'] ?? 'asc') === 'asc')>Ascendente</option><option value="desc" @selected(($filters['direction'] ?? '') === 'desc')>Descendente</option></select></div><button class="button secondary" type="submit">Ordenar</button>
                </form></div>
                @if ($trees->isEmpty())
                    <div class="public-empty"><h2>No encontramos árboles para esta consulta</h2><p>Prueba otros filtros o consulta el inventario publicado completo.</p><a class="button secondary" href="{{ route('public.trees.index') }}">Limpiar filtros</a></div>
                @else
                    <div class="public-table-wrap"><table class="public-tree-table"><caption class="sr-only">Árboles publicados que cumplen los filtros seleccionados</caption><thead><tr><th scope="col">Árbol</th><th scope="col">Especie</th><th scope="col">Territorio</th><th scope="col">Emplazamiento</th><th scope="col">Estado</th><th scope="col">Ficha</th></tr></thead><tbody>
                        @foreach ($trees as $tree)
                            <tr><td data-label="Árbol"><a class="public-tree-code" href="{{ $tree['url'] }}">{{ $tree['code'] }}</a><span class="public-cell-secondary">{{ implode(', ', $tree['common_names']) ?: 'Nombres comunes no informados' }}</span></td><td data-label="Especie"><em>{{ $tree['scientific_name'] ?? 'No informada' }}</em><span class="public-cell-secondary">{{ $tree['family'] ?? '' }}</span></td><td data-label="Territorio">{{ $tree['municipality']['name'] ?? 'Municipio no informado' }}<span class="public-cell-secondary">{{ $tree['organization']['name'] ?? '' }}</span></td><td data-label="Emplazamiento">{{ $tree['placement'] ?? 'No informado' }}</td><td data-label="Estado"><span class="pill">{{ $tree['status_label'] }}</span>@if ($tree['is_protected'])<span class="public-cell-secondary">Protegido</span>@endif @if ($tree['is_heritage'])<span class="public-cell-secondary">Patrimonial</span>@endif</td><td data-label="Ficha"><a class="public-text-link" href="{{ $tree['url'] }}">Ver árbol <span class="sr-only">{{ $tree['code'] }}</span><span aria-hidden="true">→</span></a></td></tr>
                        @endforeach
                    </tbody></table></div>
                    <div class="public-pagination">{{ $trees->links() }}</div>
                @endif
            </div>
        </div>
    </section>
    <script type="application/json" id="public-list-bootstrap">{!! \Illuminate\Support\Js::encode(['config' => $mapConfig, 'filters' => $initialFilters]) !!}</script>
@endsection
