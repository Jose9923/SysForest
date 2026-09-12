@extends('layouts.auth')
@section('title', 'Iniciar sesión')
@section('heading', 'Inicia sesión')
@section('intro', 'Ingresa con tu cuenta para acceder a tu espacio de trabajo.')
@section('content')
    <form class="auth-form" method="POST" action="{{ route('login.store') }}">
        @csrf
        <x-field name="email" label="Correo electrónico" type="email" required autocomplete="username" maxlength="254" placeholder="tu.nombre@institucion.edu.co" autofocus />
        <div class="field">
            <label for="password">Contraseña</label>
            <input id="password" name="password" type="password" required autocomplete="current-password" maxlength="128">
            @error('password')<span class="field-error">{{ $message }}</span>@enderror
        </div>
        <div class="auth-form-link"><a class="text-link" href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a></div>
        <button class="button auth-primary" type="submit">Iniciar sesión <span aria-hidden="true">→</span></button>
    </form>
    <p class="auth-help">¿Es tu primera vez? El administrador de tu organización puede invitarte por correo.</p>
@endsection
