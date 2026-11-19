<div class="inspection-clinometer">
    <input type="hidden" name="uses_clinometer" value="0">
    <label class="inspection-condition inspection-clinometer-toggle" for="uses-clinometer">
        <input id="uses-clinometer" type="checkbox" name="uses_clinometer" value="1" @checked($clinometerEnabled) data-clinometer-toggle data-dendrometry-input aria-controls="clinometer-measurements" aria-describedby="clinometer-help">
        <span>Inclinómetro<small>Calcular las alturas a partir de las mediciones de campo</small></span>
    </label>
    @error('uses_clinometer')<span class="field-error">{{ $message }}</span>@enderror
    <fieldset id="clinometer-measurements" class="inspection-clinometer-fields" data-clinometer-fields>
        <legend>Mediciones con inclinómetro</legend>
        <p id="clinometer-help" class="field-hint">Registra la distancia horizontal al árbol y los ángulos en grados, como valores positivos. El mismo ángulo de elevación se utiliza para ambas alturas.</p>
        <div class="form-grid">
            @foreach (['clinometer_distance_m' => ['Distancia horizontal al árbol (m)', false], 'clinometer_elevation_degrees' => ['Ángulo de elevación (°)', true], 'clinometer_depression_degrees' => ['Ángulo de depresión (°)', true], 'clinometer_observer_height_m' => ['Altura de la persona (m)', false]] as $field => [$label, $isAngle])
                <div class="field"><label for="measurement-{{ $field }}">{{ $label }} *</label><input id="measurement-{{ $field }}" type="number" inputmode="decimal" name="{{ $field }}" min="{{ $isAngle ? '0' : '0.000001' }}" @if ($isAngle) max="89.999999" @endif step="0.000001" value="{{ old($field, $inspection?->{$field}) }}" data-dendrometry-input>@error($field)<span class="field-error">{{ $message }}</span>@enderror</div>
            @endforeach
        </div>
        <p class="inspection-help-line">La altura total suma los tramos de elevación y depresión. La comercial suma el tramo de elevación y la altura de la persona. Revisa las mediciones si la comercial supera la total.</p>
        @foreach (['total_height_m', 'commercial_height_m'] as $field)
            @error($field)<p class="field-error">{{ $message }}</p>@enderror
        @endforeach
    </fieldset>
    <noscript><p class="inspection-help-line">Marca Inclinómetro y completa sus cuatro mediciones para calcular al guardar. En ese caso, los valores manuales de altura no se utilizan.</p></noscript>
</div>
