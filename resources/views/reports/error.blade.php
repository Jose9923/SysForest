<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reporte no disponible · SYSFOREST</title>
    <style>body{font-family:system-ui,sans-serif;background:#f4f7f1;color:#234332;margin:0;padding:24px}main{max-width:660px;margin:12vh auto;background:white;padding:32px;border-radius:16px}h1{font-size:1.6rem}li{margin:12px 0}a{color:#246447;font-weight:600}nav{display:flex;gap:24px;flex-wrap:wrap;margin-top:24px}</style>
</head>
<body><main>
    <p>SYSFOREST · Reportes</p><h1>No se pudo completar la consulta</h1><p>{{ $message }}</p>
    @if (count($validationErrors))
        <ul>
            @foreach ($validationErrors as $messages)
                @foreach ($messages as $error)
                    <li>{{ $error }}</li>
                @endforeach
            @endforeach
        </ul>
    @endif
    <nav><a href="{{ route('reports.index') }}">Volver a reportes</a><a href="{{ route('login') }}">Iniciar sesión</a></nav>
</main></body>
</html>
