@extends('layouts.auth')
@section('title', 'Aceptar invitación')
@section('eyebrow', 'INVITACIÓN A UNA ORGANIZACIÓN')
@section('heading', 'Te damos la bienvenida')
@section('intro', 'Revisa la organización y el rol de tu invitación.')
@section('content')
    <div class="identity-callout">
        <span class="identity-small-label">Organización</span><strong>{{ $invitation->organization->name }}</strong>
        <p>{{ $invitation->role->name }}</p>
        <p>{{ $invitation->email }}</p>
    </div>
    @if ($differentAuthenticatedUser)
        <p class="auth-help">La invitación está dirigida a <strong>{{ $invitation->email }}</strong>. Cierra la sesión actual y vuelve a abrir el enlace del correo para continuar con esa dirección.</p>
        <form class="auth-form" method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="button auth-primary">Cerrar sesión para continuar</button>
        </form>
    @elseif ($existingUser && !$authenticatedInvitationUser)
        <p class="auth-help">Esta dirección ya tiene una cuenta. Inicia sesión con <strong>{{ $invitation->email }}</strong> y vuelve a abrir la invitación para aceptarla.</p>
        <a class="button auth-primary" style="margin-top: 24px" href="{{ route('login') }}">Iniciar sesión con mi cuenta</a>
    @else
        <form class="auth-form" method="POST" action="{{ route('invitations.accept') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            @if (!$existingUser)
                <x-field name="name" label="Nombre completo" required maxlength="150" autocomplete="name" />
                <div class="field"><label for="password">Crea una contraseña</label><input id="password" name="password" type="password" required minlength="12" maxlength="128" autocomplete="new-password" aria-describedby="invitation-password-help"></div>
                <p class="identity-input-help" id="invitation-password-help">Al menos 12 caracteres, con mayúsculas, minúsculas, números y símbolos.</p>
                <div class="field"><label for="password_confirmation">Confirmar contraseña</label><input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" maxlength="128" autocomplete="new-password"></div>
            @else
                <p class="auth-help identity-no-top-margin">La membresía se añadirá a tu cuenta. Conservarás el acceso que tengas en otras organizaciones.</p>
            @endif
            <button type="submit" class="button auth-primary">Aceptar invitación</button>
        </form>
    @endif
    <p class="auth-help">Vencimiento del enlace (Bogotá): {{ \App\Support\Time\BogotaDateTime::format($invitation->expires_at) ?? 'Consulta el correo de invitación' }}.</p>
@endsection
