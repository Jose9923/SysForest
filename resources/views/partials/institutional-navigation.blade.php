<aside class="sidebar">
    <a class="brand" href="{{ route('organizations.index') }}" aria-label="SYSFOREST, organizaciones"><img src="{{ asset('images/sysforest-logo.jpeg') }}" alt="SYSFOREST" width="188" height="103"></a>
    <p class="nav-label">ESPACIO INSTITUCIONAL</p>
    <nav aria-label="Navegación principal">
        <a class="{{ request()->is('organizations*') ? 'selected' : '' }}" href="{{ route('organizations.index') }}"><span aria-hidden="true">▦</span> Organizaciones</a>
        <a class="{{ request()->is('territories*') ? 'selected' : '' }}" href="{{ route('territories.index') }}"><span aria-hidden="true">◎</span> Territorios</a>
        @can('inventories.view')<a class="{{ request()->is('inventories*') ? 'selected' : '' }}" href="{{ route('inventories.index') }}"><span aria-hidden="true">▤</span> Inventarios</a>@endcan
        @can('trees.view')<a class="{{ request()->is('trees*') ? 'selected' : '' }}" href="{{ route('trees.index') }}"><span aria-hidden="true">♧</span> Árboles</a>@endcan
        @can('memberships.view')<a class="{{ request()->is('memberships*', 'invitations*') ? 'selected' : '' }}" href="{{ route('memberships.index') }}"><span aria-hidden="true">♧</span> Usuarios de organización</a>@endcan
        @can('users.view')<a class="{{ request()->is('users*') ? 'selected' : '' }}" href="{{ route('users.index') }}"><span aria-hidden="true">▤</span> Usuarios globales</a>@endcan
        @can('catalogs.view')<a class="{{ request()->is('admin/catalogs*') ? 'selected' : '' }}" href="{{ route('catalogs.dashboard') }}"><span aria-hidden="true">❧</span> Catálogos forestales</a>@endcan
        <a class="{{ request()->is('profile*') ? 'selected' : '' }}" href="{{ route('profile.show') }}"><span aria-hidden="true">○</span> Mi perfil</a>
        <a href="/"><span aria-hidden="true">◉</span> Estado del sistema</a>
    </nav>
    <div class="sidebar-note"><span class="dot"></span> Entorno local <p>El territorio es el punto de partida.</p></div>
</aside>
