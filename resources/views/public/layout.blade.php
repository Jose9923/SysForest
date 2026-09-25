<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    @if ($seo['canonical'] ?? null)<link rel="canonical" href="{{ $seo['canonical'] }}">@endif
    <link rel="stylesheet" href="{{ asset('css/public-portal.css') }}">
    @stack('styles')
</head>
<body class="public-portal">
    <a class="public-skip" href="#public-main">Saltar al contenido</a>
    <header class="public-header"><div class="public-header-inner">
        <a class="public-brand" href="{{ route('public.home') }}"><img src="{{ asset('images/sysforest-logo.jpeg') }}" width="150" height="82" alt="SYSFOREST"><span>Consulta ciudadana<br><strong>Arbolado urbano</strong></span></a>
        <nav aria-label="Navegación pública">
            <a href="{{ route('public.home') }}" @if (request()->routeIs('public.home')) aria-current="page" @endif>Inicio</a>
            <a href="{{ route('public.map') }}" @if (request()->routeIs('public.map')) aria-current="page" @endif>Mapa</a>
            <a href="{{ route('public.trees.index') }}" @if (request()->routeIs('public.trees.*')) aria-current="page" @endif>Inventario</a>
            <a href="{{ route('public.home') }}#acerca">Acerca del SIGAU</a>
            <a class="public-login" href="{{ route('login') }}">Iniciar sesión</a>
        </nav>
    </div></header>
    <main id="public-main" class="public-main">@yield('content')</main>
    <footer class="public-footer"><div><strong>SYSFOREST</strong><span>Información pública para conocer y cuidar el arbolado urbano.</span></div><a href="{{ route('public.home') }}#acerca">Sobre esta consulta</a></footer>
    @stack('scripts')
</body>
</html>
