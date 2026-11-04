# Despliegue de producción · 4 de noviembre de 2026

SYSFOREST quedó publicado en [https://sysforest.varcor.tech](https://sysforest.varcor.tech), con traslado autorizado de los usuarios, inventarios y archivos del entorno anterior. Este informe registra las comprobaciones realizadas durante el despliegue; las evidencias históricas de las fases locales no sustituyen estas verificaciones.

## Plataforma y publicación

| Componente | Estado confirmado |
| --- | --- |
| Proveedor y servidor | VPS Hostinger, Ubuntu 26.04.1, arquitectura x86_64, 4 vCPU, 16 GB de RAM y 200 GB de disco contratados. |
| DNS | `sysforest.varcor.tech` resuelve a `179.66.133.169`. |
| HTTPS | Conexión TLS 1.3 verificada; certificado Let's Encrypt válido hasta el 2 de febrero de 2027 al momento de la revisión. |
| Versión desplegada | `/opt/sysforest/releases/20261104-initial`. |
| Configuración privada | `/opt/sysforest/private/production.env`, permisos `0600`; no se incorpora al repositorio ni a la imagen. |
| Aplicación | `compose.production.yml`: PostgreSQL/PostGIS, MongoDB, Redis, PHP-FPM, queue, scheduler y Nginx saludables. |
| Acceso interno | Nginx publica exclusivamente `127.0.0.1:18080`; las bases, Redis y PHP-FPM no tienen puertos públicos. |
| Proxy | Caddy en red del host, con almacenamiento persistente de certificados; reenvía al Nginx privado y conserva la IP de origen verificada. |
| Firewall | UFW permite TCP 22, 80 y 443, además de UDP 443. |
| Arranque del host | Servicio Docker habilitado para iniciar con el servidor. |

El Compose local continúa siendo independiente. La imagen productiva usa el target `production`, sin dependencias de desarrollo, sin Mailpit ni migraciones o seeders automáticos. Los detalles del procedimiento están en [despliegue de producción](../docker/production/README.md).

MongoDB utiliza `mongo:8.0.34-noble` con `GLIBC_TUNABLES=glibc.pthread.rseq=1`. El ajuste evita la incompatibilidad de TCMalloc y la detección incorrecta de la numeración del kernel Ubuntu observadas al arrancar la versión anterior. Su carácter temporal, posible coste de rendimiento y condición para retirarlo se documentan en [compatibilidad de MongoDB](../docker/production/README.md#compatibilidad-de-mongodb-con-ubuntu-2604). El arranque y la consulta autenticada quedaron operativos después del ajuste.

## Traslado de datos y acceso

La restauración PostgreSQL incluyó 71 tablas y la comparación con el origen confirmó los conteos revisados: 2.855 árboles, 2.855 visitas y cinco usuarios anteriores. Se trasladaron 11 archivos de `storage/app`; sus hashes coincidieron con los del origen. Se conservaron las relaciones, historiales y archivos privados, sin convertir la puesta en producción en una siembra nueva del piloto.

MongoDB recibió 16.802 documentos de auditoría del respaldo. Posteriormente se reprodujo la auditoría canónica desde PostgreSQL, verificando las huellas de los documentos y los eventos nuevos producidos durante la preparación. La comprobación final confirmó 16.835 eventos en PostgreSQL y 16.835 documentos en MongoDB, con cero entregas pendientes y auditoría disponible. La cantidad de eventos puede crecer con el uso y no debe tratarse como un total permanente.

Se creó la cuenta administrativa de producción **Varcor**, asociada a `varcor.soluciones@gmail.com`. Las contraseñas de desarrollo de las cinco cuentas trasladadas se retiraron y se revocaron nueve sesiones anteriores. La contraseña temporal de bootstrap se retiró del servidor después de las verificaciones; el acceso administrativo definitivo se conserva por el canal privado de entrega. Este informe no contiene contraseñas, tokens ni enlaces de recuperación. La preparación administrativa se describe en [acceso productivo](production-access.md).

Se conservó `APP_KEY` para mantener la capacidad de recuperar contenido cifrado que depende de ella. El secreto JWT y las credenciales técnicas de las bases se prepararon para producción. Los secretos se conservan por canales privados; no se publican junto con el código ni con este documento. No se restauraron las sesiones ni las colas Redis locales.

## Verificación HTTP y autenticación

Las siguientes comprobaciones se realizaron sobre la instalación desplegada. Las respuestas satisfactorias acreditan el recorrido revisado, no una prueba de carga ni una revisión exhaustiva de todos los formularios.

| Recorrido | Resultado |
| --- | --- |
| Acceso público a `/login` y disponibilidad `/health/ready` | HTTP 200 a través de la publicación HTTPS. |
| Sesión web: perfil, organizaciones, usuarios, mapa, analítica y auditoría | HTTP 200 con la cuenta autorizada. |
| API móvil: descubrimiento institucional | HTTP 200 y dos instituciones autorizadas para la cuenta revisada. |
| API móvil: login, identidad, inventarios, catálogos y lectura de árboles | HTTP 200 con el JWT y ámbito institucional correspondientes. |
| API móvil: cierre de sesión | Recorrido satisfactorio. |
| Consulta privada sin JWT | HTTP 401. |
| Acceso con la antigua contraseña de desarrollo | HTTP 401. |

La IP de origen observada por Nginx se comprobó detrás de Caddy. Los datos de negocio continúan sujetos a autenticación, permisos y separación institucional; la publicación del servidor no convierte los inventarios ni las fotografías privadas en datos públicos.

No se recrearon suites permanentes de pruebas. No se declara aquí un ensayo completo de carga, recuperación ante desastre, sincronización concurrente o edición forestal desde dispositivos físicos.

## Aplicación móvil

Se compiló el APK de revisión **0.2.1+3**, variante **debug**, con la URL productiva HTTPS integrada en su configuración. El acceso solicita correo y contraseña y, cuando corresponde, selección de la institución por nombre. La persona no necesita escribir el dominio ni un código institucional.

Los endpoints productivos fueron verificados mediante HTTP; la instalación y el recorrido de esta compilación en un teléfono físico permanecen pendientes. Este APK no acredita una publicación en Google Play o App Store ni reemplaza la preparación de una distribución firmada para usuarios finales.

## Respaldos

Se incorporó [bin/production-backup](../bin/production-backup), con rutas explícitas para release, configuración privada y destino. El preflight `--dry-run` y el primer respaldo coordinado con `--quiesce` terminaron correctamente. La copia quedó en `/opt/sysforest/backups/backup-20261104T201421Z-WbCoIj`, con marcador `COMPLETE`, hashes y lectura estructural verificados. Después se confirmaron nuevamente los siete servicios saludables y HTTP 200 en la disponibilidad HTTPS.

El script conserva propietarios y ACL en el archivo PostgreSQL custom, respalda la colección de auditoría MongoDB y `storage/app`, calcula SHA-256 por lectura secuencial y comprueba la lectura estructural de los archivos. La opción `--quiesce` detiene brevemente los servicios de aplicación que estaban funcionando y reactiva esos mismos contenedores al terminar o fallar. Sin esa opción, los almacenes pueden reflejar instantes distintos y requieren conciliación.

El script no incluye secretos ni Redis, no configura copias externas, no elimina respaldos previos y no aplica retención automática. Posteriormente, con autorización explícita, se completó una copia inicial fuera del VPS en Ubuntu del equipo local: `/home/josev/.local/share/sysforest-deploy/20261104/production-backup/backup-20261104T201421Z-WbCoIj`. Sus hashes se verificaron correctamente. Esta copia manual inicial no constituye un servicio periódico de respaldo externo.

En esta entrega no se programó cron ni un temporizador; periodicidad y retención permanecen pendientes. La lectura de los archivos y sus hashes no equivale a un ensayo integral de restauración en un entorno independiente. La restauración inicial para migrar al VPS sí se completó; todavía no se ha restaurado este nuevo respaldo.

## Pendientes al cierre

- Configurar SMTP real: durante esta preparación no se enviaron correos. Las invitaciones y recuperaciones que requieren entrega por correo no deben darse por operativas hasta verificar ese transporte.
- Definir y verificar respaldos periódicos locales y externos, su política de retención y un ensayo de restauración del nuevo respaldo. La copia externa inicial ya está confirmada.
- Instalar y verificar el APK productivo en un teléfono físico, incluyendo trabajo offline, sincronización y fotografías.
- Completar la preparación de distribución móvil para usuarios finales cuando corresponda.
- Sincronizar los cambios de código y documentación con GitHub. El despliegue y la compilación no constituyen por sí mismos un commit ni un push.

Los manuales técnico y de usuario se actualizan con este estado. La guía operativa debe consultarse junto con los datos reales del despliegue, sin reutilizar credenciales, direcciones loopback ni procedimientos de siembra del piloto local.

## Corrección de bibliotecas públicas en cartografía

El 4 de noviembre se corrigió una omisión del paquete de despliegue: su filtro excluía cualquier componente de ruta llamado `vendor`, incluyendo `public/vendor`. El servidor devolvía una página HTML con estado 404 al solicitar Leaflet, Select2 y jQuery; el navegador rechazaba su MIME y `catalog-select.js` no encontraba jQuery. La configuración MIME de Nginx era correcta.

Se restauraron los 29 archivos versionados de `public/vendor`, incluidas bibliotecas, imágenes, licencias y manifiestos. Se cotejaron los hashes SHA-256 de los manifiestos disponibles, del origen y de los archivos publicados. Nginx ya montaba `public` en lectura: no hubo que reiniciar servicios ni cambiar las bases de datos, los permisos de acceso o las reglas de seguridad del navegador.

El empaquetador operativo distingue ahora las dependencias Composer de las bibliotecas públicas y exige que todos los archivos de `public/vendor` estén presentes en el archivo comprimido. Su opción `--package-only` permite comprobar el paquete sin publicar. El target `production` del Dockerfile añade una comprobación de los 14 recursos de ejecución esenciales para detener una construcción incompleta antes de desplegarla.

Comprobaciones realizadas después de la reparación:

- Los 14 recursos de ejecución (7 JavaScript, 2 CSS y 5 PNG) devolvieron HTTPS 200, MIME correcto, `nosniff` y hashes coincidentes con el origen.
- Las páginas autenticadas `/map?scope=national` y `/analytics?scope=national` devolvieron 200. Sus 29 recursos CSS/JavaScript, incluidas las importaciones locales de módulos, respondieron con MIME y hashes correctos; el orden jQuery → Select2 → `catalog-select.js` se conservó.
- La sesión de verificación se cerró. `/.env` y `/vendor/autoload.php` continuaron bloqueados; una biblioteca inexistente siguió respondiendo 404.

Esta revisión acredita las respuestas HTTP, integridad, dependencias y orden de carga. No constituye una prueba visual del navegador ni de todos los proveedores externos de mapas base. Para eliminar una página de error que permanezca cargada, recargar la cartografía con Ctrl + F5.
