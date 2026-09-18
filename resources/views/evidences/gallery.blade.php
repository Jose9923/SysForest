<section class="panel forestry-panel evidence-gallery" id="{{ $gallery['domId'] }}">
    <div class="evidence-section-heading"><div><span class="section-kicker">EVIDENCIAS FOTOGRÁFICAS</span><h2>{{ $gallery['title'] }} <small>({{ $gallery['total'] }})</small></h2><p class="muted">{{ $gallery['description'] }}</p></div>
        <div class="forestry-actions">
            @if ($gallery['canUpload'])<a class="button" href="{{ $gallery['uploadUrl'] }}">Agregar fotografías</a>@endif
            @if (($showAllLink ?? true) && $gallery['indexUrl'])<a class="button secondary" href="{{ $gallery['indexUrl'] }}">Ver todas</a>@endif
        </div>
    </div>
    <div class="evidence-grid">
        @forelse ($gallery['items'] as $item)
            <article class="evidence-card" data-evidence-id="{{ $item['id'] }}">
                <a class="evidence-thumb" href="{{ $item['showUrl'] }}" aria-label="Ver evidencia: {{ $item['caption'] ?? $item['typeName'] }}">
                    <img src="{{ $item['viewUrl'] }}" alt="{{ $item['caption'] ?? $item['typeName'] }}" loading="lazy" decoding="async" data-evidence-image>
                    <span class="evidence-image-unavailable" hidden>No se pudo cargar la imagen. Abre la evidencia para consultar sus datos.</span>
                </a>
                <div class="evidence-card-body"><div class="evidence-card-type"><span class="pill">{{ $item['typeName'] }}</span>@if ($item['isVoided'])<span class="pill danger">Anulada</span>@endif</div>
                    @unless ($item['typeActive'])<p class="evidence-history-note">Tipo histórico inactivo</p>@endunless
                    <p class="evidence-caption preserve-lines">{{ $item['caption'] ?? 'Sin descripción' }}</p>
                    <dl class="evidence-card-meta"><div><dt>Captura</dt><dd>{{ $item['capturedAt'] ?? 'No informada' }}</dd></div><div><dt>Subida</dt><dd>{{ $item['createdAt'] }}</dd></div><div><dt>Por</dt><dd>{{ $item['uploadedByName'] ?? 'No disponible' }}</dd></div></dl>
                    <div class="evidence-card-actions"><a href="{{ $item['showUrl'] }}">Ver evidencia</a>@if ($item['canUpdate'])<a href="{{ $item['showUrl'] }}#evidence-metadata">Editar datos</a>@elseif ($item['canRestore'])<a href="{{ $item['showUrl'] }}#evidence-restore">Restaurar</a>@endif</div>
                </div>
            </article>
        @empty
            <div class="evidence-empty"><span aria-hidden="true">▧</span><h3>Sin fotografías registradas</h3><p>Las evidencias de este registro aparecerán aquí.</p>@if ($gallery['canUpload'])<a class="text-link" href="{{ $gallery['uploadUrl'] }}">Agregar la primera fotografía →</a>@endif</div>
        @endforelse
    </div>
</section>
