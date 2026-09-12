@extends('layouts.auth')
@section('title', 'Recuperar contraseña')
@section('eyebrow', 'RECUPERACIÓN DE ACCESO')
@section('heading', 'Recupera tu contraseña')
@section('intro', 'Escribe tu correo. Si la cuenta permite recuperar el acceso, recibirás las instrucciones para crear una nueva contraseña.')
@section('content')
    <form class="auth-form" method="POST" action="{{ route('password.email') }}">
        @csrf
        <x-field name="email" label="Correo electrónico" type="email" required autocomplete="email" maxlength="254" placeholder="tu.nombre@institucion.edu.co" autofocus />
        <button class="button auth-primary" type="submit">Enviar instrucciones</button>
    </form>
    <a class="auth-back text-link" href="{{ route('login') }}">← Volver a iniciar sesión</a>
@endsection
