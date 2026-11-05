@php($risk = $inspection?->riskEvaluation)
<section class="panel forestry-panel"><h2>Prioridad de Gestión Fitosanitaria</h2><p><strong>{{ \App\Support\Risk\RiskPresentation::label($risk) }}</strong></p>
@if ($risk)
@php($riskResult = \App\Support\Risk\RiskPresentation::result($risk->result, $risk->methodology?->configuration ?? []))
<p>Sugerencia automática: {{ $riskResult['priority_label'] ?? ($riskResult['status'] === 'inconsistent' ? 'No emitida por contradicciones' : 'No emitida por falta de condiciones evaluadas') }} · {{ $riskResult['methodology_code'] }} · Severidad {{ $riskResult['severity_max'] ?? '—' }} · Extensión {{ $riskResult['extension_class'] ?? '—' }}.</p>
@if (isset($riskResult['historical_suggestion']))<p>Antecedente automático conservado: {{ $riskResult['historical_suggestion']['priority_label'] }}. Se calculó sin condiciones evaluadas y no acredita un diagnóstico sano.</p>@endif
<p>Condiciones registradas incluidas en la metodología: {{ $riskResult['evaluated_condition_count'] }}. Este conteo no acredita la revisión completa de todos los órganos.</p>
@if ($risk->confirmed_at)<p>Confirmada el {{ $risk->confirmed_at->format('d/m/Y H:i') }} UTC · {{ $risk->confirmer?->name ?? 'Responsable no disponible' }}.</p>@else<p>Requiere revisión y confirmación profesional.</p>@endif
@php($componentNames = ['PHT_ROOT' => 'Sistema radicular', 'PHT_STEM' => 'Fuste o estípite', 'PHT_BRANCH' => 'Ramas', 'PHT_FOLIAGE' => 'Follaje', 'PHT_FLOWER_FRUIT' => 'Flores y frutos'])
<p>Componentes afectados ({{ $risk->result['affected_component_count'] }}): {{ collect($risk->result['affected_components'])->map(fn ($code) => $componentNames[$code] ?? $code)->join(', ') ?: 'Ninguno registrado' }}.</p>
<ul>@foreach (array_merge($riskResult['errors'], $riskResult['warnings']) as $message)<li>{{ $message }}</li>@endforeach
@foreach ($riskResult['overrides'] as $override)<li>Prioridad mínima por {{ $override['label'] }}: {{ \App\Enums\Risk\RiskPriority::from($override['minimum'])->label() }}</li>@endforeach
@foreach ($riskResult['determinants'] as $item)<li>{{ $item['display_label'] }} · peso {{ $item['weight'] }}</li>@endforeach
@foreach ($riskResult['excluded_conditions'] as $item)<li>Condición registrada que no puntúa: {{ $item['display_label'] }}.</li>@endforeach</ul>
@if ($risk->observations)<p class="preserve-lines">{{ \App\Support\Imports\ExcelReferencePresentation::text($risk->observations) }}</p>@endif
@endif
<p>Prioridad ordinal de gestión; no expresa probabilidad de caída.</p></section>
