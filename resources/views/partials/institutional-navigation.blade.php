<aside class="sidebar">
    <a class="brand" href="{{ route('organizations.index') }}" aria-label="SYSFOREST, organizaciones"><img src="{{ asset('images/sysforest-logo.jpeg') }}" alt="SYSFOREST" width="188" height="103"></a>
    <p class="nav-label">ESPACIO INSTITUCIONAL</p>
    <nav aria-label="Navegación principal">
        <a class="{{ request()->is('organizations*') ? 'selected' : '' }}" href="{{ route('organizations.index') }}"><span aria-hidden="true">▦</span> Organizaciones</a>
        <a class="{{ request()->is('territories*') ? 'selected' : '' }}" href="{{ route('territories.index') }}"><span aria-hidden="true">◎</span> Territorios</a>
        <a href="/"><span aria-hidden="true">◉</span> Estado del sistema</a>
    </nav>
    <div class="sidebar-note"><span class="dot"></span> Entorno local <p>El territorio es el punto de partida.</p></div>
</aside>
