@extends('layouts.institutional')
@section('title', $tree->code)
@include('forestry.assets', ['withMap' => true])
@include('inspections.assets', ['stylesOnly' => true])
@include('maintenances.assets', ['stylesOnly' => true])
@include('evidences.assets')
@include('reports.assets')
@section('content')
    @php
        $statusValue = $tree->current_status instanceof \BackedEnum ? $tree->current_status->value : $tree->current_status;
    @endphp
    <a class="back-link" href="{{ route('trees.index', $tree->trashed() ? ['trashed' => 'only'] : []) }}">← Volver a árboles</a>
    <div class="page-heading forestry-heading"><div><p class="eyebrow">{{ $organization->name }} · FICHA PERMANENTE</p><h1>{{ $tree->code }}</h1><p class="muted">{{ $tree->species?->commonNames->pluck('name')->join(', ') ?: 'Especie sin identificar' }} @if ($tree->species) · <i>{{ $tree->species->scientific_name }}</i>@endif</p><div class="forestry-flags"><span class="pill">{{ $statuses[$statusValue] ?? $statusValue }}</span>@if ($tree->is_protected)<span class="pill neutral">Protegido</span>@endif @if ($tree->is_heritage)<span class="pill warning">Patrimonial</span>@endif @if ($tree->trashed())<span class="pill danger">Registro anulado</span>@endif</div></div>
        <div class="report-entry-actions"><a class="button secondary" href="#tree-history">Historial del árbol</a>@can('reports.view')@if (!$tree->trashed())<a class="button secondary" href="{{ route('reports.trees.pdf', ['tree' => $tree->id, 'scope' => 'organization']) }}">Descargar ficha PDF</a>@endif @endcan @if ($canUpdate && !$tree->trashed())<a class="button" href="{{ route('trees.edit', $tree->id) }}">Editar ficha</a>@endif</div>
    </div>
    @if ($tree->trashed())<div class="record-warning"><strong>Este registro está anulado</strong><p>{{ $tree->deletionReason?->name ?? 'Motivo no disponible' }} · {{ \App\Support\Time\BogotaDateTime::format($tree->deleted_at) }}</p>@if ($tree->deletion_notes)<p class="preserve-lines">{{ $tree->deletion_notes }}</p>@endif</div>@endif
    @if (!$canUpdate && !$tree->trashed())<p class="forestry-readonly">La ficha está disponible para consulta. La edición requiere permisos vigentes y, para funcionarios, una campaña activa vinculada.</p>@endif
    <div class="tree-overview {{ ($wholeTreePhoto || $treeEvidenceGallery) ? 'has-cover' : '' }}">
        <div class="forestry-stack">
            @include('inspections.tree-summary')
            @if ($latestInspectionEvidenceGallery ?? null)@include('evidences.gallery', ['gallery' => $latestInspectionEvidenceGallery])@endif
        </div>
        @include('trees.cover-photo')
    </div>
    <div class="forestry-layout">
        <div class="forestry-stack">
            <section class="panel forestry-panel"><span class="section-kicker">IDENTIDAD DEL ÁRBOL</span><h2>Identificación y taxonomía</h2><dl class="details forestry-details">
                <div><dt>Código permanente</dt><dd>{{ $tree->code }}</dd></div><div><dt>Número anterior o externo</dt><dd>{{ $tree->external_number ?: 'No informado' }}</dd></div>
                <div class="wide"><dt>Nombre científico</dt><dd><i>{{ $tree->species?->scientific_name ?? 'Sin identificar' }}</i></dd></div>
                <div><dt>Familia</dt><dd>{{ $tree->species?->family?->scientific_name ?? 'Sin identificar' }}</dd></div><div><dt>Nombres comunes</dt><dd>{{ $tree->species?->commonNames->pluck('name')->join(', ') ?: 'Sin identificar' }}</dd></div>
                <div><dt>Hábito de crecimiento</dt><dd>{{ $tree->growthHabit?->name ?? 'No informado' }}</dd></div><div><dt>Emplazamiento</dt><dd>{{ $tree->placement?->name ?? 'No informado' }}</dd></div>
                <div><dt>Origen de establecimiento</dt><dd>{{ $tree->establishmentOrigin?->name ?? 'No informado' }}</dd></div><div><dt>Origen biogeográfico</dt><dd>{{ $tree->biogeographicOrigin?->name ?? 'No informado' }}</dd></div>
                <div class="wide"><dt>Descripción de la ubicación</dt><dd class="preserve-lines">{{ $tree->location_description ?: 'No informada' }}</dd></div>
            </dl></section>
            <section class="panel forestry-panel"><span class="section-kicker">CAMPAÑAS</span><h2>Inventarios vinculados</h2><ul class="inventory-chip-list">@forelse ($tree->inventories as $linked)<li><a href="{{ route('inventories.show', $linked->id) }}">{{ $linked->code }} · {{ $linked->name }}</a></li>@empty<li><span>Sin inventarios vinculados</span></li>@endforelse</ul>
                @if ($canUpdate && !$tree->trashed() && $availableInventories->isNotEmpty())
                    <form method="POST" action="{{ route('trees.inventories', $tree->id) }}" style="margin-top:22px">@csrf<input type="hidden" name="lock_version" value="{{ old('lock_version', $tree->lock_version) }}">
                        <div class="field"><label for="attach-inventories">Agregar a campañas activas</label><select class="catalog-select" id="attach-inventories" name="inventory_ids[]" multiple required>@foreach ($availableInventories as $option)<option value="{{ $option->id }}">{{ $option->code }} · {{ $option->name }}</option>@endforeach</select>@error('inventory_ids')<span class="field-error">{{ $message }}</span>@enderror</div><div class="form-actions"><button class="button secondary">Vincular inventarios</button></div>
                    </form>
                @endif
            </section>
            <section class="panel forestry-panel"><span class="section-kicker">CAPTURA DE UBICACIÓN</span><h2>Coordenadas y GPS</h2><dl class="details forestry-details">
                <div class="wide"><dt>Lectura original</dt><dd class="preserve-lines">{{ $tree->coordinate_raw ?: 'Sin coordenadas' }}</dd></div>
                <div><dt>Datum informado</dt><dd>{{ $tree->datum ?: 'No informado' }}</dd></div><div><dt>Precisión reportada</dt><dd>{{ $tree->accuracy_m !== null ? $tree->accuracy_m.' m' : 'No informada' }}</dd></div>
                <div><dt>Marca del GPS</dt><dd>{{ $tree->gps_brand ?: 'No informada' }}</dd></div><div><dt>Modelo del GPS</dt><dd>{{ $tree->gps_model ?: 'No informado' }}</dd></div>
                <div class="wide"><dt>Fecha y hora de captura</dt><dd>{{ $captureDate['display'] ?? 'No informada' }}</dd></div>
            </dl></section>
        </div>
        <aside class="forestry-map-column forestry-stack">
            @include('forestry.map', ['mapId' => 'tree-detail-map', 'mapLabel' => $tree->code, 'mapLatitude' => $tree->latitude, 'mapLongitude' => $tree->longitude, 'mapFormat' => $tree->coordinate_format, 'mapEditable' => false, 'mapInputId' => '', 'mapDatumId' => ''])
            <section class="panel forestry-panel"><h2>Registro y seguimiento</h2><p class="muted">Horas de Bogotá (UTC−5).</p><dl class="details forestry-details"><div><dt>Registrado por</dt><dd>{{ $tree->creator?->name ?? 'No disponible' }}</dd></div><div><dt>Fecha de registro</dt><dd>{{ \App\Support\Time\BogotaDateTime::format($tree->created_at) }}</dd></div><div><dt>Última actualización</dt><dd>{{ \App\Support\Time\BogotaDateTime::format($tree->updated_at) }}</dd></div><div><dt>Actualizado por</dt><dd>{{ $tree->updater?->name ?? 'No disponible' }}</dd></div>@if ($tree->restored_at)<div class="wide"><dt>Última restauración</dt><dd>{{ \App\Support\Time\BogotaDateTime::format($tree->restored_at) }}</dd></div>@endif</dl></section>
        </aside>
    </div>
    <div class="forestry-stack" id="tree-history" style="margin-top:22px">
        <section class="panel forestry-panel"><h2>Historial del árbol</h2><p class="muted">Consulta sus visitas, intervenciones y cambios registrados.</p><div class="row-actions">
            @can('inspections.view')<a href="#tree-inspection-history">Visitas e inspecciones</a>@endcan
            @if ($canViewMaintenance)<a href="#tree-maintenances">Mantenimientos</a>@endif
            <a href="#tree-status-history">Cambios de estado</a><a href="#tree-location-history">Cambios de ubicación</a>
            <a href="{{ route('trees.follow-up', ['tree_id' => $tree->id]) }}">Bandeja de seguimiento</a>
        </div></section>
        @if ($treeEvidenceGallery ?? null)@include('evidences.gallery', ['gallery' => $treeEvidenceGallery])@endif
        @include('inspections.tree-timeline')
        @include('maintenances.tree-summary')
        @if ($canUpdate && !$tree->trashed())
            <details id="status-change" class="panel forestry-panel forestry-disclosure" @if ($errors->has('current_status') || $errors->has('reason')) open @endif><summary>Cambiar estado del árbol</summary>
                <form method="POST" action="{{ route('trees.status', $tree->id) }}">@csrf @method('PATCH')<input type="hidden" name="lock_version" value="{{ old('lock_version', $tree->lock_version) }}">
                    <div class="form-grid"><div class="field"><label for="change-status">Nuevo estado *</label><select id="change-status" name="current_status" aria-describedby="tree-status-help" required>@foreach ($statuses as $value => $label)<option value="{{ $value }}" @selected(old('current_status', $statusValue) === $value)>{{ $label }}</option>@endforeach</select>@error('current_status')<span class="field-error">{{ $message }}</span>@enderror</div><div class="field"><label for="status-reason">Motivo del cambio</label><textarea id="status-reason" name="reason" rows="2" maxlength="2000">{{ old('reason') }}</textarea>@error('reason')<span class="field-error">{{ $message }}</span>@enderror</div></div>
                    @include('trees.status-help')
                    <p class="field-hint">El cambio conservará el estado anterior, el motivo, la fecha y la persona responsable.</p><div class="form-actions"><button class="button">Guardar estado</button></div>
                </form>
            </details>
            <details id="location-change" class="panel forestry-panel forestry-disclosure" @if ($errors->hasAny(['coordinate_raw', 'datum', 'accuracy_m', 'gps_brand', 'gps_model', 'captured_at', 'captured_at_local', 'location_reason', 'location_change_type'])) open @endif><summary>Cambiar ubicación o datos de captura</summary>
                <form method="POST" action="{{ route('trees.location', $tree->id) }}">@csrf @method('PATCH')<input type="hidden" name="lock_version" value="{{ old('lock_version', $tree->lock_version) }}">
                    <div class="forestry-layout"><div class="forestry-stack"><div>@include('forestry.coordinates', ['coordinateRecord' => $tree, 'coordinatePrefix' => 'change'])</div>
                        <div class="form-grid"><div class="field"><label for="location-change-type">Tipo de cambio *</label><select id="location-change-type" name="location_change_type" required><option value="">Selecciona un tipo</option>@foreach ($locationChangeOptions as $value => $label)<option value="{{ $value }}" @selected(old('location_change_type') === $value)>{{ $label }}</option>@endforeach</select>@error('location_change_type')<span class="field-error">{{ $message }}</span>@enderror</div><div class="field"><label for="location-reason">Motivo *</label><textarea id="location-reason" name="location_reason" required maxlength="2000" rows="3">{{ old('location_reason') }}</textarea>@error('location_reason')<span class="field-error">{{ $message }}</span>@enderror</div></div>
                    </div><div>@include('forestry.map', ['mapId' => 'tree-change-map', 'mapLabel' => $tree->code.' · nueva ubicación', 'mapLatitude' => $tree->latitude, 'mapLongitude' => $tree->longitude, 'mapFormat' => $tree->coordinate_format, 'mapEditable' => true, 'mapInputId' => 'change-coordinate-raw', 'mapDatumId' => 'change-datum'])</div></div>
                    <p class="forestry-note">El tipo y el motivo quedarán en el historial, aunque conserves las coordenadas. El estado del árbol se gestiona por separado. Mover el marcador actualiza el texto a grados decimales.</p><div class="form-actions"><button class="button">Guardar ubicación</button></div>
                </form>
            </details>
        @endif
        <div class="detail-grid">
            <section class="panel forestry-panel" id="tree-status-history"><span class="section-kicker">HISTORIAL</span><h2>Cambios de estado</h2><ol class="history-list">@forelse ($tree->statusHistories as $entry)
                @php
                    $from = $entry->from_status instanceof \BackedEnum ? $entry->from_status->value : $entry->from_status;
                    $to = $entry->to_status instanceof \BackedEnum ? $entry->to_status->value : $entry->to_status;
                @endphp
                <li><time datetime="{{ $entry->changed_at?->toIso8601String() }}">{{ \App\Support\Time\BogotaDateTime::format($entry->changed_at) }}</time><strong>{{ $from ? ($statuses[$from] ?? $from).' → ' : 'Registro inicial → ' }}{{ $statuses[$to] ?? $to }}</strong>@if ($entry->reason)<p>{{ \App\Support\Imports\ExcelReferencePresentation::text($entry->reason) }}</p>@endif<small>{{ $entry->changedBy?->name ?? 'Responsable no disponible' }}</small></li>
            @empty<li><p>Sin cambios de estado registrados.</p></li>@endforelse</ol></section>
            <section class="panel forestry-panel" id="tree-location-history"><span class="section-kicker">HISTORIAL</span><h2>Cambios de ubicación</h2><ol class="history-list">@forelse ($tree->locationHistories as $entry)
                @php($changeValue = $entry->change_type instanceof \BackedEnum ? $entry->change_type->value : $entry->change_type)
                <li><time datetime="{{ $entry->changed_at?->toIso8601String() }}">{{ \App\Support\Time\BogotaDateTime::format($entry->changed_at) }}</time><strong>{{ $changeValue === null ? 'Ubicación inicial' : ($locationLabels[$changeValue] ?? $changeValue) }}</strong><p>Antes: {{ $entry->old_coordinate_raw ?: 'Sin coordenadas' }}</p><p>Después: {{ $entry->new_coordinate_raw ?: 'Sin coordenadas' }}</p>@if ($entry->reason)<p>{{ \App\Support\Imports\ExcelReferencePresentation::text($entry->reason) }}</p>@endif<small>{{ $entry->changedBy?->name ?? 'Responsable no disponible' }}</small></li>
            @empty<li><p>Sin cambios de ubicación registrados.</p></li>@endforelse</ol></section>
        </div>
        @if ($canVoid && !$tree->trashed())
            <details class="panel forestry-panel forestry-disclosure danger-zone" @if ($errors->hasAny(['deletion_reason_id', 'deletion_notes'])) open @endif><summary>Anular registro</summary>
                <form method="POST" action="{{ route('trees.void', $tree->id) }}" data-confirm-submit="¿Anular el registro {{ $tree->code }}? Se conservará para auditoría y podrá restaurarse con permiso.">@csrf @method('DELETE')<input type="hidden" name="lock_version" value="{{ old('lock_version', $tree->lock_version) }}">
                    <p class="muted">La anulación se utiliza para errores de registro. Si el árbol murió, fue talado o retirado, actualiza su estado.</p><div class="form-grid" style="margin-top:18px"><div class="field"><label for="deletion-reason">Motivo de anulación *</label><select class="catalog-select" id="deletion-reason" name="deletion_reason_id" required><option value="">Selecciona un motivo</option>@foreach ($options['deletion_reasons'] as $option)<option value="{{ $option->id }}" @selected(old('deletion_reason_id') === $option->id)>{{ $option->name }}</option>@endforeach</select>@error('deletion_reason_id')<span class="field-error">{{ $message }}</span>@enderror</div><div class="field"><label for="deletion-notes">Detalle</label><textarea id="deletion-notes" name="deletion_notes" maxlength="2000" rows="3">{{ old('deletion_notes') }}</textarea>@error('deletion_notes')<span class="field-error">{{ $message }}</span>@enderror</div></div><div class="form-actions"><button class="button danger">Anular registro</button></div>
                </form>
            </details>
        @endif
        @if ($canRestore && $tree->trashed())<section class="panel forestry-panel"><h2>Restaurar registro</h2><p class="muted">La restauración recupera la misma identidad del árbol y conserva la trazabilidad del registro.</p><form method="POST" action="{{ route('trees.restore', $tree->id) }}" data-confirm-submit="¿Restaurar el registro {{ $tree->code }}?">@csrf<input type="hidden" name="lock_version" value="{{ old('lock_version', $tree->lock_version) }}"><div class="form-actions"><button class="button">Restaurar registro</button></div></form></section>@endif
    </div>
@endsection
