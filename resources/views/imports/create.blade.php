@extends('layouts.institutional')
@section('title', 'Nueva importación Excel')
@include('imports.assets')
@section('content')
    <a class="back-link" href="{{ route('imports.index') }}">← Volver a importaciones</a>
    <div class="page-heading"><div><p class="eyebrow">{{ $organization->name }} · PLANTILLA OFICIAL</p><h1>Nueva importación Excel</h1><p class="muted">Carga los nuevos individuos de una campaña y revisa el resultado antes de guardarlos.</p></div></div>
    <ol class="import-steps" aria-label="Pasos de importación"><li><span>1</span><strong>Descargar y diligenciar</strong></li><li aria-current="step"><span>2</span><strong>Cargar y revisar</strong></li><li><span>3</span><strong>Confirmar el lote</strong></li></ol>
    <div class="import-create-grid">
        <section class="panel import-guide"><p class="eyebrow">01 · PREPARAR EL ARCHIVO</p><h2>Usa la plantilla oficial</h2><p>Incluye los campos del inventario, sus instrucciones y los códigos de los catálogos disponibles.</p><a class="button secondary" href="{{ route('imports.template') }}">Descargar plantilla oficial</a>
            <ul class="import-guidance"><li>Completa la hoja <strong>Datos</strong> sin cambiar sus encabezados.</li><li>Consulta <strong>Diccionario</strong>, <strong>Catalogos</strong> e <strong>Instrucciones</strong> para diligenciar cada campo.</li><li>Deja vacíos los campos calculados. SYSFOREST los recalcula con las reglas forestales vigentes.</li><li>Las fotografías se adjuntan después desde Evidencias.</li><li>Un código explícito conserva su número y puede adelantar el consecutivo institucional. Los códigos automáticos continúan después del mayor reservado.</li></ul><p class="muted">Cada fila crea un nuevo árbol con una inspección. Esta carga no actualiza árboles existentes.</p>
        </section>
        <section class="panel"><p class="eyebrow">02 · CARGAR PARA REVISIÓN</p><h2>Selecciona el destino y el archivo</h2>
            @if ($inventories->isEmpty())
                <div class="import-message"><p>No hay inventarios disponibles para importar en esta organización.</p><a class="text-link" href="{{ route('inventories.index') }}">Consultar inventarios →</a></div>
            @else
                <form method="POST" action="{{ route('imports.store') }}" enctype="multipart/form-data" class="import-upload-form">
                    @csrf
                    <div class="field"><label for="import-inventory">Inventario de destino *</label><select name="inventory_id" id="import-inventory" class="catalog-select" required><option value="">Selecciona un inventario</option>@foreach ($inventories as $inventory)<option value="{{ $inventory->id }}" @selected(old('inventory_id') === $inventory->id)>{{ $inventory->code }} · {{ $inventory->name }}</option>@endforeach</select><p class="help">Todos los registros del lote quedarán vinculados a este inventario de {{ $organization->name }}.</p>@error('inventory_id')<span class="field-error">{{ $message }}</span>@enderror</div>
                    <div class="field import-file-field"><label for="import-file">Plantilla diligenciada (.xlsx) *</label><input id="import-file" type="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" aria-describedby="import-file-help" required><p class="help" id="import-file-help">Máximo {{ $limits['max_file_mb'] }} MB y {{ $limits['max_rows'] }} filas. No se aceptan archivos con macros ni fórmulas.</p>@error('file')<span class="field-error">{{ $message }}</span>@enderror</div>
                    <div class="import-message"><strong>La carga solo prepara una revisión.</strong><p>Verás los errores por fila. Ningún árbol se creará mientras el lote tenga errores ni antes de tu confirmación.</p></div>
                    <div class="form-actions"><button type="submit" class="button">Cargar y revisar archivo</button></div>
                </form>
            @endif
        </section>
    </div>
@endsection
