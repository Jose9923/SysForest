<aside class="sidebar">
    <a class="brand" href="{{ route('organizations.index') }}" aria-label="SYSFOREST, organizaciones"><img src="{{ asset('images/sysforest-logo.jpeg') }}" alt="SYSFOREST" width="188" height="103"></a>
    <p class="nav-label">ESPACIO INSTITUCIONAL</p>
    <nav aria-label="Navegación principal">
        <a class="{{ request()->is('organizations*') ? 'selected' : '' }}" href="{{ route('organizations.index') }}"><span aria-hidden="true">▦</span> Organizaciones</a>
        <a class="{{ request()->is('territories*') ? 'selected' : '' }}" href="{{ route('territories.index') }}"><span aria-hidden="true">◎</span> Territorios</a>
        @can('inventories.view')<a class="{{ request()->is('inventories*') ? 'selected' : '' }}" href="{{ route('inventories.index') }}"><span aria-hidden="true">▤</span> Inventarios</a>@endcan
        @can('trees.view')<a class="{{ request()->is('trees*') && !request()->is('trees/*/inspections*', 'trees/*/maintenances*') ? 'selected' : '' }}" href="{{ route('trees.index') }}"><span aria-hidden="true">♧</span> Árboles</a>@endcan
        @can('maps.view')<a class="{{ request()->is('map') ? 'selected' : '' }}" href="{{ route('maps.index') }}"><span aria-hidden="true">◎</span> Cartografía</a>@endcan
        @can('imports.view')<a class="{{ request()->is('imports*') ? 'selected' : '' }}" href="{{ route('imports.index') }}"><span aria-hidden="true">↥</span> Importaciones</a>@endcan
        @can('reports.view')<a class="{{ request()->is('analytics*') ? 'selected' : '' }}" href="{{ route('analytics.index') }}"><span aria-hidden="true">◴</span> Diagramas y gráficas</a>@endcan
        @can('reports.view')<a class="{{ request()->is('reports*') ? 'selected' : '' }}" href="{{ route('reports.index') }}"><span aria-hidden="true">▥</span> Reportes</a>@endcan
        @can('inspections.view')<a class="{{ request()->is('inspections*', 'trees/*/inspections*') ? 'selected' : '' }}" href="{{ route('inspections.index') }}"><span aria-hidden="true">▧</span> Inspecciones</a>@endcan
        @can('maintenances.view')<a class="{{ request()->is('maintenances*', 'trees/*/maintenances*') ? 'selected' : '' }}" href="{{ route('maintenances.index') }}"><span aria-hidden="true">♧</span> Mantenimientos</a>@endcan
        @can('memberships.view')<a class="{{ request()->is('memberships*', 'invitations*') ? 'selected' : '' }}" href="{{ route('memberships.index') }}"><span aria-hidden="true">♧</span> Usuarios de organización</a>@endcan
        @can('users.view')<a class="{{ request()->is('users*') ? 'selected' : '' }}" href="{{ route('users.index') }}"><span aria-hidden="true">▤</span> Usuarios globales</a>@endcan
        @can('catalogs.view')<a class="{{ request()->is('admin/catalogs*') ? 'selected' : '' }}" href="{{ route('catalogs.dashboard') }}"><span aria-hidden="true">❧</span> Catálogos forestales</a>@endcan
        <a class="{{ request()->is('profile*') ? 'selected' : '' }}" href="{{ route('profile.show') }}"><span aria-hidden="true">○</span> Mi perfil</a>
        @can('audit.view')<a class="{{ request()->is('audit*') ? 'selected' : '' }}" href="{{ route('audit.index') }}"><span aria-hidden="true">◷</span> Auditoría</a>@endcan
        @can('publication.manage')<a class="{{ request()->is('publication*') ? 'selected' : '' }}" href="{{ route('publication.index') }}"><span aria-hidden="true">◎</span> Publicación ciudadana</a>@endcan
        <a href="{{ route('public.home') }}"><span aria-hidden="true">♧</span> Portal público</a>
        <a href="/"><span aria-hidden="true">◉</span> Estado del sistema</a>
    </nav>
    <div class="sidebar-note"><span class="dot"></span> Entorno local <p>El territorio es el punto de partida.</p></div>
</aside>
