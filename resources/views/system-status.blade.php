<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>SYSFOREST · Infraestructura local</title>
    <style>
        :root { color-scheme: light; --ink: #173342; --muted: #5d6f68; --green: #216b47; --line: #dbe7df; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f7f3; color: var(--ink); font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; }
        header { border-bottom: 1px solid var(--line); background: #fff; }
        .header-inner, main, footer { width: min(100% - 40px, 1000px); margin-inline: auto; }
        .header-inner { min-height: 88px; display: flex; align-items: center; justify-content: space-between; gap: 20px; }
        .brand { display: flex; align-items: center; gap: 12px; }
        .brand img { width: 156px; height: 85px; object-fit: contain; }
        .environment { padding: 5px 12px; border: 1px solid var(--line); border-radius: 100px; font-size: .8rem; color: var(--green); white-space: nowrap; }
        main { padding-block: 58px 32px; }
        .eyebrow { margin: 0 0 12px; font-size: .78rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--green); }
        h1 { margin: 0; max-width: 780px; font-size: clamp(2rem, 5vw, 3rem); line-height: 1.16; letter-spacing: -.035em; font-weight: 650; }
        .intro { max-width: 700px; margin: 20px 0 32px; color: var(--muted); font-size: 1.05rem; }
        .panel { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 26px; margin-bottom: 20px; }
        .panel-title { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
        h2 { font-size: 1.1rem; margin: 0; }
        .badge { font-size: .8rem; font-weight: 600; color: #216b47; background: #e9f5ed; border-radius: 100px; padding: 4px 12px; }
        .badge.error { color: #93402c; background: #fff0e9; }
        .checks { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
        .check { border-top: 2px solid var(--line); padding-top: 15px; }
        .check strong { display: block; font-weight: 600; }
        .check span { font-size: .9rem; color: var(--muted); }
        .check .available { color: var(--green); }
        .check .unavailable { color: #93402c; }
        dl { margin: 0; }
        .probe { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px 24px; padding: 14px 0; border-top: 1px solid var(--line); }
        dt { font-weight: 500; }
        dd { margin: 0; color: var(--muted); font-size: .9rem; overflow-wrap: anywhere; }
        .note { font-size: .86rem; color: var(--muted); margin: 16px 0 0; }
        .links { display: flex; flex-wrap: wrap; gap: 20px; margin-top: 24px; }
        a { color: var(--green); text-underline-offset: 4px; }
        a:focus-visible { outline: 3px solid #80bc96; outline-offset: 5px; border-radius: 2px; }
        footer { padding-block: 0 30px; color: var(--muted); font-size: .8rem; }
        @media (max-width: 600px) { main { padding-top: 34px; } .checks { grid-template-columns: 1fr; } .panel { padding: 20px; } .environment { font-size: .7rem; } }
    </style>
</head>
<body>
    <header>
        <div class="header-inner">
            <div class="brand"><img src="{{ asset('images/sysforest-logo.jpeg') }}" alt="SYSFOREST" width="156" height="85"></div>
            <span class="environment">Entorno local</span>
        </div>
    </header>
    <main>
        <p class="eyebrow">Fase 2 · Infraestructura</p>
        <h1>La base de SYSFOREST.</h1>
        <p class="intro">Este entorno permite verificar la conexión de la aplicación, la base de datos espacial y los procesos en segundo plano. Los módulos de inventario forestal se desarrollarán en las siguientes fases.</p>

        <section class="panel" aria-labelledby="connections-heading">
            <div class="panel-title">
                <h2 id="connections-heading">Conexiones de la aplicación</h2>
                <span class="badge {{ $status->isReady() ? '' : 'error' }}">{{ $status->isReady() ? 'Disponibles' : 'Requieren revisión' }}</span>
            </div>
            <div class="checks">
                @foreach (['database' => 'PostgreSQL', 'postgis' => 'PostGIS', 'redis' => 'Redis'] as $key => $label)
                    <div class="check">
                        <strong>{{ $label }}</strong>
                        <span class="{{ $status->checks[$key] ? 'available' : 'unavailable' }}">{{ $status->checks[$key] ? 'Conexión verificada' : 'No disponible' }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="panel" aria-labelledby="processes-heading">
            <div class="panel-title"><h2 id="processes-heading">Evidencia de ejecución</h2></div>
            <dl>
                <div class="probe"><dt>Última prueba procesada en cola</dt><dd>{{ $status->queueLastProcessedAt ?? 'Sin ejecución registrada' }}</dd></div>
                <div class="probe"><dt>Última ejecución del programador</dt><dd>{{ $status->schedulerLastRunAt ?? 'Sin ejecución registrada' }}</dd></div>
            </dl>
            <p class="note">Las fechas se muestran en UTC y conservan la última ejecución durante siete días. Una ejecución anterior no confirma que el proceso continúe activo. La información se consulta al cargar esta página.</p>
        </section>

        <nav class="links" aria-label="Comprobaciones de infraestructura">
            <a href="{{ route('phase3.access') }}">Gestión institucional · Fase 3</a>
            <a href="{{ route('system.readiness') }}">Consultar disponibilidad</a>
            <a href="{{ url('/up') }}">Comprobar respuesta de Laravel</a>
        </nav>
    </main>
    <footer>SYSFOREST · Infraestructura de desarrollo local</footer>
</body>
</html>
