@extends('layouts.auth')
@section('title', 'Nueva contraseña')
@section('eyebrow', 'RECUPERACIÓN DE ACCESO')
@section('heading', 'Crea una nueva contraseña')
@section('intro', 'Elige una contraseña diferente y guárdala en un lugar seguro.')
@section('content')
    <form class="auth-form" method="POST" action="{{ route('password.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div class="field">
            <label for="password">Nueva contraseña</label>
            <input id="password" name="password" type="password" required minlength="12" maxlength="128" autocomplete="new-password" aria-describedby="password-help">
            @error('password')<span class="field-error">{{ $message }}</span>@enderror
        </div>
        <p class="identity-input-help" id="password-help">Usa al menos 12 caracteres, con mayúsculas, minúsculas, números y símbolos.</p>
        <div class="field">
            <label for="password_confirmation">Confirmar nueva contraseña</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" maxlength="128" autocomplete="new-password">
        </div>
        <button class="button auth-primary" type="submit">Guardar nueva contraseña</button>
    </form>
    <p class="auth-help">Después del cambio tendrás que iniciar sesión nuevamente.</p>
    <a class="auth-back text-link" href="{{ route('login') }}">← Volver a iniciar sesión</a>
@endsection
