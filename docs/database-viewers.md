# Visores privados de PostgreSQL y MongoDB

Entrega autorizada el **18/11/2026**: modificar/versionar el repositorio, publicar y actualizar su checkout en el servidor e instalar pgAdmin y Mongo Express como servicios privados persistentes. **Estado al redactar: implementación y comprobaciones locales; instalación productiva todavía pendiente de verificación.** No se declara acceso productivo hasta registrar su cierre.

## Separación entre fuente, aplicación y visores

La fuente canónica sigue siendo `/home/josev/SYSFOREST`. En producción existe un checkout dedicado en `/opt/sysforest/source`; el release de la aplicación continúa en `/opt/sysforest/releases/20261104-initial`, sin `.git`. Para esta entrega se prepara el seguimiento de `codex/organize-backend-modules` en el checkout fuente y una actualización `pull --ff-only` después del push autorizado. No se inicializa Git sobre el release ni se copia el árbol completo del checkout al runtime.

La revisión inicial del 18/11 encontró el checkout limpio, incluidos archivos sin seguimiento, en `main`/`origin/main`, commit `84894106c19ec23d3fb29ff454c50c3505318759`; esa versión es antecesora de la rama canónica. El cambio de rama y pull requieren su resultado propio al cerrar esta entrega. No se encontraron montajes Docker ni referencias directas systemd/cron al checkout en la inspección realizada; eso no acredita la ausencia de automatización externa.

`compose.database-viewers.yml` es un proyecto independiente, `sysforest-database-viewers`, con solo dos servicios. Se conecta a la red externa existente `sysforest-production_backend` para consultar las bases; no crea ni reemplaza la red, las bases o volúmenes de negocio. No llama migraciones, seeders ni comandos Laravel; tampoco reconstruye/reinicia app, queue, scheduler, Nginx o el proxy compartido. La preparación crea o comprueba únicamente las identidades y permisos de consulta de las bases.

## Acceso y alcance de lectura

| Componente | Acceso y alcance |
| --- | --- |
| pgAdmin | Login web propio, separado de la cuenta de la aplicación; servidor PostgreSQL preconfigurado. La cuenta DB `sysforest_viewer` consulta las tablas autorizadas de SysForest. |
| Mongo Express | Autenticación web propia y cuenta DB `sysforest_audit_viewer`. Lee la colección `sysforest_audit.activity_logs` y los metadatos necesarios del visor, sin acceso a colecciones ajenas. |
| Operador | Acceso administrativo privado a datos de las organizaciones presentes en esas bases. No aplica el tenant ni la matriz de roles web de un usuario de SysForest. No compartir estos accesos con usuarios de una institución. |

Las cuentas de base son exclusivas de los visores; no se reutilizan las del runtime, migrador o administrador. PostgreSQL concede SELECT sobre las tablas autorizadas de la aplicación y futuras tablas del migrador, sin privilegios de escritura sobre ellas, creación de esquemas/tablas temporales, avance de secuencias, superusuario, membresías ni bypass de RLS. Una función SECURITY DEFINER accesible que requiera revisión bloquea la preparación. MongoDB impone un rol específico de lectura; las opciones visuales de solo lectura complementan los permisos reales, no los sustituyen.

**Límite PostgreSQL:** `default_transaction_read_only` y los timeouts son valores iniciales de sesión que el usuario puede modificar, no barreras inmutables. Las ACL protegen las tablas de la aplicación, pero no se han revocado globalmente todas las funciones incorporadas ni sus efectos laterales; por ejemplo, puede estar disponible la creación de objetos grandes propios. No se presenta esta cuenta como un aislamiento general de SQL ni como una garantía absoluta de ausencia de escrituras de cualquier clase. Las futuras funciones SECURITY DEFINER accesibles requieren revisión antes de desplegarse.

El login de pgAdmin no es un login Laravel y su contraseña inicial se genera aparte. Las credenciales web y de base permanecen en archivos privados; no se muestran durante `install` ni `status`, ni se versionan. Los servicios no reciben la clave administrativa de las bases. El instalador usa las credenciales administrativas ya configuradas únicamente para preparar las identidades restringidas.

## Instalación en el servidor

Requiere root, Docker/Compose, los siete servicios productivos saludables, la red existente y el checkout de fuente actualizado/revisado. Los puertos **18084 y 18085** deben estar disponibles. La revisión inicial los encontró libres; el instalador vuelve a comprobarlos. El entorno productivo permanece fuera del repositorio.

Desde el checkout fuente aprobado:

```bash
cd /opt/sysforest/source
sudo bin/database-viewers install
sudo bin/database-viewers status
```

Rutas predeterminadas:

- Release: `/opt/sysforest/releases/20261104-initial`.
- Entorno productivo: `/opt/sysforest/private/production.env`.
- Estado privado de los visores: `/opt/sysforest/private/database-viewers`.

`install` admite `--production-dir`, `--env-file` y `--state-dir`; valida las rutas y el contexto en lugar de sustituir instalaciones ajenas. El estado debe estar directamente bajo `/opt/sysforest/private`, fuera del checkout, con propietario root y modo 0700. Los archivos se crean en modo 0600, con el UID requerido cuando el servicio debe leerlos: 5050 para pgAdmin y 1000 para Mongo Express. Se conserva la contraseña PostgreSQL fuera del panel y se entrega un passfile privado al usuario de pgAdmin.

El instalador guarda `settings.json`, secretos, `access.txt` y `installation.json`. El correo inicial de pgAdmin se toma de `SYSFOREST_BASE_ADMIN_EMAIL` del entorno privado; no convierte ese usuario de la aplicación en una sesión web del visor ni reutiliza su contraseña. Las contraseñas son aleatorias y distintas. `access.txt` es privado y no debe copiarse a la documentación, Git, un enlace público ni un log.

## Persistencia y repetición

Los visores permanecen ejecutándose en el servidor; cerrar el túnel solo cierra el acceso desde el equipo del operador. El volumen de pgAdmin conserva su configuración y cuentas. Mongo Express conserva sus secretos web/sesión y vuelve a consultar MongoDB; no crea una segunda copia del historial.

Repetir `install` reutiliza el manifiesto y secretos existentes, comprueba formato/propietarios y vuelve a asegurar permisos de las cuentas gestionadas. No rota contraseñas automáticamente, borra bases ni recrea volúmenes de negocio. Una identidad preexistente ajena al instalador o un estado privado incompatible provoca un error en lugar de una sustitución silenciosa. Puede recrear **solo los contenedores de los dos visores** cuando su configuración o imagen cambia.

Un fallo conserva el estado privado para reintentar. La preparación PostgreSQL es transaccional; MongoDB administra únicamente su rol/usuario y puede repetirse. No se promete una transacción única entre ambas bases o entre bases y Docker. Si falta una parte después de un fallo, corregir la causa y repetir; no eliminar el directorio privado para forzar una instalación nueva.

El passfile de pgAdmin se copia al almacenamiento del usuario en su primera inicialización. Una rotación futura de credenciales requiere coordinar la cuenta de base, el secreto y esa copia; una nueva ejecución no es un procedimiento de rotación. Si se cambia la contraseña del login web dentro de pgAdmin, la «contraseña inicial» conservada en `access.txt` deja de ser la contraseña vigente de esa cuenta.

## Abrir desde el equipo del operador

Las dos interfaces escuchan exclusivamente en loopback del servidor. No se añaden dominios, rutas Caddy ni puertos públicos de bases o paneles. El navegador accede a HTTP local protegido durante el trayecto remoto por SSH.

En Linux, macOS o WSL con el checkout y OpenSSH:

```bash
bin/database-viewers-tunnel usuario@servidor
```

En Windows PowerShell, desde el checkout y con la identidad SSH existente en WSL (Ubuntu-24.04 por defecto):

```powershell
.\bin\database-viewers-connect.ps1 -SshTarget usuario@servidor
```

El helper Linux/WSL permanece en primer plano: mantener su terminal abierta y usar `Ctrl+C` para cerrarlo. El helper PowerShell usa WSL, deja un túnel SSH en segundo plano y abre las dos URLs solo después de conectar; repetirlo reutiliza el socket de control privado y no añade tareas al inicio. Admite `-Distribution` para otra distribución de WSL. Para cerrar ese túnel persistente desde PowerShell:

```powershell
wsl -d Ubuntu-24.04 -- ssh -S '~/.ssh/sysforest-database-viewers-%C.sock' -O exit usuario@servidor
```

El túnel enlaza solo `127.0.0.1` en ambos extremos. Las claves SSH se gestionan mediante la configuración existente del operador; no incluir contraseñas, tokens ni claves privadas en argumentos. No usar reenvío de agente. Si el puerto local ya está ocupado, el helper falla sin sustituir el servicio que lo utiliza.

- pgAdmin: `http://127.0.0.1:18084`.
- Mongo Express: `http://127.0.0.1:18085`.

Para consultar los accesos desde una terminal privada en el servidor, un operador root puede ejecutar `sudo /opt/sysforest/source/bin/database-viewers credentials`. Este comando **muestra credenciales** de forma explícita; no ejecutarlo en sesiones grabadas, informes de automatización o capturas compartidas. `status` informa salud y URLs sin contraseñas.

## Versiones y actualizaciones

pgAdmin se fija en `dpage/pgadmin4:9.19` con digest `sha256:3591b3d02910dcd817dec6fcaa681c278ba6966945283dd0ea0042731c330c83`. Node usa `22.23.5-bookworm-slim` con digest `sha256:e9f565d32859f0cbfb52125eafe8be39390f24dac302dc3cdf566e8ba3ab21ee`. El archivo fuente, Yarn y lock de Mongo Express también se comprueban por huella en su Dockerfile. No se usa actualización automática ni tags flotantes como `latest`. Cambiar un pin requiere revisar la nueva versión, dependencias, permisos efectivos, compatibilidad de secretos/volumen y acceso por túnel antes de publicar el cambio.

Mongo Express se construye desde el snapshot oficial **`6b0fc653660f9862a23f4b111eaa4daa0a3a6e2a`**, cuyo paquete indica **1.1.0-rc-4**, sobre **Node 22.23.5**. No se presenta como una release estable de Mongo Express. El snapshot evita reutilizar la versión estable antigua con su árbol de dependencias pendiente; se conserva el origen y pin para revisar una futura actualización estable. La auditoría del árbol seleccionado reportó cero vulnerabilidades altas/críticas y un aviso moderado de deprecación de `csurf`; es una observación de esta entrega, no una garantía permanente.

Antes de una actualización manual, conservar el estado privado y el volumen de pgAdmin, revisar el diff y confirmar el commit aprobado. Actualizar la rama de fuente por fast-forward, volver a ejecutar `install` y comprobar los dos visores, sus permisos y que los servicios de negocio no cambiaron. No usar `down -v`, `git clean` sobre datos, borrado de secretos ni copia indiscriminada del checkout al release como mecanismo de actualización.

## Verificaciones y límites actuales

Revisión independiente de infraestructura por SSH, solo lectura: release sin Git, checkout fuente limpio, puertos 18084/18085 libres, red backend existente y siete servicios saludables. En esa observación había aproximadamente 13,4 GiB de RAM disponible y 166,5 GiB de disco libre; son valores puntuales, no una reserva de capacidad. Los proyectos compartidos y el proxy se mantienen independientes. Evidencia externa al repo: `.private-admin-tools-work/review/`.

Las observaciones manuales aisladas de preparación de cuentas aprobaron **20/20** en `.database-viewers-work/readonly-manual-observations.json`: creación/repetición, lectura, rechazo de escritura/creación/secuencias/escalamiento, preservación de RLS, futuras tablas del migrador y bloqueo de SECURITY DEFINER accesible; en Mongo, lectura/metadatos, cinco denegaciones con código 13 y conservación de los documentos ficticios. No hubo nuevas suites permanentes ni uso de datos productivos para esos casos.

La revisión independiente del instalador confirmó resueltos tres desajustes: conexión explícita al ejecutar `mongosh --nodb`, correspondencia entre variables administrativas MongoDB y nombre uniforme `SYSFOREST_VIEWERS_SECRETS_DIR`. El instalador comprueba que los siete secretos del Compose resuelven al estado seleccionado y que usa la red externa exacta. No se encontraron otros bloqueos en la lectura final del instalador; la comprobación integrada sigue siendo necesaria. **Pendiente al redactar:** validación integrada de Compose/paneles, push/pull final, instalación productiva, autenticación de las interfaces, reejecución persistente y comprobación posterior de salud/integridad. Las comprobaciones de cuentas aisladas no acreditan por sí solas la instalación ni la interfaz final. El cierre se añadirá a esta guía con los resultados observados, sin afirmar cambios aún no ejecutados.
