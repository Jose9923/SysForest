<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Gestión institucional') · SYSFOREST</title>
    <link rel="stylesheet" href="{{ asset('css/institutional.css') }}">
    @stack('styles')
</head>
<body>
    <div class="shell">
        @include('partials.institutional-navigation')
        <div class="workspace">
            <header class="topbar">
                <div><span>Gestión institucional</span>@if ($activeOrganization ?? null)<strong class="active-organization">{{ $activeOrganization->name }}</strong>@endif @if (($currentAccessMode ?? null) === 'department_read')<span class="pill">Consulta departamental</span>@endif</div>
                @auth
                    <div class="account"><a href="{{ route('profile.show') }}">{{ request()->user()->name }}</a><form method="POST" action="{{ route('logout') }}">@csrf<button class="text-button">Cerrar sesión</button></form></div>
                @endauth
            </header>
            <main id="main-content">
                @if (session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
                <x-form-errors />
                @yield('content')
            </main>
            <footer>SYSFOREST · Inventario y gestión forestal institucional</footer>
        </div>
    </div>
    @stack('scripts')
</body>
</html>
