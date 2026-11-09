@if ($wholeTreePhoto)
    <section class="panel forestry-panel tree-cover-photo tree-cover-panel">
        <span class="section-kicker">ÁRBOL COMPLETO</span><h2>Fotografía del individuo</h2>
        <a href="{{ $wholeTreePhoto['viewUrl'] }}" target="_blank" rel="noopener" aria-label="Abrir fotografía completa de {{ $tree->code }}">
            <img src="{{ $wholeTreePhoto['viewUrl'] }}" alt="Árbol completo {{ $tree->code }}" decoding="async">
        </a>
        <p class="field-hint">{{ $wholeTreePhoto['sourceLabel'] }} · {{ $wholeTreePhoto['capturedAt'] ?: $wholeTreePhoto['createdAt'] }}</p>
        @if ($wholeTreePhoto['caption'])<p>{{ $wholeTreePhoto['caption'] }}</p>@endif
    </section>
@elseif ($treeEvidenceGallery)
    <section class="panel forestry-panel tree-cover-panel"><h2>Fotografía del árbol completo</h2><p class="field-hint">No hay una fotografía disponible con el tipo «Individuo completo».</p></section>
@endif
