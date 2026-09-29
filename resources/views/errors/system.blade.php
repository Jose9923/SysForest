<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Consulta no disponible · SYSFOREST</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f7f3; color: #173342; font: 16px/1.6 system-ui, sans-serif; }
        main { width: min(100% - 32px, 640px); margin: 10vh auto; padding: clamp(24px, 5vw, 48px); background: white; border: 1px solid #dbe7df; border-radius: 16px; }
        .brand { color: #216b47; font-weight: 700; letter-spacing: .08em; }
        h1 { font-size: clamp(1.6rem, 5vw, 2rem); line-height: 1.2; }
        a { color: #216b47; text-underline-offset: 4px; }
        a:focus-visible { outline: 3px solid #80bc96; outline-offset: 5px; }
        nav { display: flex; flex-wrap: wrap; gap: 16px 24px; margin-top: 24px; }
    </style>
</head>
<body>
    <main>
        <p class="brand">SYSFOREST</p>
        <h1>{{ $status === 409 ? 'El registro cambió' : 'Consulta no disponible' }}</h1>
        <p>{{ $message }}</p>
        <nav aria-label="Continuar en SYSFOREST">
            @if ($backUrl ?? null)<a href="{{ $backUrl }}">Volver a inspecciones</a>@endif
            <a href="/portal">Ir al portal público</a>
            <a href="/login">Iniciar sesión</a>
        </nav>
    </main>
</body>
</html>
