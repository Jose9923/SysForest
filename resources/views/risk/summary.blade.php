@php($risk = $inspection?->riskEvaluation)
<section class="panel forestry-panel"><h2>Prioridad de Gestión Fitosanitaria</h2><p><strong>{{ \App\Support\RiskPresentation::label($risk) }}</strong></p>
@if ($risk)
@php($riskResult = \App\Support\RiskPresentation::result($risk->result, $risk->methodology?->configuration ?? []))
<p>Sugerencia automática: {{ $risk->result['priority_label'] ?? 'No emitida por contradicciones' }} · {{ $risk->result['methodology_code'] }} · Severidad {{ $risk->result['severity_max'] ?? '—' }} · Extensión {{ $risk->result['extension_class'] ?? '—' }}.</p>
@if ($risk->confirmed_at)<p>Confirmada el {{ $risk->confirmed_at->format('d/m/Y H:i') }} UTC · {{ $risk->confirmer?->name ?? 'Responsable no disponible' }}.</p>@else<p>Requiere revisión y confirmación profesional.</p>@endif
@php($componentNames = ['PHT_ROOT' => 'Sistema radicular', 'PHT_STEM' => 'Fuste o estípite', 'PHT_BRANCH' => 'Ramas', 'PHT_FOLIAGE' => 'Follaje', 'PHT_FLOWER_FRUIT' => 'Flores y frutos'])
<p>Componentes afectados ({{ $risk->result['affected_component_count'] }}): {{ collect($risk->result['affected_components'])->map(fn ($code) => $componentNames[$code] ?? $code)->join(', ') ?: 'Ninguno registrado' }}.</p>
<ul>@foreach (array_merge($riskResult['errors'], $riskResult['warnings']) as $message)<li>{{ $message }}</li>@endforeach
@foreach ($riskResult['overrides'] as $override)<li>Prioridad mínima por {{ $override['label'] }}: {{ \App\Enums\RiskPriority::from($override['minimum'])->label() }}</li>@endforeach
@foreach ($riskResult['determinants'] as $item)<li>{{ $item['display_label'] }} · peso {{ $item['weight'] }}</li>@endforeach</ul>
@if ($risk->observations)<p class="preserve-lines">{{ \App\Support\ExcelReferencePresentation::text($risk->observations) }}</p>@endif
@endif
<p>Prioridad ordinal de gestión; no expresa probabilidad de caída.</p></section>
