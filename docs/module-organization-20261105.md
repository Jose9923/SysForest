# Organización del backend por módulos

La reorganización del 5 de noviembre de 2026 mantiene `Controller → Service → Repository → Model`. Cada capa contiene carpetas de módulo con nombres consistentes con las vistas Blade. Los nombres cortos de las clases permanecen iguales; la mayoría de los namespaces incorporan el módulo.

## Encontrar un módulo

Por ejemplo, el módulo de árboles se encuentra en:

```text
app/Http/Controllers/Trees/
app/Http/Requests/Trees/
app/Services/Trees/
app/Repositories/Trees/
app/Models/Trees/
app/Policies/Trees/
app/Validation/Trees/
routes/web/trees/routes.php
resources/views/trees/
```

Inspecciones utiliza `Inspections`; inventarios, `Inventories`; mantenimientos, `Maintenances`; evidencias, `Evidences`; riesgo, `Risk`; carbono, `Carbon`; cartografía, `Maps`; gráficas, `Analytics`; reportes, `Reports`; importaciones, `Imports`; auditoría, `Audit`. También existen `Auth`, `Users`, `Profile`, `Organizations`, `Memberships`, `Invitations`, `Territories`, `Catalogs`, `Taxonomy`, `Conditions` y `Publication`.

`Mobile` agrupa los adaptadores y recibos de la API móvil. `PublicPortal` agrupa el portal y sus proyecciones públicas (la palabra `public` es reservada en PHP). Los cálculos y permisos forestales compartidos utilizan `Forestry`; `Tenancy`, `Infrastructure` y `Shared` contienen responsabilidades transversales concretas.

Los DTO, enums, validadores, políticas, recursos HTTP, middleware, renderers de excepciones y comandos siguen la misma organización cuando pertenecen a una capa amplia. Los paquetes que ya tenían una responsabilidad única —`App\Auth`, `App\Auditing`, `App\Catalogs`, `App\Imports`, `App\Reports` y `App\Tenancy`— conservan su agrupación. El controller base permanece en `app/Http/Controllers/Controller.php`; el proveedor principal sigue en `app/Providers/AppServiceProvider.php`.

## Rutas y permisos

`routes/web.php` y `routes/api.php` son los puntos de entrada de Laravel. El primero carga explícitamente los módulos dentro de los grupos originales; el segundo carga `routes/api/mobile/v1.php`. `routes/console.php` conserva la programación existente. No hay carga mediante glob ni orden alfabético implícito.

Hay 33 archivos de módulo y tres puntos de entrada. Auth, invitaciones y organizaciones tienen archivos separados cuando sus rutas pertenecen a distintos grupos de autenticación o tenant. Taxonomy y Conditions se cargan dentro del prefijo histórico `admin/catalogs`. Los endpoints web JSON de mapas y gráficas conservan sus cookies y middleware web; la API pública sigue excluyendo `web`; la API móvil conserva Bearer/JWT y sus propios encabezados. Las URLs, nombres, parámetros, restricciones y permisos no cambian.

## Compatibilidad de clases persistidas

Cuatro archivos se organizan físicamente por módulo pero conservan su nombre completo de clase:

| Archivo | Identidad conservada |
| --- | --- |
| `app/Models/Users/User.php` | `App\Models\User` |
| `app/Jobs/Auth/SendSecurityMail.php` | `App\Jobs\SendSecurityMail` |
| `app/Jobs/Carbon/RefreshCarbonEstimates.php` | `App\Jobs\RefreshCarbonEstimates` |
| `app/Jobs/Infrastructure/InfrastructureProbeJob.php` | `App\Jobs\InfrastructureProbeJob` |

Se registran como archivos explícitos en `composer.json → autoload.classmap`. User participa en el hash de proveedor de JWT y en la convención de su factory. Los jobs pueden existir serializados en colas. Conservar sus identidades evita invalidar sesiones o trabajos existentes; no se cambia la emisión ni validación de tokens. No crear duplicados, aliases o wrappers que cambien `get_class(User)`.

Las demás clases mantienen PSR-4. Los imports incluyen ahora las relaciones entre módulos; los registros de catálogos, bindings, políticas, configuración y referencias de 24 vistas Blade se actualizaron. Los nombres cortos que identifican tipos en auditoría permanecen iguales. No se requiere migrar datos ni cambiar tablas.

## Incorporar código nuevo

1. Elegir el módulo que posee la responsabilidad y crear la clase en su carpeta dentro de la capa correspondiente.
2. Importar clases de otros módulos explícitamente; conservar la separación de reglas en servicios y consultas en repositorios.
3. Añadir rutas al archivo del módulo, dentro del grupo correcto. Si hace falta otro grupo de permisos, declarar su carga de forma explícita en el punto de entrada.
4. Mantener las excepciones de classmap anteriores. Regenerar el autoload al mover archivos o modificar su configuración.

PHP y Composer se ejecutan en Docker. Una imagen antigua conserva su autoload aunque Compose monte `app/` y `routes/` del checkout. Al aplicar esta reorganización hay que reconstruir la imagen y renovar los procesos de app, cola y scheduler; no basta copiar las carpetas. No ejecutar migraciones ni seeders para un cambio de estructura.

## Verificación de esta entrega

La preparación se realizó sobre una copia aislada del commit original. Se revisaron 529 clases y se movieron 490 archivos de clase. La comparación estructural de PHP conserva la lógica después de normalizar exclusivamente los namespaces autorizados. Composer aprobó el autoload optimizado con `--strict-psr --strict-ambiguous --no-scripts`, incluidas las cuatro excepciones.

La comparación de metadatos conserva 60 modelos, 113 relaciones, siete políticas, 11 comandos de la aplicación, UserFactory y las identidades de JWT/cola. Las relaciones se construyeron y su SQL se compiló sin ejecutar consultas; no acredita operaciones de negocio sobre datos reales. Los loaders de rutas expandidos conservan las declaraciones y su orden. No se añadieron ni ejecutaron suites automatizadas.

`bash bin/check` terminó con código 0: formato PSR-12 en 642 archivos, sintaxis PHP y sintaxis JavaScript. Las 193 rutas conservan orden, URL, nombre, métodos, middleware, restricciones y bindings. Se creó y retiró su caché; Laravel añade un nombre generado a `/up` mientras está cacheada, comportamiento propio del framework. La compilación de 128 Blade produjo 148 archivos PHP con sintaxis válida. De las 128 plantillas propias, solo 24 cambian referencias de clases, sin cambios de contenido ni presentación. Una revisión de 830 archivos no encontró referencias residuales a los FQCN trasladados.

La imagen local preparada conserva la imagen base y las dependencias existentes; `composer.lock` permanece intacto. También se verificaron los metadatos de modelos, relaciones, políticas, comandos, JWT y cola usando el código embebido de esa imagen, sin montar las fuentes de preparación. La activación local requiere renovar app, queue y scheduler con ese autoload. No se ejecutan migraciones ni seeders y no se publica esta reorganización en el servidor productivo.

Estas comprobaciones cubren estructura, resolución de clases, rutas, compilación y sintaxis; no equivalen a recorrer de nuevo todos los formularios ni ejecutar operaciones de negocio. No se añadieron ni ejecutaron suites automatizadas.

## Activación local comprobada

La reorganización quedó aplicada en `/home/josev/SYSFOREST`, rama `codex/organize-backend-modules`. Se regeneró el autoload del checkout y se recrearon únicamente los servicios locales app, queue y scheduler con la imagen validada. Los ocho servicios permanecieron saludables, `/health/ready` respondió `{"status":"ok"}` y `/login` respondió HTTP 200. Se retiraron las vistas compiladas anteriores.

La comprobación sobre los contenedores activos confirmó nuevamente las 193 rutas y los metadatos de los 60 modelos y sus 113 relaciones, las siete políticas, los 11 comandos y las identidades conservadas. `.env` y `composer.lock` mantienen sus huellas anteriores; no se ejecutaron migraciones ni seeders. Los cambios quedan sin commit ni push y no se desplegaron en producción.
