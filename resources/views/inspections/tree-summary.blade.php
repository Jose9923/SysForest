<section class="panel forestry-panel inspection-summary" id="tree-inspections">
    <div class="inspection-summary-head"><div><span class="section-kicker">SEGUIMIENTO FORESTAL</span><h2>Última inspección</h2>@if ($latestInspection)<p>Visita del {{ $latestInspection->inspection_date->format('d/m/Y') }} · {{ $latestInspection->creator?->name ?? 'Responsable no disponible' }}</p>@endif</div>
        @if ($canCreateInspection)<a class="button" href="{{ route('trees.inspections.create', $tree->id) }}">+ Nueva inspección</a>@endif
    </div>
    @can('inspections.view')<p>Prioridad fitosanitaria: <strong>{{ \App\Support\Risk\RiskPresentation::label($latestInspection?->riskEvaluation) }}</strong></p>@endcan
    @if ($latestInspection)
        @include('carbon.estimate', ['carbonInspection' => $latestInspection])
        <dl class="inspection-summary-measures"><div><dt>CAP</dt><dd>{{ $latestInspection->cap_cm ?? '—' }} <small>cm</small></dd></div><div><dt>DAP</dt><dd>{{ $latestInspection->dap_m ?? '—' }} <small>m</small></dd></div><div><dt>Altura total</dt><dd>{{ $latestInspection->total_height_m ?? '—' }} <small>m</small></dd></div><div><dt>Madurez</dt><dd>{{ $latestInspection->maturityState?->name ?? 'Sin calcular' }}</dd></div></dl>
        <details class="inspection-filter-details"><summary>Condiciones registradas en esta inspección ({{ $latestInspection->conditions->count() }})</summary><ul class="inspection-found-list">@forelse ($latestInspection->conditions as $condition)<li>{{ $condition->name }}<small>{{ $condition->assessment_type === 'physical_mechanical' ? 'Físico-mecánico' : 'Fitosanitario' }} · {{ $condition->category->name }}{{ !$condition->active || !$condition->category->active ? ' · referencia histórica inactiva' : '' }}</small></li>@empty<li>Sin condiciones registradas.</li>@endforelse</ul></details>
        <div class="form-actions"><a class="button secondary" href="{{ route('inspections.show', $latestInspection->id) }}">Ver inspección completa →</a></div>
    @else
        <p class="muted">Este árbol todavía no tiene inspecciones. La primera visita conservará sus propias mediciones y observaciones.</p>
    @endif
</section>
