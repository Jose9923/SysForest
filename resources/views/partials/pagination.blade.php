@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Paginación">
        @if ($paginator->previousPageUrl())<a class="button secondary" href="{{ $paginator->previousPageUrl() }}">Anterior</a>@endif
        <span>Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
        @if ($paginator->nextPageUrl())<a class="button secondary" href="{{ $paginator->nextPageUrl() }}">Siguiente</a>@endif
    </nav>
@endif
