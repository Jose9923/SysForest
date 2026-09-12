<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title', 'Acceso') · SysForest</title>
    <link rel="stylesheet" href="{{ asset('css/institutional.css') }}">
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}">
</head>
<body class="auth-body">
    <a class="identity-skip" href="#auth-content">Ir al formulario</a>
    <div class="auth-shell">
        <aside class="auth-brand-panel" aria-label="SysForest">
            <div class="auth-brand-top">
                <div class="auth-logo"><img src="{{ asset('images/sysforest-logo.jpeg') }}" alt="SysForest" width="220" height="120"></div>
                <span class="auth-product-name">SysForest</span>
            </div>
            <div class="auth-brand-copy">
                <p class="auth-overline">Gestión institucional</p>
                <h1>Inventario y gestión forestal inteligente</h1>
                <p>Un espacio de trabajo para los equipos que cuidan el patrimonio forestal de sus organizaciones.</p>
            </div>
            <div class="auth-brand-footer"><span class="auth-brand-line" aria-hidden="true"></span>Personas, organizaciones y territorio.</div>
        </aside>
        <main class="auth-main" id="auth-content">
            <div class="auth-card">
                <p class="eyebrow">@yield('eyebrow', 'ACCESO A SYSFOREST')</p>
                <h2>@yield('heading', 'Bienvenido')</h2>
                @hasSection('intro')<p class="auth-intro">@yield('intro')</p>@endif
                @if (session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
                <x-form-errors />
                @yield('content')
                <div class="auth-card-footer">
                    <span>SysForest · Gestión forestal institucional</span>
                    @auth
                        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="text-button">Cerrar sesión</button></form>
                    @endauth
                </div>
            </div>
        </main>
    </div>
</body>
</html>
