<section class="public-indicators" aria-label="Indicadores del inventario publicado">
    @foreach (['trees' => 'Árboles publicados', 'species' => 'Especies', 'municipalities' => 'Municipios', 'organizations' => 'Organizaciones'] as $key => $label)
        <div class="public-indicator"><span>{{ $label }}</span><strong>{{ number_format($indicators[$key] ?? 0, 0, ',', '.') }}</strong></div>
    @endforeach
</section>
