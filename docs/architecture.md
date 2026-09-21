# Arquitectura de SYSFOREST

## Estado y alcance

Este documento conserva la arquitectura objetivo acordada para SYSFOREST. El desarrollo actual alcanza Fase 9: territorio, autenticación, catálogos, inventarios, árboles, inspecciones, evidencias y mantenimientos. La infraestructura de Fase 2 continúa como base. Los módulos de fases posteriores son diseño, no capacidades implementadas.

La Fase 5 concreta los catálogos forestales nacionales, la taxonomía, las condiciones y las reglas versionadas de clasificación. Tree y TreeInspection se concretaron en Fases 6/7, fenología como campos de inspección, Evidence en Fase 8 y Maintenance en Fase 9. Mapas generales, portal público, reportes e importación conservan sus fases posteriores. La trazabilidad implementada se detalla en [catálogos forestales](forestry-catalogs.md) y en la [matriz de las 94 variables](excel-field-mapping.md).

SYSFOREST será una aplicación web para inventario y gestión del arbolado urbano, con un piloto en la Institucion Educativa INEM Luis Lopez de Mesa, municipio de Villavicencio, departamento del Meta, Colombia. El piloto no cambia la arquitectura territorial y municipal del producto.

## Referencias y decisiones

- El Excel de inventario es la referencia principal de campos, categorías y terminología forestal. Ninguna variable debe desaparecer porque falte en el mockup.
- El HTML es la referencia visual y de interacción. Sus datos, mapas, cálculos manuales y almacenamiento en memoria son demostrativos.
- El logo original de SYSFOREST será un recurso institucional para login, menú, correos y documentos.
- La aplicación sigue MVC con Laravel y renderizado en servidor mediante Blade. La ficha se divide en layouts, componentes y parciales reutilizables.
- El desarrollo funciona primero en local con Docker Compose. El despliegue posterior en un VPS de Hostinger no forma parte de la Fase 2.

## Flujo obligatorio de responsabilidades

El acceso a datos de los casos de uso debe seguir **Controller → Service → Repository → Model**. Las rutas, middleware, Form Requests y Policies/Gates validan el contexto y los permisos antes de ejecutar la operación correspondiente.

| Capa | Responsabilidad |
| --- | --- |
| Controller | Coordinar la petición validada, invocar el servicio y devolver una vista Blade, redirección o API Resource. |
| Service | Ejecutar el caso de uso, aplicar reglas de negocio y coordinar la transacción y sus colaboradores. |
| Repository | Resolver consultas y persistencia mediante los modelos. Centralizar filtros, restricciones de alcance y SQL espacial parametrizado. |
| Model | Representar la entidad Eloquent, sus relaciones, casts y comportamiento de persistencia propio del modelo. |
| Form Request | Validar entradas y realizar la autorización de la petición cuando corresponda. |
| Policy/Gate | Definir las operaciones autorizadas sobre el contexto territorial y organizacional. |
| Blade | Presentar información escapada y componentes reutilizables, sin consultas ni reglas de negocio. |

Los controladores no consultan modelos ni construyen SQL. Los servicios no omiten el repositorio para acceder a la base. Las reglas puras, como normalización de coordenadas o cálculo dasométrico, pueden extraerse a clases de dominio comprobables sin base de datos; ello no altera el flujo de persistencia obligatorio. No se crean controladores, servicios ni repositorios universales que concentren todos los módulos.

Los reportes se presentan mediante **vistas Blade**. El controlador solicita los resultados a un servicio de reportes, que utiliza repositorios con el mismo conjunto de filtros aplicado a tablas, mapas, indicadores y exportaciones. Cuando corresponda generar un PDF, su contenido procede de una plantilla Blade de reporte.

## Organización del código

La estructura se incorporará progresivamente, cuando la fase correspondiente necesite cada carpeta:

```text
app/
  Http/Controllers/
  Http/Requests/
  Http/Resources/
  Http/Middleware/
  Services/
  Repositories/
  Models/
  Policies/
  Domain/Forestry/
  Domain/Geospatial/
  DTOs/
  Enums/
  Jobs/
  Mail/
resources/views/
  layouts/
  components/
  partials/
  auth/
  trees/
  inspections/
  reports/
  public/
  emails/
database/migrations/
database/seeders/
tests/Unit/
tests/Feature/
docs/
```

El código PHP debe cumplir PSR-12, usar tipos y retornos explícitos cuando proceda y mantener `declare(strict_types=1)` donde sea compatible. Los bloques PHPDoc se redactan en español y describen contratos, parámetros o decisiones que aporten información; no repiten innecesariamente las firmas.

## Aislamiento y permisos

Se utilizará una aplicación, una base PostgreSQL y un esquema compartido. La organización propietaria es el tenant y `organization_id` es la clave de aislamiento operativo. Toda organización pertenece obligatoriamente a un municipio.

El usuario puede ser miembro de varias organizaciones. El tenant activo se deriva de su membresía autorizada y se comprueba en cada petición; no se acepta como autoridad un `organization_id` recibido desde el navegador. Las restricciones se aplican en middleware, Policies, servicios, repositorios y constraints de base. También se aplican a jobs, archivos, importaciones y exportaciones.

| Rol | Alcance previsto |
| --- | --- |
| SUPER_ADMIN | Administración global, territorios, organizaciones, usuarios, catálogos nacionales, auditoría y restauración autorizada. |
| DEPARTMENT_ADMIN | Consulta y consolidación dentro de su departamento; no modifica árboles de las organizaciones. |
| ORGANIZATION_ADMIN | Administración y operación de sus organizaciones, funcionarios, inventarios, anulaciones y restauraciones. |
| OFFICIAL | Consulta y operación forestal de su organización, incluidos registros de otros funcionarios; sin administrar usuarios ni restaurar anulados. |

`created_by` conserva al autor original. La propiedad del registro pertenece a la organización. Cualquier bypass de alcance para operaciones globales debe ser explícito y autorizado.

Los catálogos forestales son nacionales y **no llevan `organization_id`**. Las organizaciones utilizan sus valores sin redefinirlos. Solo SUPER_ADMIN recibe `catalogs.manage`; los otros tres roles pueden consultar los valores autorizados. Se conservan códigos estables, orden y estado `active`; desactivar no borra el valor ni sus referencias históricas.

## Identidad del árbol y seguimiento

`Tree` representa un individuo permanente. Mantiene identidad, organización, código, ubicación, taxonomía y clasificación patrimonial/protegida. El código humano es independiente del UUID interno.

`TreeInspection` representa la observación en una fecha: mediciones, madurez, condiciones físico-mecánicas, condiciones fitosanitarias, fauna y observaciones. Su campaña puede registrarse como contexto. Una nueva campaña o inspección no duplica automáticamente el árbol ni sobrescribe su historia.

Las condiciones se normalizan por categoría y órgano; una misma palabra, como «Hongos», no sustituye la identidad de condiciones de órganos diferentes. Fase 5 conserva **23 condiciones físico-mecánicas y 42 fitosanitarias**. El bloque fitosanitario de 44 columnas del Excel incluye además **dos campos compuestos de fenología**, CE/CF, que solicitan fecha y estimación de desarrollo. Esos campos no se convierten en condiciones ni originan un catálogo de etapas sin respaldo. Cada imagen tiene exactamente un tipo de evidencia y un propietario explícito. Las órdenes de mantenimiento se crean manualmente, con reglas de estado en MaintenanceWorkflowService; sus fotografías reutilizan Evidence y sus restricciones tenant. [Contrato Fase 9](maintenances.md).

El origen se divide en `establishment_origins` (Espontánea y Plantada) y `biogeographic_origins` (Especie nativa, Especie exótica, Especie naturalizada y Especie invasora). La única columna de origen del Excel no permite inferir ambas respuestas. Introducido del HTML no se siembra ni se transforma automáticamente en uno de esos valores.

La clasificación nacional adopta las reglas del Excel para entradas positivas: `ceil(DAP_cm / 5)` y `ceil(altura_total_m / 10)`, con límite inferior exclusivo y superior incluido. La madurez conserva Fustal joven (clases 1–3), Fustal medio (4–6), Fustal maduro (7–9) y Fustal sobremaduro (≥10). No se usan las bandas manuales del HTML ni se inventan equivalencias con Juvenil, Adulto o Senescente.

`classification_rule_versions` y `maturity_rule_bands` centralizan los parámetros de `EXCEL_V1`. La versión sellada mantiene sus parámetros y bandas inmutables; cambiar una fórmula requiere otra versión. Los límites utilizan `DECIMAL(18,6)` y cálculo exacto con BCMath. Las futuras inspecciones conservarán la versión aplicada y sus resultados; la automatización de madurez sobre inspecciones no se implementa en Fase 5.

Los estados del individuo y sus transiciones se conservan en un historial. La muerte, tala, traslado o retiro no eliminan el árbol. Soft delete se reserva para registros erróneos e incluye autor, motivo y notas. Los cambios críticos utilizan auditoría y concurrencia optimista mediante una versión.

## Autenticación, interfaz y datos públicos

La autenticación prevista utiliza JWT con access token de duración limitada y refresh token rotatorio, revocable y almacenado de forma segura. En el navegador se utilizan cookies HttpOnly, Secure en producción y SameSite apropiado, manteniendo CSRF para las operaciones web. No se utiliza localStorage para JWT. La dependencia concreta debe ser mantenida y compatible con Laravel 13; se verificará antes de integrarla en su fase.

JavaScript se limita a interacción y asistencia: Select2, Leaflet, mapas, imágenes y previsualización de cálculos. El backend valida y recalcula los valores derivados. Leaflet sustituye los mapas decorativos del mockup y PostGIS conserva la verdad espacial.

El portal público tiene layout, rutas y Resources propios, con una lista explícita de campos permitidos. No publica IDs internos, funcionarios, correos, auditoría, tokens, IP ni notas administrativas. Los filtros compartidos afectan a mapas, tablas, indicadores, reportes y exportaciones. La publicación de fotografías y observaciones debe respetar su clasificación pública o interna.

## Geometría e intercambio

Las coordenadas decimales y DMS se normalizan a `POINT` SRID 4326, usando longitud como X y latitud como Y. Se preservan los metadatos del GPS cuando existan. PostGIS realiza filtros por bbox, radio, polígono y agregaciones espaciales, con índices GiST.

La importación acepta la plantilla oficial de SYSFOREST, valida encabezados, catálogos, datos y coordenadas, presenta errores por fila y registra el lote. Los valores calculados se recalculan en backend. Una colisión inequívoca de código impide la importación; la resolución avanzada de duplicados queda fuera del MVP.

## Ejecución local y progresión

Los seis servicios mínimos son `nginx`, `app`, `postgres`, `redis`, `queue` y `scheduler`. Nginx expone únicamente el directorio `public` de Laravel. PostgreSQL/PostGIS, Redis y los archivos que requieran persistencia utilizan volúmenes adecuados. Las credenciales proceden de variables de entorno y no se incorporan secretos reales al repositorio.

La Fase 2 debe comprobar la configuración Compose, la construcción y el arranque, la respuesta HTTP de Laravel, la disponibilidad de PostGIS, las conexiones a base y Redis y la ejecución de colas/scheduler. Los resultados realmente ejecutados se registran en la documentación de la fase; un procedimiento descrito no equivale a una prueba aprobada.

Después continúan territorios y multi-tenancy; usuarios/JWT/permisos; catálogos; inventarios/árboles; inspecciones; evidencias; mantenimiento; mapas; portal público; reportes/exportaciones; importación; auditoría/seguridad/concurrencia; y refinamiento con pruebas y responsive. Las comprobaciones de seguridad relevantes se introducen con cada capacidad, sin esperar a la última fase.
