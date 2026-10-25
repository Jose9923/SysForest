@extends('layouts.institutional')
@section('title', $tree ? 'Editar árbol '.$tree->code : 'Registrar árbol')
@include('forestry.assets', ['withMap' => true])
@section('content')
<p class="notice">El carbono aéreo se calcula al guardar las mediciones en la primera inspección. La identidad del árbol por sí sola no permite estimarlo.</p>
    @php
        $selectedIds = old('inventory_ids', $selectedInventoryIds ?? []);
        $selectedIds = is_array($selectedIds) ? $selectedIds : [];
    @endphp
    <a class="back-link" href="{{ $tree ? route('trees.show', $tree->id) : route('trees.index') }}">← {{ $tree ? 'Volver a la ficha' : 'Volver a árboles' }}</a>
    <div class="page-heading forestry-heading"><div><p class="eyebrow">{{ $organization->name }} · FICHA PERMANENTE</p><h1>{{ $tree ? 'Editar árbol '.$tree->code : 'Registrar árbol' }}</h1><p class="muted">La identidad del árbol se conserva entre campañas.</p></div></div>
    <form class="forestry-form" method="POST" action="{{ $tree ? route('trees.update', $tree->id) : route('trees.store') }}">
        @csrf @if ($tree) @method('PUT')<input type="hidden" name="lock_version" value="{{ old('lock_version', $tree->lock_version) }}">@endif
        <div class="forestry-layout forestry-form-layout">
            <div class="forestry-stack">
                <section class="panel forestry-panel tree-identity"><span class="section-kicker">01 · IDENTIFICACIÓN</span><h2>Árbol e inventarios</h2>
                    <div class="form-grid">
                        <div class="field"><label>Código SYSFOREST</label><span class="readonly-value">{{ $tree?->code ?? 'Se asignará al guardar' }}</span><p class="field-hint">Identificador permanente generado por el sistema.</p></div>
                        <div class="field"><label for="external-number">Número anterior o externo</label><input id="external-number" name="external_number" value="{{ old('external_number', $tree?->external_number) }}" maxlength="100" placeholder="Opcional">@error('external_number')<span class="field-error">{{ $message }}</span>@enderror</div>
                        @if ($tree)<div class="field wide"><label>Campañas vinculadas</label><ul class="inventory-chip-list">@foreach ($tree->inventories as $linked)<li><a href="{{ route('inventories.show', $linked->id) }}">{{ $linked->code }} · {{ $linked->name }}</a></li>@endforeach</ul><p class="field-hint">Los vínculos existentes se conservan.</p></div>@endif
                        <div class="field wide"><label for="tree-inventory-ids">{{ $tree ? 'Vincular a otras campañas activas' : 'Inventarios activos *' }}</label>
                            <select class="catalog-select" id="tree-inventory-ids" name="inventory_ids[]" multiple @required(!$tree) data-placeholder="Selecciona una o varias campañas">@foreach ($availableInventories as $option)<option value="{{ $option->id }}" @selected(in_array($option->id, $selectedIds, true))>{{ $option->code }} · {{ $option->name }}</option>@endforeach</select>
                            <p class="field-hint">{{ $tree ? 'Esta selección agrega vínculos; no retira al árbol de campañas anteriores.' : 'Selecciona al menos una campaña para registrar el árbol.' }}</p>@error('inventory_ids')<span class="field-error">{{ $message }}</span>@enderror @error('inventory_ids.*')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                    </div>
                    @if (!$tree && $availableInventories->isEmpty())<p class="forestry-note warning">No hay campañas activas disponibles. @if ($canManageInventory)<a class="text-link" href="{{ route('inventories.create') }}">Crea o activa un inventario</a> para registrar árboles.@else Solicita una campaña activa al administrador.@endif</p>@endif
                </section>
                <section class="panel forestry-panel tree-taxonomy"><span class="section-kicker">02 · IDENTIDAD BOTÁNICA</span><h2>Taxonomía y entorno</h2>
                    <div class="form-grid">
                        <div class="field wide"><label for="tree-species-id">Especie · nombre común y científico</label>
                            <select class="catalog-select" id="tree-species-id" name="species_id" data-species-select data-family-output="tree-family" data-common-output="tree-common"><option value="">Sin identificar</option>
                                @foreach ($options['species'] as $option)<option value="{{ $option->id }}" data-family="{{ $option->family?->scientific_name }}" data-common="{{ $option->commonNames->pluck('name')->join(', ') }}" @selected(old('species_id', $tree?->species_id) === $option->id)>{{ $option->commonNames->pluck('name')->join(', ') ?: 'Sin nombre común' }} · {{ $option->scientific_name }}{{ !$option->active ? ' (inactiva; referencia histórica)' : '' }}</option>@endforeach
                            </select>@error('species_id')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="field"><label>Familia</label><output id="tree-family" class="readonly-value">{{ $tree?->species?->family?->scientific_name ?? 'Sin especie seleccionada' }}</output></div>
                        <div class="field"><label>Nombres comunes registrados</label><output id="tree-common" class="readonly-value">{{ $tree?->species?->commonNames->pluck('name')->join(', ') ?: 'Sin nombre común registrado' }}</output></div>
                        @foreach (['placement_id' => ['placements', 'Emplazamiento'], 'growth_habit_id' => ['growth_habits', 'Hábito de crecimiento'], 'establishment_origin_id' => ['establishment_origins', 'Origen de establecimiento'], 'biogeographic_origin_id' => ['biogeographic_origins', 'Origen biogeográfico']] as $field => [$optionKey, $label])
                            <div class="field"><label for="tree-{{ $field }}">{{ $label }}</label><select class="catalog-select" id="tree-{{ $field }}" name="{{ $field }}"><option value="">No informado</option>@foreach ($options[$optionKey] as $option)<option value="{{ $option->id }}" @selected(old($field, $tree?->{$field}) === $option->id)>{{ $option->name }}{{ !$option->active ? ' (inactivo; referencia histórica)' : '' }}</option>@endforeach</select>@error($field)<span class="field-error">{{ $message }}</span>@enderror</div>
                        @endforeach
                    </div>
                    <p class="field-hint" style="margin-top:15px">El establecimiento y la procedencia biogeográfica son datos distintos. Conserva como no informado lo que no conozcas.</p>
                </section>
                <section class="panel forestry-panel tree-coordinates"><span class="section-kicker">03 · LOCALIZACIÓN</span><h2>Ubicación y captura GPS</h2>
                    <div class="field"><label for="tree-location-description">Descripción de la ubicación</label><textarea id="tree-location-description" name="location_description" rows="3" maxlength="2000" placeholder="Dirección, sector o referencia para encontrar el árbol">{{ old('location_description', $tree?->location_description) }}</textarea>@error('location_description')<span class="field-error">{{ $message }}</span>@enderror</div>
                    @if ($tree)<p class="forestry-note">Las coordenadas se actualizan desde <a class="text-link" href="{{ route('trees.show', $tree->id) }}#location-change">Cambiar ubicación</a> en la ficha. Cada cambio conserva su historial.</p>
                    @else<hr>@include('forestry.coordinates', ['coordinateRecord' => null, 'coordinatePrefix' => 'create'])@endif
                </section>
                <section class="panel forestry-panel tree-classification"><span class="section-kicker">04 · CONDICIÓN DEL REGISTRO</span><h2>Estado y protección</h2>
                    @if (!$tree)<div class="field"><label for="tree-initial-status">Estado inicial *</label><select id="tree-initial-status" name="current_status" aria-describedby="tree-status-help" required>@foreach ($statuses as $value => $label)<option value="{{ $value }}" @selected(old('current_status', 'active') === $value)>{{ $label }}</option>@endforeach</select>@error('current_status')<span class="field-error">{{ $message }}</span>@enderror</div>
                    @else<p class="muted">Estado actual: <strong>{{ $statuses[$tree->current_status->value] ?? $tree->current_status->value }}</strong>. <a class="text-link" href="{{ route('trees.show', $tree->id) }}#status-change">Cambiar estado desde la ficha</a>.</p>@endif
                    @include('trees.status-help')
                    <input type="hidden" name="is_protected" value="0"><label class="check-field" for="tree-protected"><input id="tree-protected" type="checkbox" name="is_protected" value="1" @checked(old('is_protected', $tree?->is_protected ?? false))><span>Árbol protegido</span></label>
                    <input type="hidden" name="is_heritage" value="0"><label class="check-field" for="tree-heritage"><input id="tree-heritage" type="checkbox" name="is_heritage" value="1" @checked(old('is_heritage', $tree?->is_heritage ?? false))><span>Árbol patrimonial</span></label>
                    <p class="field-hint">Estas clasificaciones se registran de forma independiente del estado del árbol.</p>
                </section>
            </div>
            <aside class="forestry-map-column">
                @include('forestry.map', ['mapId' => 'tree-form-map', 'mapLabel' => $tree?->code ?? 'Árbol por registrar', 'mapLatitude' => $tree?->latitude, 'mapLongitude' => $tree?->longitude, 'mapFormat' => $tree?->coordinate_format, 'mapEditable' => !$tree, 'mapInputId' => $tree ? '' : 'create-coordinate-raw', 'mapDatumId' => $tree ? '' : 'create-datum'])
            </aside>
        </div>
        <div class="forestry-submit"><div class="form-actions"><a class="button secondary" href="{{ $tree ? route('trees.show', $tree->id) : route('trees.index') }}">Cancelar</a><button class="button" @disabled(!$tree && $availableInventories->isEmpty())>{{ $tree ? 'Guardar cambios' : 'Registrar árbol' }}</button></div></div>
    </form>
@endsection
