<div class="inspection-clinometer">
    <input type="hidden" name="uses_clinometer" value="0">
    <label class="inspection-condition inspection-clinometer-toggle" for="uses-clinometer">
        <input id="uses-clinometer" type="checkbox" name="uses_clinometer" value="1" @checked($clinometerEnabled) data-clinometer-toggle data-dendrometry-input aria-controls="clinometer-measurements" aria-describedby="clinometer-help">
        <span>Inclinómetro<small>Calcular las alturas a partir de las mediciones de campo</small></span>
    </label>
    @error('uses_clinometer')<span class="field-error">{{ $message }}</span>@enderror
    <fieldset id="clinometer-measurements" class="inspection-clinometer-fields" data-clinometer-fields>
        <legend>Mediciones con inclinómetro</legend>
        <p id="clinometer-help" class="field-hint">Registra la distancia horizontal en metros y los ángulos en grados.</p>
        @if ($clinometerLegacyAvailable)
            <div class="field inspection-clinometer-method"><label for="clinometer-method">Método de la medición</label><select id="clinometer-method" name="clinometer_method" data-clinometer-method><option value="legacy" @selected($clinometerMethod === 'legacy')>Conservar el método anterior</option><option value="signed_angles" @selected($clinometerMethod === 'signed_angles')>Nueva fórmula: ángulos al ápice, al fuste y a la base</option></select><p class="field-hint">Para cambiar de método, registra los ángulos de la nueva medición. No se deduce el ángulo del fuste.</p></div>
        @else
            <input type="hidden" name="clinometer_method" value="signed_angles" data-clinometer-method>
        @endif
        @error('clinometer_method')<p class="field-error">{{ $message }}</p>@enderror
        <div class="form-grid">
            @foreach (['clinometer_distance_m' => ['Distancia horizontal al árbol (m)', false], 'clinometer_elevation_degrees' => ['Ángulo al ápice: altura total (°)', true]] as $field => [$label, $isAngle])
                <div class="field"><label for="measurement-{{ $field }}">{{ $label }} *</label><input id="measurement-{{ $field }}" type="number" inputmode="decimal" name="{{ $field }}" min="{{ $isAngle ? '0' : '0.000001' }}" @if ($isAngle) max="89.999999" @endif step="0.000001" value="{{ old($field, $inspection?->{$field}) }}" data-dendrometry-input>@error($field)<span class="field-error">{{ $message }}</span>@enderror</div>
            @endforeach
        </div>
        <fieldset class="inspection-clinometer-subfields" data-clinometer-method-fields="signed_angles">
            <legend>Fuste y base del árbol</legend>
            <div class="form-grid">
                <div class="field"><label for="measurement-clinometer_stem_elevation_degrees">Ángulo a la base de la copa: fuste (°) *</label><input id="measurement-clinometer_stem_elevation_degrees" type="number" inputmode="decimal" name="clinometer_stem_elevation_degrees" min="0" max="89.999999" step="0.000001" value="{{ old('clinometer_stem_elevation_degrees', $inspection?->clinometer_stem_elevation_degrees) }}" data-dendrometry-input>@error('clinometer_stem_elevation_degrees')<span class="field-error">{{ $message }}</span>@enderror</div>
                <div class="field"><label for="measurement-clinometer_lower_degrees">Ángulo hacia la base del árbol (°) *</label><input id="measurement-clinometer_lower_degrees" type="number" name="clinometer_lower_degrees" min="-89.999999" max="89.999999" step="0.000001" value="{{ old('clinometer_lower_degrees', $inspection?->clinometer_lower_degrees) }}" data-dendrometry-input aria-describedby="clinometer-lower-help"><p id="clinometer-lower-help" class="field-hint">Usa un valor negativo al mirar hacia abajo, por ejemplo −10°. Si la base está por encima de la horizontal, usa un valor positivo.</p>@error('clinometer_lower_degrees')<span class="field-error">{{ $message }}</span>@enderror</div>
            </div>
            <p class="inspection-help-line">Altura total = distancia × [tan(ángulo al ápice) − tan(ángulo a la base)].<br>Altura comercial (fuste) = distancia × [tan(ángulo a la base de la copa) − tan(ángulo a la base del árbol)].</p>
            <p class="field-hint">La altura comercial corresponde al fuste, desde la base del individuo hasta la base de la copa. No se utiliza la altura de la persona.</p>
        </fieldset>
        @if ($clinometerLegacyAvailable)
            <fieldset class="inspection-clinometer-subfields" data-clinometer-method-fields="legacy">
                <legend>Observaciones del método anterior</legend>
                <div class="form-grid">
                    @foreach (['clinometer_depression_degrees' => ['Ángulo de depresión, como magnitud positiva (°)', true], 'clinometer_observer_height_m' => ['Altura de la persona (m)', false]] as $field => [$label, $isAngle])
                        <div class="field"><label for="measurement-{{ $field }}">{{ $label }} *</label><input id="measurement-{{ $field }}" type="number" inputmode="decimal" name="{{ $field }}" min="{{ $isAngle ? '0' : '0.000001' }}" @if ($isAngle) max="89.999999" @endif step="0.000001" value="{{ old($field, $inspection?->{$field}) }}" data-dendrometry-input>@error($field)<span class="field-error">{{ $message }}</span>@enderror</div>
                    @endforeach
                </div>
                <p class="inspection-help-line">Este antecedente usa el mismo ángulo superior en ambas alturas: la total suma los tramos de elevación y depresión; la comercial suma el tramo de elevación y la altura de la persona. Cambiar de fórmula requiere registrar los nuevos ángulos.</p>
            </fieldset>
        @endif
        @foreach (['total_height_m', 'commercial_height_m'] as $field)
            @error($field)<p class="field-error">{{ $message }}</p>@enderror
        @endforeach
    </fieldset>
    <noscript><p class="inspection-help-line">Marca Inclinómetro y completa las cuatro mediciones del método seleccionado para calcular al guardar. En ese caso, los valores manuales de altura no se utilizan.</p></noscript>
</div>
