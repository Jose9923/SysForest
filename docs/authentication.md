# Autenticación y sesiones

SYSFOREST usa JWT de acceso en cookies y sesiones revocables en PostgreSQL. El paquete fijado es php-open-source-saver/jwt-auth 2.9.3, cuyo manifiesto admite PHP 8.4 y Laravel 13. La cuenta conserva su identificador BIGINT; las sesiones y los registros de refresh usan UUID.

La firma del JWT prueba que el servidor lo emitió. La autorización de una solicitud también exige que su sesión siga vigente y que la cuenta continúe activa. Los permisos y la organización seleccionada se resuelven en el servidor: no se confía en roles ni en membresías almacenadas dentro del token.

## Contratos del núcleo

| Componente | Responsabilidad |
| --- | --- |
| AuthService::login(email, password) | Revalida la cuenta y la contraseña bajo bloqueo y crea una sesión con su primer par de tokens. |
| TokenService::issue(user, session) | Firma un access limitado por el vencimiento absoluto de la sesión. |
| TokenService::decode(access) | Verifica firma, tiempos, emisor, audiencia, modelo de usuario, sujeto BIGINT válido y sid UUID. No consulta el estado de la sesión. |
| SessionService::authenticate(sid, userId) | Consulta la sesión y su usuario, verifica la correspondencia y rechaza revocación, vencimiento o cuenta desactivada. |
| RefreshTokenService::rotate(refresh) | Consume el refresh y emite uno nuevo dentro de la misma familia, con detección de reutilización. |
| SessionService::revoke(session, reason) | Revoca una sesión de forma idempotente. |
| SessionService::revokeAll(user, reason) | Revoca las sesiones de una cuenta; se utiliza al cambiar/restablecer contraseña o desactivar globalmente al usuario. |
| RefreshTokenService::revokeByToken(refresh, reason) | Permite cerrar sesión aunque el access haya vencido. Un token desconocido no produce cambios. |
| LogoutService::logout(access, refresh) | Revoca por refresh y también por access criptográficamente válido; la falta de una cookie no deja vigente la otra credencial. |
| AuthCookies::attach / clear | Adjunta o elimina ambas cookies con los mismos nombres, dominio y rutas. |

TokenPair transporta accessToken, refreshToken, session y user entre servicios y Controller. Su serialización y salida de depuración omiten los tokens; la respuesta de navegador los entrega exclusivamente mediante cookies. Los parámetros sensibles están marcados para no mostrarlos como argumentos de las excepciones.

## Persistencia y vencimientos

auth_sessions representa una familia por inicio de sesión. Guarda user_id, expires_at, last_used_at, revoked_at y revocation_reason. Cada refresh_tokens referencia una sesión y guarda un hash SHA-256 único, su vencimiento y consumed_at; no guarda el secreto original.

El refresh contiene 32 bytes aleatorios codificados como 64 caracteres hexadecimales. La entropía procede del generador criptográfico. Un índice parcial impide tener dos refresh sin consumir dentro de una misma familia.

| Configuración | Valor inicial | Unidad o significado |
| --- | --- | --- |
| JWT_TTL | 10 | Minutos de vigencia del access. |
| AUTH_SESSION_TTL_MINUTES | 10080 | Minutos de vigencia absoluta de sesión y refresh: siete días. |
| JWT_ISSUER | APP_URL | Emisor esperado; se compara explícitamente. |
| JWT_AUDIENCE | sysforest-web | Audiencia esperada. |
| JWT_SECRET | Sin valor predeterminado | Clave independiente para HS256, generada y persistida fuera del código. |

Rotar no extiende expires_at. El vencimiento del access también se limita al vencimiento de su sesión. No deben eliminarse los hashes consumidos mientras la familia pueda permanecer vigente: se necesitan para reconocer reutilización.

La blacklist de JWT del paquete está desactivada. La autoridad de revocación es auth_sessions, verificada en cada solicitud autenticada. No se utiliza el método refresh() del paquete: ese método renueva JWT y no implementa el refresh opaco de SYSFOREST.

## Rotación, reutilización y concurrencia

Las mutaciones de autenticación toman bloqueos en orden fijo: usuario, sesión y token. El mismo orden permite serializar refresh y login frente a cambios de contraseña, desactivación o revocación global.

Una rotación válida comprueba el estado actual, consume el token anterior, crea el nuevo hash y actualiza la actividad dentro de una transacción. Todos los registros históricos de esa rotación conservan el mismo auth_session_id.

Si el token ya fue consumido, la transacción revoca la familia con motivo refresh_reuse y registra el evento. La excepción de autenticación se lanza después del COMMIT: lanzarla dentro de la transacción desharía la revocación.

La política es estricta y no concede un período de gracia. Si llegan dos refresh simultáneos con el mismo valor, uno puede rotar correctamente y el otro revoca la familia al detectar el reuso. Por ello también deja de funcionar el access recién emitido por el primero. El navegador debe coordinar un único intento de renovación a la vez; una renovación debe ser una acción explícita mediante POST protegido por CSRF, no un efecto secundario de todos los GET.

## Cookies y ciclo HTTP

Las cookies son sysforest_access y sysforest_refresh. Ambas utilizan Path=/, son HttpOnly, no fijan Domain y usan SameSite=Lax. Secure se exige fuera del entorno local con HTTP. Crear o eliminar cookies añade Cache-Control: no-store, private.

El middleware AuthenticateJwtCookie se ejecuta después de EncryptCookies. Lee exclusivamente la cookie de access y pasa su valor explícitamente a TokenService: no acepta tokens de query, cuerpo, parámetros de ruta ni Authorization como alternativa para el navegador.

Una vez verificado el token, SessionService comprueba la sesión y la cuenta actuales. El middleware establece el usuario del guard web y expone auth_session como atributo de la solicitud. Limpia ambos al finalizar, incluso cuando una operación lanza una excepción. El guard de contexto no autentica a partir de un identificador legado de SessionGuard.

La sesión de Laravel conserva CSRF, mensajes y selección de organización del navegador; no sustituye al JWT como credencial. BindAuthenticatedSession elimina la selección anterior si cambia auth_session_id. La pérdida o el vencimiento de esa sesión de navegación no invalida por sí solo el refresh: el navegador puede obtener un nuevo formulario CSRF y renovar la sesión autenticada mientras PostgreSQL la considere vigente. Los formularios mantienen la protección CSRF, incluyendo login, refresh y logout. No se guardan access ni refresh en localStorage o sessionStorage, ni se incluyen en URLs.

Una cuenta sin verificar puede iniciar una sesión limitada para completar su verificación. El middleware de verificación bloquea el área interna normal. La desactivación de una membresía elimina el acceso institucional correspondiente sin desactivar la cuenta ni sus otras membresías; la desactivación global del usuario bloquea todas sus sesiones.

## Auditoría y verificación

El núcleo registra éxito/fallo de login, reutilización de refresh y revocación de sesiones. Los eventos usan identificadores y motivos controlados; no almacenan contraseñas, tokens, cookies ni el correo introducido en un login fallido.

AuthenticationTest y RefreshTokenTest verifican credenciales inválidas, cookies, expiración, emisor/audiencia, firmas alteradas, correspondencia entre sesión y usuario, revocación con JWT todavía válido, desactivación global, limpieza del contexto y restricciones de PostgreSQL.

La prueba concurrente usa dos procesos PHP y una conexión separada exclusivamente a la base de pruebas. Comprueba que ambos procesos esperan un bloqueo real antes de liberarlos, y verifica una rotación seguida de revocación de familia. Los secretos de ese fixture viajan por stdin, no por argumentos ni salida del proceso.

IdentityHttpTest usa el middleware real PreventRequestForgery desactivando únicamente su excepción automática para PHPUnit. Comprueba formularios y cookies reales, rechazo CSRF sin efectos, límites de solicitudes, cambio del identificador de sesión al iniciar sesión, recuperación de navegación, revocación en logout con cookies incompletas o access vencido y eliminación del tenant al cambiar de identidad.

Los casos conservados en LocalAccessTest comprueban que las rutas y el comando técnico de Fase 3 fueron retirados y que sus grants o identificadores de sesión no conceden acceso. Las pruebas institucionales usan JWT y asignaciones RBAC persistidas, sin desactivar middleware ni sustituir Gates por permisos universales.

Los flujos de recuperación, verificación e invitación se documentan en [account-recovery.md](account-recovery.md); las capacidades por ámbito, en [authorization.md](authorization.md).
