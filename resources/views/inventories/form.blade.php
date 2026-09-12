@extends('layouts.institutional')
@section('title', $inventory ? 'Editar inventario' : 'Nuevo inventario')
@include('forestry.assets')
@section('content')
    <a class="back-link" href="{{ $inventory ? route('inventories.show', $inventory->id) : route('inventories.index') }}">← Volver a inventarios</a>
    <div class="page-heading forestry-heading"><div><p class="eyebrow">{{ $organization->name }} · CAMPAÑAS</p><h1>{{ $inventory ? 'Editar inventario' : 'Nuevo inventario' }}</h1><p class="muted">Define la campaña a la que se vincularán los árboles.</p></div></div>
    <form method="POST" action="{{ $inventory ? route('inventories.update', $inventory->id) : route('inventories.store') }}" class="forestry-form organization-form">
        @csrf @if ($inventory) @method('PUT')<input type="hidden" name="lock_version" value="{{ old('lock_version', $inventory->lock_version) }}">@endif
        <section class="panel forestry-panel">
            <h2>Datos de la campaña</h2><p class="required-note">Los campos con * son obligatorios.</p>
            <div class="form-grid">
                <div class="field"><label for="inventory-code">Código *</label><input id="inventory-code" name="code" required maxlength="80" @readonly($inventory !== null) pattern="[A-Z0-9]+([-_][A-Z0-9]+)*" aria-describedby="inventory-code-help" value="{{ old('code', $inventory?->code) }}"><p id="inventory-code-help" class="field-hint">Mayúsculas, números, guiones o guiones bajos. Ej.: CENSO-2026.</p>@error('code')<span class="field-error">{{ $message }}</span>@enderror</div>
                <div class="field"><label for="inventory-name">Nombre *</label><input id="inventory-name" name="name" required maxlength="180" value="{{ old('name', $inventory?->name) }}">@error('name')<span class="field-error">{{ $message }}</span>@enderror</div>
                <div class="field wide"><label for="inventory-description">Descripción</label><textarea id="inventory-description" name="description" maxlength="4000" rows="3">{{ old('description', $inventory?->description) }}</textarea>@error('description')<span class="field-error">{{ $message }}</span>@enderror</div>
                <div class="field"><label for="inventory-starts">Fecha de inicio</label><input id="inventory-starts" name="starts_at" type="date" value="{{ old('starts_at', $inventory?->starts_at?->format('Y-m-d')) }}">@error('starts_at')<span class="field-error">{{ $message }}</span>@enderror</div>
                <div class="field"><label for="inventory-ends">Fecha de finalización</label><input id="inventory-ends" name="ends_at" type="date" value="{{ old('ends_at', $inventory?->ends_at?->format('Y-m-d')) }}">@error('ends_at')<span class="field-error">{{ $message }}</span>@enderror</div>
                @php($statusValue = $inventory?->status instanceof \BackedEnum ? $inventory->status->value : ($inventory?->status ?? 'draft'))
                <div class="field"><label for="inventory-state">Estado de la campaña *</label><select id="inventory-state" name="status" required>@foreach ($inventoryStatuses as $value => $label)<option value="{{ $value }}" @selected(old('status', $statusValue) === $value)>{{ $label }}</option>@endforeach</select>@error('status')<span class="field-error">{{ $message }}</span>@enderror</div>
                <div class="field"><label>Disponibilidad administrativa</label><input type="hidden" name="active" value="0"><label class="check-field" for="inventory-enabled"><input type="checkbox" id="inventory-enabled" name="active" value="1" @checked(old('active', $inventory?->active ?? true))><span>Inventario disponible</span></label>@error('active')<span class="field-error">{{ $message }}</span>@enderror</div>
            </div>
            <p class="forestry-note">Para registrar o vincular árboles, la campaña debe estar en estado Activo y disponible administrativamente.</p>
            <div class="form-actions"><a class="button secondary" href="{{ $inventory ? route('inventories.show', $inventory->id) : route('inventories.index') }}">Cancelar</a><button class="button">{{ $inventory ? 'Guardar cambios' : 'Crear inventario' }}</button></div>
        </section>
    </form>
@endsection
