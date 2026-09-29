@extends('layouts.institutional')
@section('title', $record['code'])
@include('maintenances.assets')
@include('evidences.assets')
@section('content')
    <a class="back-link" href="{{ route('maintenances.index', ['tree_id' => $tree->id]) }}">← Volver a mantenimientos del árbol</a>
    <div class="page-heading forestry-heading"><div><p class="eyebrow">{{ $organization->name }} · INTERVENCIÓN FORESTAL</p><h1>{{ $record['code'] }}</h1><p class="muted">{{ $record['typeName'] }} · <a href="{{ route('trees.show', $tree->id) }}">{{ $tree->code }}</a></p><div class="forestry-flags"><span class="pill maintenance-state-{{ $record['status'] }}">{{ $record['statusLabel'] }}</span><span class="maintenance-priority maintenance-priority-{{ $record['priority'] }}">Prioridad {{ mb_strtolower($record['priorityLabel']) }}</span>@unless ($record['typeActive'])<span class="pill neutral">Tipo histórico inactivo</span>@endunless @if ($record['isVoided'])<span class="pill danger">Registro anulado</span>@endif</div></div>@if ($canUpdate)<a class="button" href="{{ route('maintenances.edit', $maintenance->id) }}">Editar mantenimiento</a>@endif</div>
    @if ($record['isVoided'])<div class="record-warning"><strong>Este registro está anulado</strong><p>El historial y las evidencias permanecen conservados.</p>@if ($record['deletionNotes'] !== null)<p class="preserve-lines">{{ $record['deletionNotes'] }}</p>@endif<p>{{ $record['deletedAt'] }} UTC · {{ $record['deletedBy'] ?? 'No disponible' }}</p></div>@endif
    @if ($record['treeVoided'])<p class="forestry-readonly">El árbol está anulado. Esta ficha conserva la información histórica de la intervención.</p>@endif
    <section class="panel forestry-panel maintenance-progress" aria-labelledby="maintenance-progress-title">
        <span class="section-kicker">SEGUIMIENTO Y CIERRE</span>
        <h2 id="maintenance-progress-title">{{ $progressGuide['title'] }}</h2>
        <p class="muted">{{ $progressGuide['description'] }}</p>
        @if (count($transitionActions) > 0)
            <h3>Acciones disponibles</h3>
                <div class="maintenance-transitions">
                    @foreach ($transitionActions as $action)
                        <details class="maintenance-transition {{ $action['isPrimary'] ? 'maintenance-transition-primary' : '' }}" @if ($action['isPrimary'] || old('status') === $action['status']) open @endif><summary>{{ $action['label'] }}</summary>
                            <form method="POST" action="{{ route('maintenances.status', $maintenance->id) }}">@csrf @method('PATCH')<input type="hidden" name="lock_version" value="{{ old('status') === $action['status'] ? old('lock_version', $maintenance->lock_version) : $maintenance->lock_version }}"><input type="hidden" name="status" value="{{ $action['status'] }}">
                                <p class="field-hint">{{ $action['description'] }}</p>
                                @if ($action['needsSchedule'])
                                    <div class="field"><label for="transition-{{ $action['status'] }}-date">Fecha programada *</label><input type="date" id="transition-{{ $action['status'] }}-date" name="scheduled_date" required value="{{ old('status') === $action['status'] ? old('scheduled_date', $maintenance->scheduled_date?->format('Y-m-d')) : $maintenance->scheduled_date?->format('Y-m-d') }}"></div>
                                @endif
                                @if ($action['needsAssignee'])
                                    <div class="field"><label for="transition-{{ $action['status'] }}-assignee">Responsable *</label><select class="catalog-select" id="transition-{{ $action['status'] }}-assignee" name="assigned_to" required><option value="">Selecciona una persona</option>@foreach ($options['assignees'] as $person)<option value="{{ $person->id }}" @selected((string) (old('status') === $action['status'] ? old('assigned_to', $maintenance->assigned_to) : $maintenance->assigned_to) === (string) $person->id)>{{ $person->name }}{{ !$person->active ? ' · cuenta inactiva' : '' }}</option>@endforeach</select></div>
                                @endif
                                @if ($action['needsResult'])
                                    <div class="field"><label for="transition-{{ $action['status'] }}-performed">Fecha de ejecución *</label><input type="date" id="transition-{{ $action['status'] }}-performed" name="performed_date" required value="{{ old('status') === $action['status'] ? old('performed_date') : '' }}"></div><div class="field"><label for="transition-{{ $action['status'] }}-result">Resultado de la intervención *</label><textarea id="transition-{{ $action['status'] }}-result" name="result" maxlength="20000" rows="4" required>{{ old('status') === $action['status'] ? old('result') : '' }}</textarea></div>
                                @endif
                                <div class="field"><label for="transition-{{ $action['status'] }}-reason">{{ $action['needsReason'] ? 'Motivo de cancelación *' : 'Nota del cambio' }}</label><textarea id="transition-{{ $action['status'] }}-reason" name="reason" rows="2" maxlength="2000" @required($action['needsReason'])>{{ old('status') === $action['status'] ? old('reason') : '' }}</textarea></div>
                                <div class="form-actions"><button class="button {{ $action['isDestructive'] ? 'danger' : '' }}">{{ $action['label'] }}</button></div>
                            </form>
                        </details>
                    @endforeach
                </div>
        @endif
    </section>
    <div class="maintenance-detail-layout">
        <div class="forestry-stack"><section class="panel forestry-panel"><span class="section-kicker">PROGRAMACIÓN</span><h2>Datos de la intervención</h2><dl class="details forestry-details"><div><dt>Tipo de mantenimiento</dt><dd>{{ $record['typeName'] }}</dd></div><div><dt>Prioridad</dt><dd>{{ $record['priorityLabel'] }}</dd></div><div><dt>Fecha programada</dt><dd>{{ $record['scheduledDate'] ?? 'No informada' }}</dd></div><div><dt>Responsable</dt><dd>{{ $record['assigneeName'] ?? 'Sin asignar' }}</dd></div><div><dt>Fecha de ejecución</dt><dd>{{ $record['performedDate'] ?? 'No registrada' }}</dd></div><div><dt>Estado actual</dt><dd>{{ $record['statusLabel'] }}</dd></div></dl></section>
            <section class="panel forestry-panel"><h2>Notas de la intervención</h2><p class="preserve-lines">{{ $record['notes'] ?? 'Sin notas registradas.' }}</p></section>
            <section class="panel forestry-panel"><h2>Resultado de la ejecución</h2><p class="preserve-lines">{{ $record['result'] ?? 'Sin resultado registrado.' }}</p></section>
        </div>
        <aside class="panel forestry-panel"><span class="section-kicker">TRAZABILIDAD</span><h2>Registro y responsables</h2><dl class="details maintenance-meta"><div><dt>Registrado por</dt><dd>{{ $record['createdBy'] }}</dd></div><div><dt>Fecha de registro</dt><dd>{{ $record['createdAt'] }}</dd></div><div><dt>Actualizado por</dt><dd>{{ $record['updatedBy'] }}</dd></div><div><dt>Última actualización</dt><dd>{{ $record['updatedAt'] }}</dd></div><div><dt>Versión</dt><dd>{{ $maintenance->lock_version }}</dd></div></dl><p class="maintenance-side-note">El mantenimiento tiene su propio seguimiento. El estado permanente del árbol se consulta en su ficha.</p></aside>
    </div>
    <div class="forestry-stack maintenance-following">
        @if ($maintenanceEvidenceGallery)@include('evidences.gallery', ['gallery' => $maintenanceEvidenceGallery])@endif
        <section class="panel forestry-panel"><span class="section-kicker">LÍNEA DE TIEMPO</span><h2>Historial de estados</h2><ol class="history-list">@forelse ($statusHistory as $entry)<li><time>{{ $entry['changedAt'] }}</time><strong>{{ $entry['from'] ? $entry['from'].' → ' : 'Registro inicial → ' }}{{ $entry['to'] }}</strong>@if ($entry['reason'] !== null)<p>{{ $entry['reason'] }}</p>@endif<small>{{ $entry['changedBy'] }} · versión {{ $entry['version'] }}</small></li>@empty<li><p>Sin cambios de estado registrados.</p></li>@endforelse</ol></section>
        <details class="panel forestry-panel maintenance-revisions"><summary>Correcciones y eventos del registro</summary><ol class="history-list">@forelse ($revisions as $revision)<li><time>{{ $revision['changedAt'] }}</time><strong>Versión {{ $revision['version'] }}</strong><p>{{ $revision['eventLabel'] }}</p><small>{{ $revision['changedBy'] }}</small>@if (count($revision['differences']) > 0)<div class="maintenance-differences">@foreach ($revision['differences'] as $difference)<div class="maintenance-difference"><h4>{{ $difference['label'] }}</h4><div><span>Antes</span><p class="preserve-lines">{{ $difference['before'] }}</p></div><div><span>Después</span><p class="preserve-lines">{{ $difference['after'] }}</p></div></div>@endforeach</div>@endif</li>@empty<li><p>Sin revisiones disponibles.</p></li>@endforelse</ol></details>
        @if ($canVoid)
            <details class="panel forestry-panel forestry-disclosure danger-zone"><summary>Anular registro por error</summary><form method="POST" action="{{ route('maintenances.void', $maintenance->id) }}" data-confirm-submit="¿Anular este registro de mantenimiento? Se conservarán el historial y las evidencias.">@csrf @method('DELETE')<input type="hidden" name="lock_version" value="{{ $maintenance->lock_version }}"><p class="muted">La anulación corrige un registro creado por error. Para suspender un trabajo que no se realizará, utiliza su cambio a Cancelado cuando esté disponible.</p><div class="field"><label for="maintenance-deletion-notes">Motivo de anulación *</label><textarea id="maintenance-deletion-notes" name="deletion_notes" required maxlength="2000" rows="3">{{ old('deletion_notes') }}</textarea>@error('deletion_notes')<span class="field-error">{{ $message }}</span>@enderror</div><div class="form-actions"><button class="button danger">Anular registro</button></div></form></details>
        @endif
        @if ($canRestore)
            <section class="panel forestry-panel"><h2>Restaurar mantenimiento</h2><p class="muted">Recupera este registro conservando su identidad, estado e historial.</p><form method="POST" action="{{ route('maintenances.restore', $maintenance->id) }}" data-confirm-submit="¿Restaurar este registro de mantenimiento?">@csrf<input type="hidden" name="lock_version" value="{{ $maintenance->lock_version }}"><div class="form-actions"><button class="button">Restaurar mantenimiento</button></div></form></section>
        @endif
    </div>
@endsection
