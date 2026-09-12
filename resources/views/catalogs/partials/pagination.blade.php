@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Paginación">
        @if ($paginator->onFirstPage())<span>Anterior</span>@else<a class="text-link" href="{{ $paginator->previousPageUrl() }}">← Anterior</a>@endif
        <span>Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
        @if ($paginator->hasMorePages())<a class="text-link" href="{{ $paginator->nextPageUrl() }}">Siguiente →</a>@else<span>Siguiente</span>@endif
    </nav>
@endif
