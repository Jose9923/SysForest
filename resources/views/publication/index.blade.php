@extends('layouts.institutional')
@section('title', 'Publicación del portal')
@include('forestry.assets')
@section('content')
    <div class="page-heading forestry-heading"><div>
        <p class="eyebrow">{{ $organization->name }} · PORTAL CIUDADANO</p>
        <h1>Administrar publicación</h1>
        <p class="muted">Autoriza expresamente qué registros institucionales pueden aparecer en Internet.</p>
    </div></div>
    <section class="panel forestry-panel" style="margin-bottom:20px">
        <h2>Antes de publicar</h2>
        <p>La organización y una campaña disponible fuera de Borrador deben estar autorizadas. Cada árbol, inspección y fotografía necesita además su propia autorización.</p>
        <p>Ocultar un propietario retira también a sus registros dependientes del portal, sin borrar su historia. Una autorización guardada no garantiza visibilidad si esa cadena está incompleta o anulada.</p>
        <p class="muted">Los textos internos y las identidades del personal permanecen privados. Las fotos públicas se entregan sin metadatos del archivo original. Los cambios posteriores de los datos forestales estructurados de un registro publicado se reflejarán en el portal.</p>
    </section>
    <nav class="forestry-actions" aria-label="Tipos de publicación" style="margin-bottom:20px;flex-wrap:wrap">
        @foreach ($kinds as $key => $label)
            <a class="button {{ $kind === $key ? '' : 'secondary' }}" href="{{ route('publication.index', ['kind' => $key]) }}" @if($kind === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    @if ($errors->any())
        <div class="panel forestry-panel" role="alert" style="margin-bottom:20px"><h2>No se guardaron los cambios</h2><ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif
    @if ($kind !== 'organizations')
        <form class="panel forestry-panel" method="GET" action="{{ route('publication.index') }}" style="margin-bottom:20px">
            <input type="hidden" name="kind" value="{{ $kind }}">
            <div class="form-grid">
                <div class="field"><label for="publication-q">{{ $kind === 'inventories' ? 'Código o nombre de la campaña' : 'Código del árbol' }}</label><input id="publication-q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="120"></div>
                <div class="field"><label for="publication-filter">Autorización guardada</label><select id="publication-filter" name="is_public"><option value="">Todas</option><option value="1" @selected((string) ($filters['is_public'] ?? '') === '1')>Autorizada</option><option value="0" @selected((string) ($filters['is_public'] ?? '') === '0')>Privada</option></select></div>
            </div>
            <div class="form-actions"><a class="button secondary" href="{{ route('publication.index', ['kind' => $kind]) }}">Limpiar</a><button class="button">Filtrar</button></div>
        </form>
    @endif
    <div class="organization-grid">
        @forelse ($records as $entry)
            <article class="panel forestry-panel">
                <h2 style="overflow-wrap:anywhere">{{ $entry['title'] }}</h2>
                <p class="muted">{{ $entry['detail'] }}</p>
                <p><span class="pill">{{ $entry['is_public'] ? 'Autorización guardada' : 'Privado' }}</span></p>
                <form action="{{ $entry['action'] }}" method="POST">
                    @csrf @method('PATCH')
                    <input type="hidden" name="publication_version" value="{{ $entry['publication_version'] }}">
                    <div class="field"><label for="visibility-{{ $entry['id'] }}">Visibilidad autorizada</label>
                        <select name="is_public" id="visibility-{{ $entry['id'] }}"><option value="0" @selected(!$entry['is_public'])>Mantener privado</option><option value="1" @selected($entry['is_public'])>Autorizar publicación</option></select>
                    </div>
                    @if ($kind === 'evidences')
                        <div class="field"><label for="caption-{{ $entry['id'] }}">Descripción pública opcional</label><textarea name="public_caption" id="caption-{{ $entry['id'] }}" rows="3" maxlength="2000" aria-describedby="caption-help-{{ $entry['id'] }}">{{ $entry['public_caption'] }}</textarea><p id="caption-help-{{ $entry['id'] }}" class="field-hint">Este texto sí será público. No incluyas nombres, contactos ni notas operativas.</p></div>
                    @endif
                    <div class="form-actions"><button class="button">Guardar autorización</button></div>
                </form>
            </article>
        @empty
            <section class="panel forestry-empty"><h2>No hay registros para estos filtros</h2><p>Ajusta la búsqueda o registra información mediante los módulos forestales.</p></section>
        @endforelse
    </div>
    @include('partials.pagination', ['paginator' => $records])
    <section class="panel forestry-panel" style="margin-top:24px">
        <h2>Historial privado de publicación</h2>
        <p class="muted">Conserva quién autorizó, ocultó o corrigió la descripción pública. Este historial no aparece en el portal.</p>
        @forelse ($revisions as $revision)
            <article style="border-top:1px solid #e3e9e5;padding:16px 0;overflow-wrap:anywhere">
                <h3>{{ $revision['kind'] }} · revisión {{ $revision['version'] }}</h3>
                <p class="muted">{{ $revision['date'] }} · {{ $revision['actor'] }}</p>
                <p>{{ ($revision['before']['is_public'] ?? false) ? 'Autorizada' : 'Privada' }} → {{ ($revision['after']['is_public'] ?? false) ? 'Autorizada' : 'Privada' }}</p>
                @if (array_key_exists('public_caption', $revision['after']) && ($revision['before']['public_caption'] ?? null) !== ($revision['after']['public_caption'] ?? null))
                    <p><strong>Descripción anterior:</strong> {{ $revision['before']['public_caption'] ?? 'Sin descripción' }}</p>
                    <p><strong>Descripción nueva:</strong> {{ $revision['after']['public_caption'] ?? 'Sin descripción' }}</p>
                @endif
            </article>
        @empty
            <p>Aún no se han cambiado autorizaciones de publicación.</p>
        @endforelse
        @include('partials.pagination', ['paginator' => $revisions])
    </section>
@endsection
