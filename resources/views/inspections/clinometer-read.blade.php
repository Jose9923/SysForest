@if ($measuredInspection->uses_clinometer)
    <div class="inspection-clinometer-read">
        <h3>Alturas medidas con inclinómetro</h3>
        <dl class="inspection-result-grid">
            @foreach (['clinometer_distance_m' => ['Distancia horizontal al árbol', 'm'], 'clinometer_elevation_degrees' => ['Ángulo de elevación', '°'], 'clinometer_depression_degrees' => ['Ángulo de depresión', '°'], 'clinometer_observer_height_m' => ['Altura de la persona', 'm']] as $field => [$label, $unit])
                <div><dt>{{ $label }}</dt><dd>{{ $measuredInspection->{$field} ?? '—' }} <small>{{ $unit }}</small></dd></div>
            @endforeach
        </dl>
        <p class="field-hint">Se utilizó el mismo ángulo de elevación para calcular la altura total y la comercial.</p>
    </div>
@endif
