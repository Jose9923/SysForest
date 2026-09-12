<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $title }}</title></head>
<body style="margin:0;padding:24px 12px;background:#f4f7f5;color:#1a241d;font-family:Arial,sans-serif;">
<table role="presentation" style="max-width:600px;width:100%;margin:auto;border-collapse:collapse;background:#ffffff;border:1px solid #dee7e1;">
    <tr><td style="padding:28px;text-align:center;border-bottom:4px solid #1f8154;">
        <img src="{{ $message->embed(public_path('images/sysforest-logo.jpeg')) }}" alt="SYSFOREST" width="240" style="width:240px;max-width:100%;height:auto;">
        <p style="font-size:13px;color:#526c5d;">Inventario y gestión forestal inteligente</p>
    </td></tr>
    <tr><td style="padding:28px;font-size:16px;line-height:1.65;">
        <h1 style="font-size:24px;color:#114a30;margin:0 0 20px;">{{ $title }}</h1>
        <p>Hola{{ !empty($data['name']) ? ', '.$data['name'] : '' }}:</p>
        @switch($kind)
            @case('invitation')
                <p>Has recibido una invitación para colaborar en <strong>{{ $data['organization'] ?? 'una organización' }}</strong> con el rol <strong>{{ $data['role'] ?? 'institucional' }}</strong>.</p>
                <p>Si ya tienes cuenta, inicia sesión con el correo que recibió esta invitación antes de aceptarla.</p>
                @break
            @case('welcome')
                <p>Tu cuenta está preparada para trabajar en SYSFOREST. Inicia sesión para consultar tus organizaciones y accesos autorizados.</p>
                @break
            @case('verification')
                <p>Confirma que esta dirección de correo te pertenece para acceder a la aplicación institucional.</p>
                @break
            @case('password_reset')
                <p>Recibimos una solicitud para restablecer tu contraseña. El enlace permite elegir una nueva contraseña y cerrar las sesiones anteriores.</p>
                @break
            @case('account_activated')
                <p>Tu cuenta global fue activada. Puedes iniciar sesión y utilizar tus membresías institucionales vigentes.</p>
                @break
            @case('account_deactivated')
                <p>Tu cuenta global fue desactivada y sus sesiones fueron revocadas. Contacta con la administración si necesitas revisar esta decisión.</p>
                @break
            @case('membership_activated')
                <p>Tu membresía en <strong>{{ $data['organization'] ?? 'la organización' }}</strong> está activa.</p>
                @break
            @case('membership_deactivated')
                <p>Tu membresía en <strong>{{ $data['organization'] ?? 'la organización' }}</strong> fue desactivada. Tus accesos vigentes en otras organizaciones no cambian por esta decisión.</p>
                @break
        @endswitch
        @if (!in_array($kind, ['account_deactivated', 'membership_deactivated'], true))
            <p style="margin:28px 0;"><a href="{{ $data['action_url'] }}" style="display:inline-block;padding:14px 22px;background:#17623f;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:bold;">{{ in_array($kind, ['invitation', 'verification', 'password_reset'], true) ? 'Continuar de forma segura' : 'Ir a SYSFOREST' }}</a></p>
        @endif
        @if (in_array($kind, ['invitation', 'verification', 'password_reset'], true))
            <p style="font-size:14px;color:#526c5d;">Este enlace es de un solo uso y vence en {{ $data['expires_minutes'] ?? 60 }} minutos. Abrirlo no confirma la operación: deberás completarla en SYSFOREST.</p>
            <p style="font-size:14px;color:#526c5d;">Si no esperabas este mensaje, puedes ignorarlo. No compartas el enlace.</p>
        @endif
    </td></tr>
    <tr><td style="padding:20px 28px;background:#e8f7ef;color:#526c5d;font-size:12px;">SYSFOREST · Gestión institucional del arbolado<br>Este mensaje nunca solicita que envíes tu contraseña por correo.</td></tr>
</table>
</body>
</html>
