<section class="panel forestry-panel"><div class="inspection-summary-head"><div><span class="section-kicker">HISTORIAL DE VISITAS</span><h2>Inspecciones del árbol</h2></div><a class="text-link" href="{{ route('inspections.index', ['tree_id' => $tree->id]) }}">Consultar todo el historial →</a></div>
    <ol class="inspection-timeline">@forelse ($inspectionTimeline as $visit)
        <li><article><div><strong>{{ $visit->inspection_date->format('d/m/Y') }}</strong>@if ($latestInspection?->id === $visit->id) <span class="pill">Última inspección</span>@endif<p>{{ $visit->inventoryTree?->inventory?->name ?? 'Sin campaña formal' }}</p><p>{{ $visit->creator?->name ?? 'Responsable no disponible' }} · {{ $visit->maturityState?->name ?? 'Madurez sin calcular' }}</p></div><a class="button secondary" href="{{ route('inspections.show', $visit->id) }}">Ver inspección</a></article></li>
    @empty
        <li><p class="muted">Sin visitas registradas.</p></li>
    @endforelse</ol>
</section>
