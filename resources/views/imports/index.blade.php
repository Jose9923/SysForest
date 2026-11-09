@extends('layouts.institutional')
@section('title', 'Importaciones Excel')
@include('imports.assets')
@section('content')
    <div class="page-heading"><div><p class="eyebrow">{{ $organization->name }} · PLANTILLA OFICIAL</p><h1>Importaciones Excel</h1><p class="muted">Revisa cada lote antes de incorporar nuevos árboles y sus inspecciones.</p></div>
        @can('imports.create')<a class="button" href="{{ route('imports.create') }}">Nueva importación</a>@endcan
    </div>
    <section class="panel import-introduction"><div><h2>De la plantilla al inventario</h2><p class="muted">Descarga la plantilla, diligencia sus registros y cárgala para revisarlos. Los árboles se crean únicamente después de tu confirmación.</p></div><a class="button secondary" href="{{ route('imports.template') }}">Descargar plantilla oficial</a></section>
    <section class="panel import-history"><h2>Lotes de esta organización</h2>
        @if ($batches->isEmpty())
            <p class="muted import-empty">Aún no hay lotes de importación. Comienza con la plantilla oficial de SYSFOREST.</p>
        @else
            <div class="import-table-scroll" tabindex="0" role="region" aria-label="Historial de lotes de importación"><table class="import-table"><thead><tr><th scope="col">Lote y archivo</th><th scope="col">Creado</th><th scope="col">Estado</th><th scope="col">Filas</th><th scope="col">Importadas</th><th scope="col">Detalle</th></tr></thead><tbody>
                @foreach ($batches as $batch)
                    <tr><th scope="row"><span class="import-code">{{ $batch->code }}</span><span class="import-file-name">{{ $batch->file_name }}</span></th><td>{{ \App\Support\Time\BogotaDateTime::format($batch->created_at) }} (Bogotá)</td><td><span class="pill">{{ $batch->status->label() }}</span></td><td>{{ $batch->total_rows }}</td><td>{{ $batch->imported_rows }}</td><td><a class="text-link" href="{{ route('imports.show', $batch->id) }}">Ver lote <span class="import-sr-only">{{ $batch->code }}</span>→</a></td></tr>
                @endforeach
            </tbody></table></div>
            @include('partials.pagination', ['paginator' => $batches])
        @endif
    </section>
@endsection
