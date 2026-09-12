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
                <span>Gestión institucional</span>
                @auth
                    <div class="account"><span>{{ request()->user()->name }}</span><form method="POST" action="{{ route('phase3.logout') }}">@csrf<button class="text-button">Cerrar acceso</button></form></div>
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
