@extends('layouts.auth')
@section('title', 'Verificar correo')
@section('eyebrow', 'CONFIRMACIÓN DE TU CUENTA')
@section('heading', 'Verifica tu correo')
@section('intro', 'Confirma que esta dirección de correo te pertenece para continuar a la aplicación.')
@section('content')
    @if ($invalidLink ?? false)
        <div class="identity-callout warning" role="alert">El enlace no está disponible o ha vencido. Solicita un nuevo correo de verificación.</div>
    @endif
    @if (($verificationToken ?? null) && ($user ?? null) && !($invalidLink ?? false))
        <form class="auth-form" method="POST" action="{{ route('verification.verify') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $verificationToken }}">
            <p class="auth-help">Pulsa el botón para confirmar tu dirección de correo.</p>
            <button class="button auth-primary" type="submit">Confirmar correo electrónico</button>
        </form>
    @endif
    @if (isset($user) && $user)
        <div class="identity-callout">
            <span class="identity-small-label">Correo de tu cuenta</span>
            <strong>{{ $user->email }}</strong>
        </div>
        <form class="auth-form" method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button class="button secondary auth-primary" type="submit">Enviar nuevo correo de verificación</button>
        </form>
        <p class="auth-help">Revisa también la carpeta de correo no deseado.</p>
    @else
        <a class="auth-back text-link" href="{{ route('login') }}">Iniciar sesión para verificar mi correo</a>
    @endif
@endsection
