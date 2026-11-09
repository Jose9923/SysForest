@extends('layouts.institutional')
@section('title', 'Lote '.$batch->code)
@include('imports.assets')
@section('content')
    <a class="back-link" href="{{ route('imports.index') }}">← Volver a importaciones</a>
    <div class="page-heading"><div><p class="eyebrow">{{ $organization->name }} · IMPORTACIÓN EXCEL</p><h1>Lote {{ $batch->code }}</h1><p class="muted import-file-name">{{ $batch->file_name }}</p></div><span class="pill">{{ ($reviewExpired ?? false) ? 'Revisión caducada' : $batch->status->label() }}</span></div>
    @if ($reviewExpired ?? false)<div class="import-message" role="status">Esta revisión caducó. Carga nuevamente el archivo para comprobar los datos vigentes antes de importar.</div>@endif
    @if ($batch->notice)<div class="import-message" role="status">{{ $batch->notice }}</div>@endif
    <section class="import-metrics" aria-label="Resultado de la revisión"><div><span>Filas revisadas</span><strong>{{ $batch->total_rows }}</strong></div><div><span>Filas válidas</span><strong>{{ $batch->valid_rows }}</strong></div><div><span>Filas con errores</span><strong>{{ $batch->invalid_rows }}</strong></div><div><span>Árboles importados</span><strong>{{ $batch->imported_rows }}</strong></div></section>
    <section class="panel import-destination"><div><p class="eyebrow">INVENTARIO DE DESTINO</p><h2>{{ $inventory->code }} · {{ $inventory->name }}</h2><p class="muted">{{ $organization->name }}</p></div><dl class="import-dates"><div><dt>Archivo revisado</dt><dd>{{ \App\Support\Time\BogotaDateTime::format($batch->created_at) }} (Bogotá)</dd></div>@if ($batch->confirmed_at)<div><dt>Importación confirmada</dt><dd>{{ \App\Support\Time\BogotaDateTime::format($batch->confirmed_at) }} (Bogotá)</dd></div>@elseif ($canConfirm)<div><dt>Revisión válida hasta</dt><dd>{{ \App\Support\Time\BogotaDateTime::format($batch->expires_at) }} (Bogotá)</dd></div>@endif</dl></section>
    <section class="panel import-preview" aria-labelledby="import-preview-title"><div class="import-section-heading"><div><h2 id="import-preview-title">Revisión por fila</h2><p class="muted">El número corresponde a la fila de la hoja Datos. Puedes desplegar sus valores y cálculos antes de confirmar.</p></div><span class="pill">{{ $rows->total() }} {{ $rows->total() === 1 ? 'fila' : 'filas' }}</span></div>
        @include('imports.rows')
        @include('partials.pagination', ['paginator' => $rows])
    </section>
    @if ($canConfirm)
        <section class="panel import-confirmation"><p class="eyebrow">03 · CONFIRMACIÓN EXPLÍCITA</p><h2>Confirmar la importación completa</h2><p>{{ $batch->valid_rows === 1 ? 'Se creará 1 árbol y su inspección' : 'Se crearán '.$batch->valid_rows.' árboles y sus inspecciones' }} en <strong>{{ $inventory->name }}</strong>. Si una fila deja de ser válida, se detiene el lote completo sin incorporar filas parcialmente.</p>
            <form method="POST" action="{{ route('imports.confirm', $batch->id) }}" data-import-confirmation>
                @csrf<input type="hidden" name="lock_version" value="{{ $batch->lock_version }}">
                <label class="import-confirm-check" for="import-confirmation"><input type="checkbox" id="import-confirmation" name="confirmation" value="1" required><span>He revisado todas las filas y confirmo que {{ $inventory->code }} · {{ $inventory->name }} es el inventario de destino correcto.</span></label>@error('confirmation')<span class="field-error">{{ $message }}</span>@enderror
                <div class="form-actions"><button type="submit" class="button">Confirmar importación</button></div>
            </form>
        </section>
    @elseif ($batch->invalid_rows > 0)
        <section class="import-message import-message-error" role="status"><h2>Corrige el archivo antes de importar</h2><p>El lote contiene filas con errores. Corrige las celdas indicadas en Excel y carga el archivo corregido como un nuevo lote.</p>@can('imports.create')<a class="button secondary" href="{{ route('imports.create') }}">Cargar archivo corregido</a>@endcan</section>
    @endif
    @if ($canCancel ?? false)
        <section class="import-cancel"><form method="POST" action="{{ route('imports.cancel', $batch->id) }}">@csrf<input type="hidden" name="lock_version" value="{{ $batch->lock_version }}"><button class="button secondary" type="submit">Cancelar este lote</button></form><p class="muted">Se conserva el registro de la revisión para auditoría.</p></section>
    @endif
@endsection
