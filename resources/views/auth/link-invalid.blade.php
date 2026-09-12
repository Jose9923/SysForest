@extends('layouts.auth')
@section('title', 'Enlace no disponible')
@section('eyebrow', 'ACCESO A TU CUENTA')
@section('heading', 'Este enlace no está disponible')
@section('intro', 'El enlace puede haber vencido o ya haberse utilizado.')
@section('content')
    <div class="identity-callout">
        <p>Si recibiste una invitación, solicita un nuevo enlace al administrador de tu organización.</p>
    </div>
    <div class="auth-form">
        <a class="button auth-primary" href="{{ route('login') }}">Ir a iniciar sesión</a>
        <a class="button secondary auth-primary" href="{{ route('password.request') }}">Recuperar contraseña</a>
    </div>
@endsection
