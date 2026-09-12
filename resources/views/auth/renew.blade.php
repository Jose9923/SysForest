@extends('layouts.auth')
@section('title', 'Continuar sesión')
@section('heading', 'Continúa tu sesión')
@section('intro', 'Renueva el acceso para seguir trabajando. Si tu sesión ya terminó, inicia sesión nuevamente.')
@section('content')
    <form class="auth-form" method="POST" action="{{ route('auth.refresh') }}">
        @csrf
        <button class="button auth-primary" type="submit">Continuar sesión <span aria-hidden="true">→</span></button>
    </form>
    <a class="auth-back text-link" href="{{ route('login') }}">Volver a iniciar sesión</a>
@endsection
