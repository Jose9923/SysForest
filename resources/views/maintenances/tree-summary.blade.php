@if ($canViewMaintenance)
    <section class="panel forestry-panel" id="tree-maintenances"><div class="maintenance-section-heading"><div><span class="section-kicker">INTERVENCIONES DEL ÁRBOL</span><h2>Mantenimientos</h2><p class="muted">Hasta diez registros recientes. Cada intervención conserva su estado, responsables y evidencias.</p></div><div class="forestry-actions">@if ($canCreateMaintenance)<a class="button" href="{{ route('trees.maintenances.create', $tree->id) }}">Nuevo mantenimiento</a>@endif<a class="button secondary" href="{{ route('maintenances.index', ['tree_id' => $tree->id]) }}">Ver todos</a></div></div>
        @include('maintenances.timeline', ['records' => $maintenanceTimeline])
    </section>
@endif
