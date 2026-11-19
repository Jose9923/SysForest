# Visores privados de PostgreSQL y MongoDB

Por solicitud explícita del **18/11/2026**, phpPgAdmin sustituye a pgAdmin; Mongo Express conserva su configuración. Por autorización posterior del mismo día se incorpora una cuenta separada de creación y edición de registros. Esta guía describe el instalador actualizado. El cierre del cambio y sus verificaciones se registran abajo, separados de la instalación inicial de pgAdmin.

## Fuente y alcance

La fuente canónica es `/home/josev/SYSFOREST`. En producción se actualiza por fast-forward `/opt/sysforest/source`, rama `codex/organize-backend-modules`. La aplicación continúa en `/opt/sysforest/releases/20261104-initial`, sin Git; no copiar todo el checkout al release.

`compose.database-viewers.yml` define el proyecto `sysforest-database-viewers` con dos servicios: `phppgadmin` y `mongo-express`, conectados a la red externa `sysforest-production_backend`. No crea bases, redes ni volúmenes de negocio, no ejecuta comandos Laravel ni reinicia app, queue, scheduler, nginx, PostgreSQL, MongoDB, Redis o el proxy compartido.

## Acceso

| Visor | Dirección por túnel | Cuenta |
| --- | --- | --- |
| phpPgAdmin · edición | http://127.0.0.1:18084 | Login PostgreSQL nativo `sysforest_editor`, base `sysforest`; crear y actualizar registros. |
| phpPgAdmin · consulta | http://127.0.0.1:18084 | `sysforest_viewer`, con sus permisos anteriores de lectura. |
| Mongo Express | http://127.0.0.1:18085 | Login web `sysforest`; cuenta DB `sysforest_audit_viewer`, limitada a la auditoría autorizada. |

phpPgAdmin no utiliza el correo y la contraseña web de pgAdmin. El selector de login ofrece `sysforest_editor` por defecto y `sysforest_viewer` para consulta. Cada una tiene una contraseña independiente disponible en el archivo privado del servidor; la de consulta se conserva y la de edición se genera una sola vez. Después de activar la edición, cerrar la sesión anterior o recargar e ingresar como `sysforest_editor`: una sesión de `sysforest_viewer` sigue siendo de lectura. El panel no recibe secretos montados ni autologin. Las sesiones son temporales en `/tmp`, por lo que reiniciar el visor exige iniciar sesión nuevamente. Mongo Express conserva sus credenciales actuales.

Desde una terminal privada del servidor:

```bash
sudo /opt/sysforest/source/bin/database-viewers credentials
```

Este comando muestra contraseñas: no compartir su salida ni ejecutarlo en informes automatizados. `status` e `install` no las imprimen. Estos accesos son administrativos y abarcan las instituciones presentes; no aplican el tenant ni los roles Laravel de una cuenta de SysForest.

Los paneles solo escuchan en loopback. Abrir el túnel desde Linux/WSL con el checkout:

```bash
bin/database-viewers-tunnel usuario@servidor
```

O desde Windows PowerShell:

```powershell
.\bin\database-viewers-connect.ps1 -SshTarget usuario@servidor
```

El helper Bash permanece en primer plano; `Ctrl+C` cierra el túnel. El helper Windows usa la identidad SSH existente en WSL, abre las direcciones tras conectar y reutiliza un socket privado. No configura tareas de inicio. Para cerrar ese túnel:

```powershell
wsl -d Ubuntu-24.04 -- ssh -S '~/.ssh/sysforest-database-viewers-%C.sock' -O exit usuario@servidor
```

Cerrar el túnel no detiene los visores del servidor. No publicar estos puertos, añadir rutas públicas ni habilitar reenvío de agente.

## Instalar o actualizar

Requiere root, Docker/Compose, la red existente y los siete servicios de producción saludables. Desde el checkout revisado:

```bash
cd /opt/sysforest/source
sudo bin/database-viewers install
sudo bin/database-viewers status
```

Rutas predeterminadas: release `/opt/sysforest/releases/20261104-initial`, entorno `/opt/sysforest/private/production.env`, estado `/opt/sysforest/private/database-viewers`. Se pueden indicar `--production-dir`, `--env-file`, `--state-dir`; el instalador valida el contexto y rechaza colisiones ajenas. El estado debe estar directamente bajo `/opt/sysforest/private`, root:0700, y sus archivos en 0600. Mongo Express lee sus secretos como UID 1000; las contraseñas PostgreSQL quedan root:0600.

En una instalación nueva se preparan las cuentas de consulta y la cuenta limitada de edición. En una instalación V1/V2 existente se conservan las cuentas/secretos anteriores y se prepara únicamente `sysforest_editor`. Tras completar V3, una repetición comprueba las credenciales/permisos sin volver a preparar las cuentas ni cambiar contraseñas. El candidato phpPgAdmin se construye y comprueba sin publicar puertos: HTTP y conexión a PostgreSQL con ambas cuentas autorizadas. Solo después se sustituye el panel en el mismo puerto 18084; para V1 se detiene el pgAdmin anterior y para V2 se actualiza únicamente phpPgAdmin. Mongo Express existente debe coincidir con su imagen/configuración y estar saludable; si difiere se aborta en lugar de reemplazarlo.

La identidad Docker se valida con el descriptor completo del manifiesto de plataforma (`ImageManifestDescriptor`): digest, tamaño, tipo y plataforma deben coincidir, junto con la configuración de Compose. Un índice multi-plataforma no se acepta como ese manifiesto. En motores sin descriptor en ambos lados se exige igualdad de Image; un descriptor ausente solo en un lado o distinto provoca rechazo. Esta distinción corrige la comparación entre el Image de un contenedor y el índice de la etiqueta sin recrear Mongo Express ni omitir la comprobación de configuración.

La actualización a V3 añade `postgres_editor_password` (root:0600), conserva los ocho archivos privados previos y guarda `access.before-editor.txt`, `settings.before-editor.json` e `installation.before-editor.json`. Publica los dos accesos en `access.txt` solo tras comprobar el panel. Si falla la activación sobre un phpPgAdmin previo, intenta recuperar su imagen mediante un override temporal exclusivo de ese servicio; no revierte el rol/secreto ya preparados ni toca Mongo o la aplicación. Corregir la causa y repetir conserva ese estado.

La actualización V1→V2 conserva los ocho archivos privados anteriores (incluidos clave/passfile de pgAdmin) y guarda `access.before-phppgadmin.txt`, `settings.before-phppgadmin.json` e `installation.before-phppgadmin.json`. Conserva el contenedor `sysforest-database-viewers-pgadmin-1` **detenido**, su imagen y el volumen `sysforest-database-viewers_pgadmin_data`. No usar `--remove-orphans`, `down -v`, borrado de secretos ni limpieza global de Docker.

Una repetición comprueba configuración, salud, secretos, entorno y las identidades de los siete servicios. No rota contraseñas ni reinicia Mongo Express. El instalador conserva estado tras un fallo para corregir la causa y reintentar; no garantiza una transacción global entre archivos, cuentas y Docker. Un fallo durante la activación recupera el pgAdmin anterior si estaba activo; un fallo posterior al activar requiere consultar `status` y revisar el estado antes de repetir. No borrar el directorio privado para forzar una instalación nueva.

## Recuperar el visor anterior

El contenedor/volumen anterior y las preimágenes se conservan para una recuperación manual revisada. Con `phppgadmin` detenido, el puerto 18084 libre y el ID de pgAdmin comparado contra `settings.json.legacy_pgadmin`, se puede iniciar ese mismo contenedor con `docker start`. Usar el acceso previo guardado en `access.before-phppgadmin.txt`. No restaurar ni migrar la base para volver de visor. Revisar el manifiesto antes de volver a ejecutar el instalador; no mezclar instrucciones de login de ambos paneles.

## Permisos y límites

`sysforest_viewer` conserva SELECT sobre las tablas autorizadas y futuras tablas del migrador, sin INSERT/UPDATE/DELETE/TRUNCATE ni avance de secuencias. `sysforest_editor` añade INSERT/UPDATE en relaciones de `public` propiedad del migrador y USAGE/SELECT en sus secuencias, con defaults equivalentes para objetos futuros; permite `nextval` para los IDs automáticos, no `setval` ni reiniciar secuencias. No concede DELETE/TRUNCATE, crear/alterar estructuras, delegar permisos, superusuario, propiedad, membresías o bypass RLS. Las restricciones y triggers siguen activos y pueden rechazar cambios de identidades o historiales protegidos. Mongo conserva su rol de consulta. Ningún visor recibe la cuenta administrativa de la base.

El modo de transacción predeterminado es `off` para `default_transaction_read_only` de editor y `on` para viewer; el filesystem Docker sigue siendo de lectura y no se cambia por este permiso SQL. SQL directo omite los flujos de Laravel: no calcula hashes de contraseñas, crea membresías, asigna roles, ejecuta validaciones de negocio ni genera su auditoría de servicios. `users` ya tiene ID BIGINT por secuencia y no requiere `external_id`; las 55 entidades convertidas generan su UUID externo al omitir ese campo. El alta en `users` por sí sola no habilita el acceso institucional en la aplicación.

`default_transaction_read_only` y los timeouts son valores iniciales modificables por la sesión. Las ACL de consulta restringen las escrituras y las de edición permiten el DML descrito, pero no se han revocado globalmente todas las funciones incorporadas ni efectos como crear objetos grandes propios. Esta cuenta no es un aislamiento absoluto de SQL. Revisar futuras funciones SECURITY DEFINER accesibles.

phpPgAdmin conserva la consola SQL y las exportaciones sujetas a esas ACL. La exportación de datos de una tabla utiliza los formatos nativos CSV y SQL. Se deja `pg_dump_path` vacío: el respaldo administrativo con `pg_dump` necesita un alcance distinto del perfil de consulta, y este panel mantiene desactivado ese procedimiento para ambas cuentas. Ese rechazo se comprobó en la base desechable; no se amplían ACL. El visor no ofrece respaldos completos y no sustituye el procedimiento administrativo de copia de seguridad. La configuración restringe servidor/base y las dos cuentas admitidas; los parches pequeños incorporan token CSRF para POST, validación de origen/destino, rotación de sesión al login, escape de un nombre de campo y validación del índice del historial. No constituyen una auditoría integral del upstream. El acceso continúa siendo exclusivamente privado por SSH.

## Versiones fijadas

phpPgAdmin se construye desde el [snapshot ReimuHakurei f35f659463aed028f763343cb7cf3d44a36c121f](https://github.com/ReimuHakurei/phpPgAdmin/commit/f35f659463aed028f763343cb7cf3d44a36c121f), posterior al soporte de columnas IDENTITY; no se presenta como una release estable. Archivo fuente SHA256 `ece40f966e901228a1bc70b8c92b384a360db355c2a46d1e2f420ff112bd19c6`. PHP Apache 8.3.36 Bookworm se fija por digest en `Dockerfile.phppgadmin`; no se incluye el cliente pg_dump. UID 1000, filesystem de lectura, tmpfs de 64 MiB, sin capacidades Linux ni privilegios nuevos. La salud es `/health.php`.

Mongo Express conserva el snapshot oficial `6b0fc653660f9862a23f4b111eaa4daa0a3a6e2a` (paquete 1.1.0-rc-4), Node 22.23.5 y sus huellas en `Dockerfile.mongo-express`. Tampoco se presenta como release estable. No hay actualizaciones automáticas. Revisar los cambios de fuente, dependencias y permisos y comprobar login, BIGINT/IDENTITY, navegación y exportación antes de actualizar pins.

## Habilitación de creación y edición · 18/11/2026

Autorizada explícitamente después del error de INSERT en una sesión de consulta. Implementación V3 preparada, con `sysforest_editor` separado y tag de panel `sysforest-phppgadmin:f35f659-php83-v2`. Activación y cierre de pruebas pendientes de registrar aquí. No crear ni modificar a Julieth o datos forestales como verificación productiva; las escrituras de prueba se limitan a una base desechable.

## Verificación del cambio a phpPgAdmin

**Cambio completado el 18/11/2026 a las 19:11, hora de Bogotá.** El commit operativo `380a0b1364247f01ac91bfefe523adaca213c365` quedó publicado y actualizado en `/opt/sysforest/source`, limpio en `codex/organize-backend-modules`. Imagen productiva phpPgAdmin: `sha256:90c608da19523dad6f0f0cb06ca84cf2c8e215ad9df1868373709f96a21861c6`, etiqueta `sysforest-phppgadmin:f35f659-php83-v1`. Mongo Express conservó su contenedor, imagen, configuración y fecha de inicio.

| Comprobación | Resultado observado |
| --- | --- |
| QA aislada | **21/21** aprobadas con PostgreSQL 17, datos ficticios, BIGINT exacto mayor que 2⁵³, metadatos IDENTITY, login, consulta, ordenamiento, CSV y SQL nativo de cuatro filas, rechazos por ACL/CSRF/origen y logout. Sin conexión a producción ni ampliación de ACL. |
| HTTP productivo | **48/48** aprobadas: 16 comprobaciones para cada una de las tablas trees, inventories y evidences. Login, BIGINT/IDENTITY, consulta, ordenamiento, CSV/SQL nativo completos con 2, 1 y 7 filas, protección CSRF/origen y logout. Sesiones propias cerradas; sin escrituras de negocio, secretos impresos ni exportaciones crudas persistidas. |
| Datos y permisos | Se conservaron los conteos observados: dos árboles, un inventario, dos inspecciones, siete evidencias y dos asociaciones inventario/árbol, sin referencias huérfanas. La cuenta mantuvo SELECT y ningún permiso de escritura sobre tablas. Estas lecturas no equivalen a una verificación integral de todos los datos. |
| Activación y conservación | Ambos visores saludables; entorno productivo y hashes de los **ocho archivos privados** idénticos. Tres preimágenes conservadas. pgAdmin anterior detenido y retenido con su imagen y volumen. Sin migraciones, seeders ni cambios de ACL. |
| Repetición | `install` repetido sin cambiar contenedor, imagen ni fecha de inicio de phpPgAdmin, ni access/settings. Mongo Express y los siete servicios de negocio permanecieron iguales a la línea base del instalador. |
| Acceso desde Windows | Túnel privado abierto, phpPgAdmin HTTP 200 con su contenido reconocido y Mongo Express 401 sin autenticación, según lo esperado. No se hizo una revisión interactiva en navegador. |

Los siete servicios de negocio conservaron sus IDs, imágenes y configuración durante la sustitución y la repetición, sin reinicios en ese intervalo. La comparación con el preflight anterior muestra un cambio de StartedAt únicamente en **queue**, a las 23:41 UTC (18:41 en Bogotá), antes de crear phpPgAdmin: su intervalo de reinicio coincide con el límite configurado de `--max-time=3600`. Los otros seis mantuvieron su fecha de inicio. `queue_observation` y la línea base del instalador acreditan esta distinción; no se afirma que los siete conservaran StartedAt durante toda la sesión de trabajo.

La revisión de identidad de imágenes aprobó diez observaciones con los descriptores productivos capturados: acepta el mismo manifiesto de plataforma aunque difiera Image, y rechaza cambios de configuración, digest, arquitectura, tamaño, índices o descriptores incompletos. La corrección no reconstruyó ni recreó Mongo Express.

La exportación SQL aprobada contiene INSERT de datos, sin estructura, estado de secuencias ni respaldo completo de la base. La evidencia funcional es HTTP, no una aceptación visual interactiva. No se añadieron suites permanentes. Tras cerrar la QA se retiraron exclusivamente sus dos contenedores, red interna y dos archivos secretos temporales; se conservaron las imágenes y no se tocaron volúmenes persistentes ni bases canónicas/productivas.

Evidencias externas al repositorio, bajo `.phppgadmin-work/`: `qa-functional-review.json`, `production-verification-first.json`, `production-verification-repeat.json`, `production-http-verification.json`, `repeat-idempotence.json`, `windows-access-verification.json`, `platform-manifest-identity-review.json` y `qa-cleanup.json`. Los informes conservan resultados y huellas sin publicar credenciales ni filas de negocio.

## Evidencia histórica de la instalación inicial de pgAdmin

Revisión independiente de infraestructura por SSH, solo lectura: release sin Git, checkout fuente limpio, puertos 18084/18085 libres, red backend existente y siete servicios saludables. En esa observación había aproximadamente 13,4 GiB de RAM disponible y 166,5 GiB de disco libre; son valores puntuales, no una reserva de capacidad. Los proyectos compartidos y el proxy se mantienen independientes. Evidencia externa al repo: `.private-admin-tools-work/review/`.

Las observaciones manuales aisladas de preparación de cuentas aprobaron **20/20** en `.database-viewers-work/readonly-manual-observations.json`: creación/repetición, lectura, rechazo de escritura/creación/secuencias/escalamiento, preservación de RLS, futuras tablas del migrador y bloqueo de SECURITY DEFINER accesible; en Mongo, lectura/metadatos, cinco denegaciones con código 13 y conservación de los documentos ficticios. No hubo nuevas suites permanentes ni uso de datos productivos para esos casos.

La revisión independiente del instalador confirmó resueltos tres desajustes: conexión explícita al ejecutar `mongosh --nodb`, correspondencia entre variables administrativas MongoDB y nombre uniforme `SYSFOREST_VIEWERS_SECRETS_DIR`. El instalador comprueba que los siete secretos del Compose resuelven al estado seleccionado y que usa la red externa exacta. La integración local de paneles comprobó login, registro PostgreSQL importado, passfile, persistencia y rechazos de autenticación; su evidencia es `.database-viewers-work/local-http.json`. El cierre productivo siguiente es independiente de las 20 observaciones de cuentas aisladas. No se añadieron ni ejecutaron suites permanentes.

## Cierre histórico de pgAdmin · 18/11/2026

Código operativo `74af8aea20f98109e431aee3afbef0d2715e365e` publicado y actualizado en `/opt/sysforest/source`, con tracking de `codex/organize-backend-modules`. La aplicación continúa usando `/opt/sysforest/releases/20261104-initial`. El respaldo previo está en `/opt/sysforest/backups/database-viewers-20261118t182429z/backup-20261118T182431Z-CroPle`; conservarlo junto con el estado privado y el volumen de pgAdmin. No se afirma un ensayo de restauración de ese respaldo.

La primera verificación HTTP y la repetida quedaron aprobadas. Entre ambas se volvió a ejecutar `install` con salida 0; los hashes de **ocho archivos privados de credenciales/conexión** fueron idénticos. La recuperación de pgAdmin añadió solo la conexión gestionada ausente tras la inicialización parcial, con guarda de conflicto, sin reemplazo ni borrado de volumen.

| Comprobación productiva | Resultado observado |
| --- | --- |
| pgAdmin | Login 200, exactamente un servidor gestionado coincidente, conexión por passfile sin enviar contraseña explícita, árbol con la base sysforest, desconexión y logout 200. |
| Mongo Express | 401 sin autenticación, 200 autenticado, colección de auditoría visible, readOnly activo y sin controles de escritura. |
| Salud | Los dos paneles saludables; health HTTP 200. Desde Windows, el túnel entregó pgAdmin 200 y Mongo 401 sin credenciales, según lo esperado. |
| PostgreSQL | Lectura de metadatos correcta: 75 tablas y 1 esquema visibles para el visor. No hubo intentos de escritura de negocio en estas comprobaciones. |
| MongoDB | Lectura correcta: 17.016 documentos en la primera consulta y 17.017 en la segunda. La actividad concurrente puede aumentar la auditoría; estas cifras no prueban integridad ni una instantánea global. |
| Conservación | Los siete servicios de SysForest mantuvieron sus IDs, imágenes y fechas de inicio; la huella de production.env permaneció idéntica. No hubo migraciones, seeders ni reinicios de esos servicios. |

Evidencias externas al repositorio: `.database-viewers-work/production-http-first.json`, `production-http-repeat.json` y `production-deployment-result.json`. El helper Windows entregado conserva la identidad SSH existente y no contiene credenciales. Los paneles siguen siendo privados y los permisos PostgreSQL mantienen el límite de funciones incorporadas descrito arriba; los resultados HTTP no convierten esa cuenta en un aislamiento absoluto de SQL.
