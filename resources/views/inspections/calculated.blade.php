<aside class="inspection-calculated" data-calculation-panel>
    <h3>Resultados de las mediciones</h3><span class="pill">Calculado automáticamente</span>
    <dl class="inspection-result-grid">
        @foreach (['total_height_m' => 'Altura total', 'commercial_height_m' => 'Altura comercial'] as $field => $label)
            <div data-clinometer-result @unless ($clinometerEnabled ?? false) hidden @endunless><dt @if ($field === 'commercial_height_m') data-clinometer-commercial-label @endif>{{ $field === 'commercial_height_m' && ($clinometerMethod ?? 'signed_angles') === 'signed_angles' ? 'Altura comercial (fuste)' : $label.' con inclinómetro' }}</dt><dd><output data-calculated-output="{{ $field }}">{{ $inspection?->{$field} ?? '—' }}</output> <small>m</small></dd></div>
        @endforeach
        @foreach (['dap_m' => ['DAP', 'm'], 'radius_m' => ['Radio', 'm'], 'basal_area_m2' => ['Área basal', 'm²'], 'commercial_volume_m3' => ['Volumen comercial', 'm³'], 'total_volume_m3' => ['Volumen total', 'm³'], 'diameter_class_number' => ['Clase diamétrica', ''], 'height_class_number' => ['Clase de altura', '']] as $field => [$label, $unit])
            <div><dt>{{ $label }}</dt><dd><output data-calculated-output="{{ $field }}">{{ $inspection?->{$field} ?? '—' }}</output> <small>{{ $unit }}</small></dd></div>
        @endforeach
        <div class="wide"><dt>Estado de madurez</dt><dd><output data-calculated-output="maturity_name">{{ $inspection?->maturityState?->name ?? '—' }}</output></dd></div>
    </dl>
    <p class="inspection-calculation-status" data-calculation-status role="status" aria-live="polite">Ingresa las mediciones conocidas para ver los resultados.</p>
    <p class="field-hint">Los campos sin información permanecen sin resultado cuando faltan mediciones necesarias.</p>
    <noscript><p class="inspection-help-line">Los resultados se calcularán al guardar la inspección.</p></noscript>
</aside>
