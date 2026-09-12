@extends('layouts.institutional')
@section('title', 'Invitar usuario')
@push('styles')<link rel="stylesheet" href="{{ asset('css/auth.css') }}">@endpush
@section('content')
    <a class="back-link" href="{{ route('memberships.index') }}">← Usuarios de la organización</a>
    <div class="page-heading"><div><p class="eyebrow">AMPLIAR EL EQUIPO</p><h1>Invitar usuario</h1><p class="muted">Envía una invitación para incorporarse a {{ $organization->name }}.</p></div></div>
    <form class="panel identity-panel identity-compact identity-form" method="POST" action="{{ route('invitations.store') }}">
        @csrf
        <x-field name="email" label="Correo de la persona invitada" type="email" required maxlength="254" autocomplete="email" placeholder="nombre@institucion.edu.co" />
        <div class="field">
            <label for="role">Rol en esta organización</label>
            <select id="role" name="role" required><option value="" disabled @selected(!old('role'))>Selecciona un rol</option>@foreach ($roles as $role)<option value="{{ $role->name }}" @selected(old('role') === $role->name)>{{ $role->name }}</option>@endforeach</select>
            @error('role')<span class="field-error">{{ $message }}</span>@enderror
        </div>
        <div class="identity-callout identity-no-top-margin">
            <p>ORGANIZATION_ADMIN administra la organización y sus miembros. OFFICIAL accede al trabajo de su organización sin administrar usuarios.</p>
        </div>
        <p class="muted">Si la persona ya tiene una cuenta, podrá aceptar la invitación sin crear otra.</p>
        <div class="form-actions"><a class="button secondary" href="{{ route('memberships.index') }}">Cancelar</a><button type="submit" class="button">Enviar invitación</button></div>
    </form>
@endsection
