# Invitaciones, verificación y recuperación de cuenta

Esta parte de la Fase 4 utiliza servicios de aplicación, repositorios y modelos separados. Los controladores y Form Requests coordinan los formularios y su autorización; los servicios vuelven a validar las reglas críticas antes de modificar cuentas.

## Contratos de servicios

| Servicio | Métodos públicos |
| --- | --- |
| `InvitationService` | `create(User, array): Invitation`, `preview(string): Invitation`, `accept(string, array, ?User): User` |
| `PasswordService` | `requestReset(string): void`, `reset(string, string): void`, `change(User, string, string): void` |
| `EmailVerificationService` | `send(User): void`, `preview(string): User`, `verify(User, string): void` |
| `SecurityMailService` | `send(string, User|string, array): void`; `deliver(string, string, array): void` es el punto de entrega utilizado por el job de correo. |

## Invitaciones institucionales

La organización procede de `TenantContext`, que debe corresponder al actor. Crear una invitación requiere `users.invite` en esa organización. Únicamente se admiten `ORGANIZATION_ADMIN` y `OFFICIAL`, resueltos desde el catálogo de roles de ámbito `organization`.

La invitación normaliza el correo a minúsculas, genera 32 bytes criptográficamente aleatorios y guarda exclusivamente su SHA-256. El enlace vence en 48 horas. Emitir una nueva invitación para el mismo correo y organización revoca la invitación anterior pendiente.

Abrir el enlace ejecuta una consulta de previsualización, sin aceptar la invitación. La aceptación se completa por POST con CSRF. Si la cuenta existe, el usuario debe autenticarse previamente como esa misma cuenta. El enlace nunca permite cambiar la contraseña ni el nombre de una cuenta existente.

Una cuenta nueva requiere nombre y contraseña confirmada de al menos 12 caracteres con mayúsculas, minúsculas, números y símbolos; el máximo admitido es 128 caracteres. La nueva cuenta se marca verificada porque la invitación demuestra posesión del correo destinatario.

En una única transacción se crean la cuenta cuando corresponda, la membresía y su asignación de rol, se consume la invitación y se registra el evento. Se bloquean las cuentas implicadas en orden de ID, la organización y la invitación. Antes de aceptar se comprueban nuevamente el vencimiento, consumo, revocación, organización activa y autorización vigente del invitador.

Si ya existe una membresía, activa o inactiva, se rechaza tanto la creación como la aceptación de una invitación destinada a reemplazarla. Su rol y estado deben modificarse explícitamente mediante administración de membresías. Una invitación antigua no reactiva un acceso deshabilitado ni degrada un rol vigente.

## Recuperación y cambio de contraseña

La solicitud de recuperación normaliza el correo y siempre tiene respuesta externa genérica. Las cuentas inexistentes o inactivas no reciben mensajes. Un fallo de infraestructura mantiene esa respuesta y registra únicamente un mensaje técnico genérico, sin correo, token ni contraseña.

El token de recuperación es aleatorio, tiene una vigencia de 60 minutos y se almacena solo como SHA-256. Solicitar otro enlace invalida los enlaces anteriores pendientes del mismo propósito. El registro conserva también la dirección de correo emitida: cambiarla invalida el enlace anterior.

El restablecimiento bloquea primero la cuenta y después el token; valida estado, propósito, correo, vencimiento y consumo. Actualiza la contraseña y el remember token, consume los enlaces pendientes de recuperación y revoca todas las sesiones mediante `SessionService::revokeAll(..., 'password_reset')` en la misma transacción.

El cambio de contraseña autenticado comprueba la contraseña actual y la fortaleza de la nueva. También invalida los enlaces de recuperación pendientes y revoca todas las sesiones con motivo `password_changed`. El usuario debe iniciar sesión nuevamente.

Recuperar una contraseña no verifica automáticamente la dirección de correo ni reactiva una cuenta deshabilitada.

## Verificación de correo

Cada enlace corresponde a un usuario y a la dirección exacta que tenía al emitirse. Su token aleatorio se almacena como SHA-256, vence en 60 minutos y solo puede consumirse una vez. Enviar otro enlace invalida los anteriores pendientes de verificación.

La previsualización no modifica `email_verified_at`. La confirmación exige un POST autorizado para el mismo usuario al que pertenece el token, bloquea cuenta y token y comprueba también que la dirección no haya cambiado. La verificación marca la cuenta y consume los enlaces pendientes del mismo propósito en una transacción.

## Correo y tratamiento de secretos

Los tipos admitidos son `invitation`, `welcome`, `verification`, `password_reset`, `account_activated`, `account_deactivated`, `membership_activated` y `membership_deactivated`. Se presentan mediante el Mailable `SecurityNotification` y `resources/views/mail/security.blade.php`, con el logo original y textos escapados por Blade.

Los servicios encolan `SendSecurityMail` después del commit. El job implementa `ShouldQueue` y `ShouldBeEncrypted`: el enlace sensible viaja cifrado en el payload de la cola y, si el job falla, el payload conservado sigue cifrado. La excepción de entrega se sustituye por un mensaje genérico sin conservar la excepción SMTP que pudiera incluir contenido.

Los correos con enlaces sensibles rechazan un transporte `log`, incluso como parte de una cadena de failover o round-robin. `Mail::fake()` se permite en pruebas. La entrega de desarrollo se configura con SMTP y Mailpit local; no se envían pruebas a servicios externos. Las vistas y notificaciones nunca contienen contraseñas.

Las URL se construyen desde `config('app.url')`, una configuración institucional confiable, y rutas fijas: `/invitations/accept`, `/verify-email` y `/reset-password`. Los tokens de acción viajan únicamente en el enlace concreto del correo. Los access JWT y refresh tokens no forman parte de estos enlaces. Los parámetros sensibles de servicios, job y Mailable usan `SensitiveParameter` para ocultarlos en trazas.

El registro de acceso web debe omitir la query de esas URL y los formularios deben mantener CSRF. La integración HTTP y la configuración del servidor se verifican junto con las rutas de la fase.

## Persistencia e integridad

La migración `2026_09_12_000005_create_account_action_tables.php` incorpora:

- `invitations`: UUID, organización, correo normalizado, rol y scope organizacional mediante FK compuesta, invitador BIGINT, hash único, vencimiento y fechas de aceptación/revocación. Un índice parcial impide dos invitaciones pendientes simultáneas para la misma organización y correo.
- `account_action_tokens`: UUID, usuario BIGINT, correo emitido, propósito `verification` o `password_reset`, hash único, vencimiento y consumo. CHECK constraints limitan el propósito, normalización de correo y formato del hash.

No se almacenan tokens de acción en texto plano en estas tablas. Los eventos `invitation.created`, `invitation.accepted`, `password.reset`, `password.changed` y `email.verified` no incluyen secretos. La revocación de sesiones genera sus propios eventos mediante el servicio de sesiones.

## Pruebas de este componente

Las clases `InvitationTest`, `PasswordSecurityTest` y `EmailVerificationTest` prueban permisos y alcance, toma de cuenta mediante invitación, conservación de membresías, contraseña fuerte, vencimiento y reutilización de enlaces, cambios de correo, revocación de sesiones, previsualización sin efectos, rechazo del transporte log, cifrado del payload real de Laravel y escape de las ocho variantes de correo.

Ejecución localizada:

```bash
docker compose exec -T -e DB_DATABASE=sysforest_testing app php artisan test tests/Feature/InvitationTest.php tests/Feature/PasswordSecurityTest.php tests/Feature/EmailVerificationTest.php
```

La ejecución del 12 de septiembre de 2026 completó 36 pruebas y 138 aserciones. Se validaron las plantillas y el payload sin enviar correos SMTP externos. El informe global de la Fase 4 documenta las pruebas HTTP, los otros componentes y los resultados consolidados.
