@if ($measuredInspection->uses_clinometer)
    @php
        $signedAngles = $measuredInspection->clinometer_formula_version === 'INCLINOMETER_V2';
        $clinometerReadFields = ['clinometer_distance_m' => ['Distancia horizontal al árbol', 'm'], 'clinometer_elevation_degrees' => ['Ángulo al ápice: altura total', '°']];
        $clinometerReadFields += $signedAngles
            ? ['clinometer_stem_elevation_degrees' => ['Ángulo a la base de la copa: fuste', '°'], 'clinometer_lower_degrees' => ['Ángulo hacia la base del árbol', '°']]
            : ['clinometer_depression_degrees' => ['Ángulo de depresión', '°'], 'clinometer_observer_height_m' => ['Altura de la persona', 'm']];
    @endphp
    <div class="inspection-clinometer-read">
        <h3>Alturas medidas con inclinómetro</h3>
        <dl class="inspection-result-grid">
            @foreach ($clinometerReadFields as $field => [$label, $unit])
                <div><dt>{{ $label }}</dt><dd>{{ $measuredInspection->{$field} ?? '—' }} <small>{{ $unit }}</small></dd></div>
            @endforeach
        </dl>
        <p class="field-hint">{{ $signedAngles ? 'Se usaron ángulos superiores independientes hacia el ápice y la base de la copa, con una misma lectura hacia la base del árbol. La altura comercial corresponde al fuste.' : 'Medición con el método anterior: se utilizó el mismo ángulo de elevación en ambas alturas y la altura de la persona en la comercial.' }}</p>
    </div>
@endif
