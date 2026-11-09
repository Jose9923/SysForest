@extends('layouts.institutional')
@section('title', $title)
@include('evidences.assets', ['withForestryAssets' => true])
@section('content')
    <a class="back-link" href="{{ $ownerUrl }}">← Volver a {{ $ownerLabel }}</a>
    <div class="page-heading forestry-heading"><div><p class="eyebrow">{{ $organization->name }} · EVIDENCIAS FOTOGRÁFICAS</p><h1>{{ $title }}</h1><p class="muted">{{ $ownerLabel }}</p></div></div>
    <p class="forestry-note">Cada fotografía tendrá un único tipo de evidencia. Selecciona el tipo que corresponde a cada archivo.</p>
    <form class="forestry-form evidence-upload-form" method="POST" action="{{ $storeUrl }}" enctype="multipart/form-data" data-evidence-upload data-max-files="{{ $limits['maxFiles'] }}" data-max-size-mb="{{ $limits['maxSizeMb'] }}" data-allowed-mimes="{{ implode(',', $limits['allowedMimes']) }}">
        @csrf
        <section class="panel forestry-panel evidence-picker-panel"><div><span class="section-kicker">01 · SELECCIONAR FOTOGRAFÍAS</span><h2>Imágenes para este registro</h2><p class="muted">Puedes elegir fotografías existentes o utilizar la cámara del dispositivo.</p></div>
            <div class="evidence-picker"><label for="evidence-files">Seleccionar imágenes</label><input type="file" id="evidence-files" name="items[0][file]" accept="{{ implode(',', $limits['allowedMimes']) }}" required data-evidence-files><p class="field-hint">Hasta {{ $limits['maxFiles'] }} imágenes por carga · máximo {{ $limits['maxSizeMb'] }} MB por imagen · @foreach ($limits['allowedMimes'] as $mime){{ ['image/jpeg' => 'JPEG', 'image/png' => 'PNG', 'image/webp' => 'WEBP'][$mime] ?? $mime }}{{ $loop->last ? '.' : ', ' }}@endforeach</p></div>
            <button type="button" class="button secondary evidence-camera-button" data-camera-button hidden>Tomar fotografía</button><input type="file" accept="image/*" capture="environment" data-camera-input hidden>
            <p class="field-hint evidence-camera-help">La disponibilidad de la cámara depende del navegador y del dispositivo. También puedes seleccionar imágenes guardadas.</p>
        </section>
        <div data-upload-fallback class="panel forestry-panel evidence-following"><h2>Datos de la fotografía</h2><p class="field-hint">Puedes cargar una imagen por envío. Con JavaScript disponible se habilita la selección múltiple con previsualización.</p>
            <div class="form-grid"><div class="field"><label for="evidence-fallback-type">Tipo de evidencia *</label><select id="evidence-fallback-type" class="catalog-select" name="items[0][evidence_type_id]" required><option value="">Selecciona un tipo</option>@foreach ($evidenceTypes as $type)<option value="{{ $type->id }}" @selected(old('items.0.evidence_type_id') === $type->id)>{{ $type->name }}</option>@endforeach</select>@error('items.0.evidence_type_id')<span class="field-error">{{ $message }}</span>@enderror</div><div class="field"><label for="evidence-fallback-caption">Descripción de la fotografía</label><textarea id="evidence-fallback-caption" name="items[0][caption]" rows="2" maxlength="2000">{{ old('items.0.caption') }}</textarea>@error('items.0.caption')<span class="field-error">{{ $message }}</span>@enderror</div><div class="field"><label for="evidence-fallback-captured">Fecha y hora de captura (Bogotá)</label><input type="datetime-local" step="60" id="evidence-fallback-captured" name="items[0][captured_at]" value="{{ old('items.0.captured_at') }}"><p class="field-hint">Opcional. Déjala vacía si se desconoce.</p>@error('items.0.captured_at')<span class="field-error">{{ $message }}</span>@enderror</div><div class="field"><label for="evidence-fallback-notes">Notas</label><textarea id="evidence-fallback-notes" name="items[0][notes]" rows="2" maxlength="20000">{{ old('items.0.notes') }}</textarea>@error('items.0.notes')<span class="field-error">{{ $message }}</span>@enderror</div></div>
        </div>
        <section class="evidence-queue-section" data-upload-queue-section hidden><div class="evidence-section-heading"><div><span class="section-kicker">02 · CLASIFICAR CADA ARCHIVO</span><h2>Fotografías seleccionadas <small data-upload-count></small></h2></div></div><div class="evidence-upload-queue" data-upload-queue></div></section>
        <template data-upload-template>
            <article class="panel forestry-panel evidence-upload-card">
                <div class="evidence-upload-image"><img alt="" data-upload-preview><span class="evidence-image-unavailable" hidden data-upload-preview-error>No se pudo previsualizar este archivo.</span></div>
                <div class="evidence-upload-fields"><div class="evidence-upload-file-heading"><div><h3 data-upload-filename></h3><p data-upload-filesize class="field-hint"></p></div><button type="button" class="evidence-remove" data-upload-remove>Quitar</button></div>
                    <div class="field"><label data-upload-label="evidence_type_id">Tipo de evidencia *</label><select data-upload-field="evidence_type_id" required><option value="">Selecciona un tipo</option>@foreach ($evidenceTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select></div>
                    <div class="field"><label data-upload-label="caption">Descripción de la fotografía</label><textarea data-upload-field="caption" rows="2" maxlength="2000"></textarea></div>
                    <details class="evidence-upload-optional"><summary>Detalles opcionales</summary><div class="field"><label data-upload-label="captured_at">Fecha y hora de captura (Bogotá)</label><input type="datetime-local" step="60" data-upload-field="captured_at"><p class="field-hint">Déjala vacía cuando se desconozca la fecha real de captura.</p></div><div class="field"><label data-upload-label="notes">Notas</label><textarea data-upload-field="notes" rows="2" maxlength="20000"></textarea></div></details>
                    <ul class="evidence-file-errors" data-upload-file-errors aria-live="polite" hidden></ul>
                    <p class="evidence-file-status" data-upload-file-status>Lista para clasificar</p>
                </div>
            </article>
        </template>
        <div class="evidence-upload-errors" role="alert" data-upload-errors hidden></div>
        <div class="evidence-upload-progress" data-upload-progress-panel hidden><label for="evidence-upload-progress">Progreso de la carga</label><progress id="evidence-upload-progress" max="100" value="0" data-upload-progress></progress><p data-upload-progress-text aria-live="polite"></p></div>
        <div class="forestry-submit"><div class="form-actions"><a class="button secondary" href="{{ $ownerUrl }}">Volver al registro</a><button class="button" type="submit" data-upload-submit>Subir fotografías</button></div></div>
    </form>
@endsection
