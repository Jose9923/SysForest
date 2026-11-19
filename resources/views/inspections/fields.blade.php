    @php
        $physicalIds = old('physical_condition_ids', $selectedPhysicalConditionIds);
        $physicalIds = is_array($physicalIds) ? $physicalIds : [];
        $phytosanitaryIds = old('phytosanitary_condition_ids', $selectedPhytosanitaryConditionIds);
        $phytosanitaryIds = is_array($phytosanitaryIds) ? $phytosanitaryIds : [];
        $faunaIds = old('fauna_category_ids', $selectedFaunaCategoryIds);
        $faunaIds = is_array($faunaIds) ? $faunaIds : [];
        $clinometerEnabled = in_array(old('uses_clinometer', $inspection?->uses_clinometer), [true, 1, '1', 'true'], true);
    @endphp
    <nav aria-label="Secciones de la inspección"><ol class="inspection-anchor-nav">
        @foreach (['context' => '1. Contexto', 'dendrometry' => '2. Dasonomía', 'physical' => '3. Físico-mecánico', 'phytosanitary' => '4. Fitosanitario', 'phenology' => '5. Fenología', 'fauna' => '6. Fauna', 'observations' => '7. Observaciones'] as $anchor => $label)<li><a href="#inspection-{{ $anchor }}">{{ $label }}</a></li>@endforeach
    </ol></nav>
        <div class="forestry-stack">
            <section class="panel forestry-panel inspection-section" id="inspection-context"><span class="section-kicker">01 · CONTEXTO DE INSPECCIÓN</span><h2>Fecha y campaña</h2>
                <div class="form-grid">
                    <div class="field"><label for="inspection-date">Fecha de inspección *</label><input id="inspection-date" name="inspection_date" type="date" value="{{ old('inspection_date', $inspection?->inspection_date?->format('Y-m-d')) }}" required>@error('inspection_date')<span class="field-error">{{ $message }}</span>@enderror</div>
                    @if ($firstInspection ?? false)
                        <div class="field"><label for="first-inspection-inventory">Campaña de la primera visita</label><select class="catalog-select" id="first-inspection-inventory" name="first_inspection_inventory_id"><option value="">Usar la única campaña seleccionada arriba</option>@foreach ($availableInventories as $option)<option value="{{ $option->id }}" @selected(old('first_inspection_inventory_id') === $option->id)>{{ $option->code }} · {{ $option->name }}</option>@endforeach</select><p class="field-hint">Si vinculaste varios inventarios, elige a cuál corresponde esta primera visita.</p>@error('first_inspection_inventory_id')<span class="field-error">{{ $message }}</span>@enderror</div>
                    @else
                    <div class="field"><label for="inspection-inventory">Inventario o campaña</label><select class="catalog-select" id="inspection-inventory" name="inventory_tree_id"><option value="">Sin campaña formal</option>@foreach ($options['inventoryTrees'] as $participation)<option value="{{ $participation->id }}" @selected(old('inventory_tree_id', $inspection?->inventory_tree_id) === $participation->id)>{{ $participation->inventory->code }} · {{ $participation->inventory->name }}</option>@endforeach</select><p class="field-hint">Solo se muestran campañas a las que pertenece este árbol.</p>@error('inventory_tree_id')<span class="field-error">{{ $message }}</span>@enderror</div>
                    @endif
                </div>
                <p class="inspection-help-line">Las mediciones y observaciones son opcionales. Deja en blanco los datos que no fueron registrados.</p>
            </section>
            <section class="panel forestry-panel inspection-section" id="inspection-dendrometry"><span class="section-kicker">02 · DASONOMÍA</span><h2>Mediciones del individuo</h2>
                <div class="inspection-measure-layout"><div>
                    @include('inspections.clinometer-fields')
                    <div class="form-grid">
                    @foreach (['cap_cm' => ['CAP', 'cm'], 'number_of_stems' => ['Número de fustes o estípites', ''], 'form_factor' => ['Factor de forma', '0,45–0,7'], 'total_height_m' => ['Altura total', 'm'], 'commercial_height_m' => ['Altura comercial', 'm'], 'crown_x_m' => ['Copa X', 'm'], 'crown_y_m' => ['Copa Y', 'm']] as $field => [$label, $unit])
                        <div class="field" @if (in_array($field, ['total_height_m', 'commercial_height_m'], true)) data-manual-height @endif><label for="measurement-{{ $field }}">{{ $label }}{{ $unit !== '' ? ' ('.$unit.')' : '' }}</label><input id="measurement-{{ $field }}" name="{{ $field }}" type="number" inputmode="{{ $field === 'number_of_stems' ? 'numeric' : 'decimal' }}" step="{{ $field === 'number_of_stems' ? '1' : 'any' }}" min="{{ $field === 'number_of_stems' ? '1' : ($field === 'form_factor' ? '0.45' : '0') }}" @if ($field === 'form_factor') max="0.7" @endif value="{{ old($field, $inspection?->{$field}) }}" data-dendrometry-input>@error($field)<span class="field-error">{{ $message }}</span>@enderror</div>
                    @endforeach
                </div><p class="inspection-help-line">CAP se registra en centímetros; alturas y ejes de copa, en metros. El conteo de fustes se conserva como dato observado y no multiplica automáticamente los volúmenes.</p></div>
                @include('inspections.calculated')
                </div>
            </section>
            <section class="panel forestry-panel inspection-section" id="inspection-physical"><span class="section-kicker">03 · ESTADO FÍSICO-MECÁNICO</span><h2>Condiciones físicas observadas</h2><p class="section-description">Elige uno o varios órganos y después las condiciones observadas en cada uno. Una condición sin seleccionar no declara una ausencia evaluada.</p>
                @include('inspections.condition-fields', ['categories' => $options['conditionCategories']['physical_mechanical'] ?? collect(), 'assessmentLabel' => 'Estado físico-mecánico', 'fieldName' => 'physical_condition_ids', 'selectedIds' => $physicalIds])
            </section>
            <section class="panel forestry-panel inspection-section" id="inspection-phytosanitary"><span class="section-kicker">04 · ESTADO FITOSANITARIO</span><h2>Condiciones fitosanitarias observadas</h2><p class="section-description">Elige los órganos o grupos evaluados y selecciona sus condiciones. Puedes registrar varios a la vez; cada grupo conserva sus propias selecciones.</p>
                @include('inspections.condition-fields', ['categories' => $options['conditionCategories']['phytosanitary'] ?? collect(), 'assessmentLabel' => 'Estado fitosanitario', 'fieldName' => 'phytosanitary_condition_ids', 'selectedIds' => $phytosanitaryIds])
            </section>
            @include('risk.form')
            <section class="panel forestry-panel inspection-section" id="inspection-phenology"><span class="section-kicker">05 · FENOLOGÍA</span><h2>Floración y fructificación</h2><p class="section-description">Registra la fecha conocida y describe el desarrollo observado, sin seleccionar etapas predefinidas.</p>
                <div class="form-grid">
                    @foreach (['flowering' => 'Floración', 'fruiting' => 'Fructificación'] as $prefix => $label)
                        <div class="inspection-phenology"><h3>{{ $label }}</h3><div class="field"><label for="{{ $prefix }}-date">Fecha de {{ mb_strtolower($label) }}</label><input id="{{ $prefix }}-date" type="date" name="{{ $prefix }}_date" value="{{ old($prefix.'_date', $inspection?->{$prefix.'_date'}?->format('Y-m-d')) }}">@error($prefix.'_date')<span class="field-error">{{ $message }}</span>@enderror</div><div class="field"><label for="{{ $prefix }}-development">Estado de desarrollo observado</label><textarea id="{{ $prefix }}-development" name="{{ $prefix }}_development" rows="3" maxlength="20000">{{ old($prefix.'_development', $inspection?->{$prefix.'_development'}) }}</textarea>@error($prefix.'_development')<span class="field-error">{{ $message }}</span>@enderror</div></div>
                    @endforeach
                </div>
            </section>
            <section class="panel forestry-panel inspection-section" id="inspection-fauna"><span class="section-kicker">06 · FAUNA ASOCIADA</span><h2>Fauna observada</h2><p class="section-description">Puedes registrar varias categorías y describir la observación.</p>
                <div class="inspection-fauna-grid">@foreach ($options['faunaCategories'] as $category)<label class="inspection-condition" for="fauna-{{ $category->id }}"><input type="checkbox" id="fauna-{{ $category->id }}" name="fauna_category_ids[]" value="{{ $category->id }}" @checked(in_array($category->id, $faunaIds, true))><span>{{ $category->name }}@unless ($category->active)<small>Referencia histórica inactiva</small>@endunless</span></label>@endforeach</div>
                @error('fauna_category_ids')<span class="field-error">{{ $message }}</span>@enderror @error('fauna_category_ids.*')<span class="field-error">{{ $message }}</span>@enderror
                <div class="field"><label for="fauna-observations">Descripción de la fauna observada</label><textarea id="fauna-observations" name="fauna_observations" rows="3" maxlength="20000">{{ old('fauna_observations', $inspection?->fauna_observations) }}</textarea>@error('fauna_observations')<span class="field-error">{{ $message }}</span>@enderror</div>
            </section>
            <section class="panel forestry-panel inspection-section" id="inspection-observations"><span class="section-kicker">07 · OBSERVACIONES</span><h2>Observaciones generales de la inspección</h2><div class="field">@if (\App\Support\Imports\ExcelReferencePresentation::capturedObservations($inspection?->general_observations))<p class="preserve-lines">Observaciones de origen: {{ \App\Support\Imports\ExcelReferencePresentation::capturedObservations($inspection?->general_observations) }}</p>@endif<label for="general-observations">Describe otros hallazgos de la visita</label><textarea id="general-observations" name="general_observations" rows="5" maxlength="20000">{{ \App\Support\Imports\ExcelReferencePresentation::text(old('general_observations', $inspection?->general_observations), false) }}</textarea>@error('general_observations')<span class="field-error">{{ $message }}</span>@enderror</div><p class="inspection-help-line">Estas observaciones corresponden a la visita; la descripción permanente de ubicación se conserva en la ficha del árbol.</p></section>
        </div>
