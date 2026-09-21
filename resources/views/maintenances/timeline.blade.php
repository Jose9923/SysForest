<ol class="maintenance-timeline">
    @forelse ($records as $item)
        <li><article class="maintenance-timeline-row">
            <div class="maintenance-date"><span>Fecha programada</span><strong>{{ $item['scheduledDate'] ?? 'Por programar' }}</strong></div>
            <div class="maintenance-timeline-main"><a class="maintenance-code" href="{{ $item['url'] }}">{{ $item['code'] }}</a><h3>{{ $item['typeName'] }}</h3><p><a href="{{ route('trees.show', $item['treeId']) }}">{{ $item['treeCode'] }}</a> · {{ $item['assigneeName'] ?? 'Sin responsable asignado' }}</p>@unless ($item['typeActive'])<small>Tipo histórico inactivo</small>@endunless @if ($item['performedDate'])<small>Realizado el {{ $item['performedDate'] }}</small>@endif</div>
            <div class="maintenance-row-flags"><span class="pill maintenance-state-{{ $item['status'] }}">{{ $item['statusLabel'] }}</span><span class="maintenance-priority maintenance-priority-{{ $item['priority'] }}">{{ $item['priorityLabel'] }}</span>@if ($item['isVoided'])<span class="pill danger">Registro anulado</span>@endif</div>
            <a class="button secondary maintenance-open" href="{{ $item['url'] }}">Ver ficha</a>
        </article></li>
    @empty
        <li class="maintenance-empty"><span aria-hidden="true">♧</span><h3>Sin mantenimientos registrados</h3><p>Las intervenciones autorizadas aparecerán aquí con su programación y seguimiento.</p></li>
    @endforelse
</ol>
