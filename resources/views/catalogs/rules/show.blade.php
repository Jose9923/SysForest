@extends('layouts.institutional')
@section('title', $version->name)
@push('styles')<link rel="stylesheet" href="{{ asset('css/catalogs.css') }}">@endpush
@section('content')
<a class="back-link" href="{{ route('classification.index') }}">← Versiones de clasificación</a>
<div class="page-heading"><div><p class="eyebrow">{{ $version->code }} · {{ $version->active ? 'ACTIVA' : 'INACTIVA' }}</p><h1>{{ $version->name }}</h1><p class="muted">{{ $version->description }}</p></div>@if ($canManage)<a class="button secondary" href="{{ route('classification.edit', $version->id) }}">Editar disponibilidad y nombre</a>@endif</div>
<div class="detail-grid">
    <section class="panel"><h2>Clases de diámetro y altura</h2><p>Para medidas positivas, cada clase incluye su límite superior y excluye el inferior.</p><dl class="details"><div><dt>Intervalo de diámetro</dt><dd>{{ $version->diameter_step }} {{ $version->diameter_unit }}</dd></div><div><dt>Intervalo de altura total</dt><dd>{{ $version->height_step }} {{ $version->height_unit }}</dd></div></dl>
        <hr><h3>Probar una medida</h3><p class="help">Usa punto decimal y hasta seis decimales. Esta consulta no registra una inspección.</p>
        <form method="GET" action="{{ route('classification.show', $version->id) }}" class="catalog-filters"><div class="field"><label for="kind">Medida</label><select name="kind" id="kind"><option value="diameter" @selected(($input['kind'] ?? 'diameter') === 'diameter')>DAP (cm)</option><option value="height" @selected(($input['kind'] ?? '') === 'height')>Altura total (m)</option></select></div><x-field name="value" label="Valor positivo" :value="$input['value'] ?? ''" inputmode="decimal" required maxlength="32" /><button class="button">Calcular clase</button></form>
        @if ($result)<div class="catalog-rule-result" role="status"><strong>Clase {{ $result->classNumber }}</strong><p>{{ $result->label }}</p></div>@endif
    </section>
    <section class="panel"><h2>Bandas de madurez</h2><p class="muted">Referencia por clase diamétrica. Su aplicación a inspecciones se incorporará en una fase posterior.</p><dl class="details">@foreach ($version->maturityBands as $band)<div><dt>{{ $band->maturityState->name }}</dt><dd>@if ($band->max_class)Clases {{ $band->min_class }} a {{ $band->max_class }}@else Clase {{ $band->min_class }} en adelante
                @endif</dd></div>@endforeach</dl></section>
</div>
@endsection
