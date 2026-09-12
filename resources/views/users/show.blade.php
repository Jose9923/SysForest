@extends('layouts.institutional')
@section('title', 'Administrar cuenta')
@push('styles')<link rel="stylesheet" href="{{ asset('css/auth.css') }}">@endpush
@section('content')
    <a class="back-link" href="{{ route('users.index') }}">← Usuarios globales</a>
    <div class="page-heading"><div><p class="eyebrow">CUENTA GLOBAL</p><h1>{{ $user->name }}</h1><p class="muted">{{ $user->email }}</p></div><span class="pill {{ $user->active ? '' : 'identity-warning' }}">{{ $user->active ? 'Cuenta activa' : 'Cuenta desactivada' }}</span></div>
    <div class="identity-stack">
        <div class="identity-grid">
            <section class="panel identity-panel" aria-labelledby="account-state-heading">
                <h2 id="account-state-heading">Estado de la cuenta</h2>
                <p class="muted">Una desactivación global impide acceder a todas las organizaciones de esta cuenta.</p>
                @can('users.manage')
                    <form class="identity-form" method="POST" action="{{ route('users.update', $user->id) }}">
                        @csrf
                        @method('PUT')
                        <div class="field"><label for="active">Estado global</label><select id="active" name="active" required><option value="1" @selected((string) old('active', (int) $user->active) === '1')>Activa</option><option value="0" @selected((string) old('active', (int) $user->active) === '0')>Desactivada</option></select></div>
                        <div class="form-actions"><button type="submit" class="button">Guardar estado global</button></div>
                    </form>
                @else
                    <p>{{ $user->active ? 'La cuenta está activa.' : 'La cuenta está desactivada.' }}</p>
                @endcan
            </section>
            <section class="panel identity-panel" aria-labelledby="assigned-scopes-heading">
                <h2 id="assigned-scopes-heading">Asignaciones actuales</h2>
                <p class="muted">Alcances nacionales y departamentales registrados para esta cuenta.</p>
                <div class="identity-list">
                    @forelse ($nationalAssignments as $assignment)<div class="identity-list-item"><div><strong>{{ $assignment->role->name }}</strong><p>Ámbito nacional</p></div><span class="pill">Nacional</span></div>@empty<div class="identity-list-item"><p class="muted">Sin asignación nacional.</p></div>@endforelse
                    @forelse ($departmentAssignments as $assignment)<div class="identity-list-item"><div><strong>{{ $assignment->department->name }}</strong><p>{{ $assignment->role->name }}</p></div><span class="pill">Departamento</span></div>@empty<div class="identity-list-item"><p class="muted">Sin asignación departamental.</p></div>@endforelse
                </div>
            </section>
        </div>

        <section class="panel identity-panel" aria-labelledby="grant-heading">
            <div class="identity-section-heading"><div><h2 id="grant-heading">Asignar un ámbito administrativo</h2><p class="muted">Los roles de organización se administran desde la membresía de cada institución.</p></div></div>
            <div class="identity-grid">
                @can('roles.assign-national')
                    <form class="identity-assignment" method="POST" action="{{ route('users.roles', $user->id) }}">
                        @csrf
                        <input type="hidden" name="scope" value="national"><input type="hidden" name="role" value="SUPER_ADMIN">
                        <h3>SUPER_ADMIN</h3><p>Permite administrar cuentas, territorios y organizaciones en el ámbito nacional.</p>
                        <button type="submit" class="button secondary">Asignar rol nacional</button>
                    </form>
                @endcan
                @can('roles.assign-department')
                    <form class="identity-assignment identity-form" method="POST" action="{{ route('users.roles', $user->id) }}">
                        @csrf
                        <input type="hidden" name="scope" value="department"><input type="hidden" name="role" value="DEPARTMENT_ADMIN">
                        <div><h3>DEPARTMENT_ADMIN</h3><p>Permite consultar la información de las organizaciones del departamento elegido.</p></div>
                        <div class="field"><label for="department_id">Departamento autorizado</label><select id="department_id" name="department_id" required><option value="" disabled @selected(!old('department_id'))>Selecciona un departamento</option>@foreach ($departments as $department)<option value="{{ $department->id }}" @selected(old('department_id') === $department->id)>{{ $department->name }} · {{ $department->country->name }}</option>@endforeach</select></div>
                        <button type="submit" class="button secondary">Asignar rol departamental</button>
                    </form>
                @endcan
            </div>
        </section>
    </div>
@endsection
