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

## Correo transaccional con Brevo

El 4 de noviembre de 2026, en horario `America/Bogota`, se activó Brevo para esta instalación. `varcor.tech` quedó autenticado y `notificaciones@varcor.tech` aparece verificado con DKIM y DMARC. Los cuatro registros DNS de correo se añadieron conservando los registros de los sitios publicados. El remitente de esta aplicación conserva el nombre **SYSFOREST**.

Se configuró SMTP en `smtp-relay.brevo.com:587`, con `MAIL_SCHEME=smtp`, negociación STARTTLS y validación del certificado. `MAIL_URL` debe estar **ausente**, no definido con una cadena vacía: en la versión desplegada de Laravel 13, ese valor vacío anula el transporte y provoca `Unsupported mail transport []`. El login y la clave SMTP permanecen únicamente en configuración privada, fuera del repositorio y de la imagen.

Se recrearon `app`, `queue` y `scheduler` para cargar el entorno; se verificaron en los tres servicios el transporte `EsmtpTransport`, la configuración esperada y la presencia de credenciales sin imprimirlas. Los servicios PHP y Nginx quedaron saludables y las comprobaciones HTTP/HTTPS fueron satisfactorias. La prueba dirigida al correo administrativo fue **aceptada por el transporte** y el usuario confirmó su **recepción el 4 de noviembre de 2026**. Son dos comprobaciones distintas: una respuesta SMTP satisfactoria no acredita por sí sola la llegada a la bandeja.

EcoData y SysForest usan la misma cuenta gratuita de Brevo: el cupo de **300 envíos diarios es compartido**, no se asignan 300 a cada aplicación. Los envíos pendientes del proveedor pueden llegar después de que un enlace haya vencido; en ese caso se debe solicitar uno nuevo. Consultar el uso y los registros de entrega en Brevo. [Límites oficiales del plan gratuito](https://help.brevo.com/hc/en-us/articles/208580669-FAQs-What-are-the-limits-of-the-Free-plan).

Revisar en el panel de Brevo el vencimiento y estado de la clave SMTP privada. Su renovación requiere actualizar ambas configuraciones privadas y recrear sus servicios PHP; no colocar la clave en manuales, comandos de ejemplo ni archivos versionados. Brevo también puede desactivar claves después de 90 días sin envíos satisfactorios. [Administración de claves SMTP](https://help.brevo.com/hc/en-us/articles/7959631848850-Create-and-manage-your-SMTP-keys).

## Pendientes al cierre

- Completar los recorridos de invitación/recuperación desde los enlaces recibidos. El transporte SMTP ya está activado y el usuario confirmó la recepción de las pruebas; este envío no acredita todos los flujos funcionales.
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

## Captura completa y aplicación 0.3.0 · 4 de noviembre de 2026

Se publicó el formulario web que registra árbol y primera inspección juntos y la ampliación móvil de inventarios y mantenimientos. El delta de 32 archivos fue verificado por SHA-256; se aplicó únicamente la migración aditiva 000032. Se reconstruyó la imagen y se recrearon app, queue y scheduler. Los siete servicios terminaron saludables. La configuración privada y SMTP permanecieron idénticos, y las bibliotecas y descargas anteriores conservaron sus huellas.

Imagen activa: `sha256:ee2b1ce84aed944ace6a463a8cbae4a4188375576ff874a31f9d548246e608c7`. El respaldo previo conserva datos, fuente e imagen bajo `/opt/sysforest/backups/field-capture-20261105T011715Z`; la copia de datos es `/opt/sysforest/backups/field-capture-20261105T011715Z/data/backup-20261105T011733Z-mgULmu`. Fue un respaldo online: no representa una instantánea coordinada de todos los almacenes ni un ensayo de restauración. Se conserva la imagen anterior etiquetada como `sysforest-app:before-field-capture-20261105t011715z`. Las rutas del respaldo usan UTC; la publicación corresponde al 4 de noviembre en America/Bogota.

HTTP autenticado comprobó el alta con 23 condiciones físicas, 42 fitosanitarias y seis categorías de fauna, la sugerencia de riesgo sin crear árbol y los formularios de visitas posteriores. En ambas instituciones se comprobaron capacidades, inventarios, árboles y mantenimientos. Una creación de inventario incompleta respondió 422 sin registros. Se cerraron exclusivamente las sesiones de verificación; no se insertaron datos productivos de prueba.

[Descargar APK 0.3.0](https://sysforest.varcor.tech/descargas/SysForest-Mobile-0.3.0.apk): paquete Android `co.sysforest.mobile`, versión 0.3.0+5, variante debug universal y misma firma anterior. Compilación, análisis estático, firma, tamaño, descarga HTTPS por rangos y manifiesto de hash aprobados. Instalar encima y después actualizar referencias desde Sincronizar. La validación física permanece pendiente. No se realizó commit ni push de esta ampliación.

El detalle de la implementación y sus límites está en [captura inicial y módulos móviles](field-capture-20261104.md).

## Selectores por órgano y condiciones · actualización del 04/11/2026

Actualización finalizada sin migraciones ni cambios de configuración. Imagen activa: `sha256:fae16e6047d7b5055bcc6c2fa75f2ebc7a3f77e5aa4f65c54d5f2a8cbac6e26a`; delta de 17 archivos, siete servicios saludables y verificaciones HTTPS de nueve páginas, riesgo y API pública aprobadas. Los formularios y filtros ahora permiten varios órganos y varias condiciones por órgano; se conserva ANY/ALL y la separación de referencias privadas/públicas.

Respaldo de datos online, fuente e imagen: `/opt/sysforest/backups/condition-cascade-20261105T014535Z`; datos `/opt/sysforest/backups/condition-cascade-20261105T014535Z/data/backup-20261105T014607Z-Bn13gI`. Tag anterior: `sysforest-app:before-condition-cascade-20261105t014535z`. El respaldo online no constituye una instantánea global coordinada. Configuración productiva, SMTP y los 34 archivos previos de vendor/descargas permanecieron idénticos antes de publicar la APK adicional.

Android [0.3.1+6](https://sysforest.varcor.tech/descargas/SysForest-Mobile-0.3.1.apk) publicado y verificado, con misma firma y SHA-256 `d1f3bbee1ebca53d16344c95078edad1ab8c9ba770df9c72faa81eeef50f3480`. Conserva los enlaces de versiones anteriores. El análisis y la compilación fueron satisfactorios; no hubo prueba física de teléfono. Alcance, archivos, verificaciones y límites: [informe de cascada](condition-cascade-20261104.md).

## Bandeja de árboles y reporte de riesgo · actualización del 04/11/2026

Gestión del riesgo abre la bandeja institucional con la evaluación de la última visita, filtros y reporte privado por árbol en CSV, Excel y PDF. La metodología versionada conserva su pestaña propia. Se publicó un delta de 18 archivos e imagen `sha256:8affc5d16977a9bc0da375f4b8443922e6c80e30f55d14431cc5b872676e6761`; siete servicios saludables, hashes de fuente y comprobaciones HTTPS de vistas, filtros, paginación, alcance y tres descargas aprobados. El reporte privado se rechaza en las cuatro rutas públicas comprobadas. La sesión de comprobación quedó cerrada.

Respaldo previo `/opt/sysforest/backups/risk-inbox-20261105T030635Z`; datos online `/opt/sysforest/backups/risk-inbox-20261105T030635Z/data/backup-20261105T030707Z-0d4gUP`; tag `sysforest-app:before-risk-inbox-20261105t030635z`. Configuración privada y 35 archivos de vendor/descargas sin cambios. No hubo migraciones, seeders, cambios de datos forestales ni versión móvil nueva. Detalles y límites: [informe de bandeja de riesgo](risk-tree-inbox-20261104.md).
