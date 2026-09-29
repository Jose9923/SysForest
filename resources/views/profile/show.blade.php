@extends('layouts.institutional')
@section('title', 'Mi perfil')
@push('styles')<link rel="stylesheet" href="{{ asset('css/auth.css') }}">@endpush
@section('content')
    <div class="page-heading">
        <div><p class="eyebrow">MI CUENTA</p><h1>Tu perfil</h1><p class="muted">Actualiza tus datos y revisa dónde tienes acceso.</p></div>
        <a class="button secondary" href="{{ route('organizations.index') }}">Ver organizaciones</a>
    </div>
    <div class="identity-stack">
        <div class="identity-grid">
            <section class="panel identity-panel" aria-labelledby="profile-heading">
                <h2 id="profile-heading">Información personal</h2>
                <p class="muted">Tu cuenta es la misma en todas tus organizaciones.</p>
                <form class="identity-form" method="POST" action="{{ route('profile.update') }}">
                    @csrf
                    @method('PUT')
                    <x-field name="name" label="Nombre completo" :value="$user->name" required maxlength="150" autocomplete="name" />
                    <dl class="details"><div><dt>Correo electrónico</dt><dd>{{ $user->email }}</dd></div><div><dt>Verificación</dt><dd>{{ $user->email_verified_at ? 'Correo verificado' : 'Verificación pendiente' }}</dd></div></dl>
                    <div class="form-actions"><button type="submit" class="button">Guardar nombre</button></div>
                </form>
            </section>
            <section class="panel identity-panel" aria-labelledby="password-heading">
                <h2 id="password-heading">Cambiar contraseña</h2>
                <p class="muted">Al guardar, se cerrarán tus sesiones y tendrás que iniciar sesión nuevamente.</p>
                <form class="identity-form" method="POST" action="{{ route('password.change') }}">
                    @csrf
                    @method('PUT')
                    <div class="field"><label for="current_password">Contraseña actual</label><input id="current_password" name="current_password" type="password" required maxlength="128" autocomplete="current-password"></div>
                    <div class="field"><label for="password">Nueva contraseña</label><input id="password" name="password" type="password" required minlength="12" maxlength="128" autocomplete="new-password" aria-describedby="profile-password-help"></div>
                    <p class="identity-input-help" id="profile-password-help">Al menos 12 caracteres, con mayúsculas, minúsculas, números y símbolos.</p>
                    <div class="field"><label for="password_confirmation">Confirmar nueva contraseña</label><input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" maxlength="128" autocomplete="new-password"></div>
                    <div class="form-actions"><button type="submit" class="button">Cambiar contraseña</button></div>
                </form>
            </section>
        </div>

        <div class="identity-grid">
            <section class="panel identity-panel" aria-labelledby="memberships-heading">
                <h2 id="memberships-heading">Tus membresías</h2>
                <p class="muted">Cada organización define tu rol y el estado de tu membresía.</p>
                <div class="identity-list">
                    @forelse ($memberships as $membership)
                        <div class="identity-list-item">
                            <div><strong>{{ $membership->organization?->name ?? 'Organización no disponible' }}</strong><p>{{ $membership->roleAssignment?->role?->name ?? 'Sin rol asignado' }}</p></div>
                            <span class="pill {{ $membership->active ? '' : 'identity-neutral' }}">{{ $membership->active ? 'Membresía activa' : 'Membresía inactiva' }}</span>
                        </div>
                    @empty
                        <p class="muted">No tienes membresías registradas.</p>
                    @endforelse
                </div>
            </section>
            <section class="panel identity-panel" aria-labelledby="scopes-heading">
                <h2 id="scopes-heading">Ámbitos administrativos</h2>
                <p class="muted">Las asignaciones nacionales y departamentales se muestran por separado.</p>
                <div class="identity-list">
                    @forelse ($nationalAssignments as $assignment)
                        <div class="identity-list-item"><div><strong>{{ $assignment->role->name }}</strong><p>Ámbito nacional</p></div><span class="pill">Nacional</span></div>
                    @empty
                        <div class="identity-list-item"><p>No tienes asignaciones nacionales.</p></div>
                    @endforelse
                    @forelse ($departmentAssignments as $assignment)
                        <div class="identity-list-item"><div><strong>{{ $assignment->department->name }}</strong><p>{{ $assignment->role->name }} · Consulta departamental</p></div><span class="pill">Departamento</span></div>
                    @empty
                        <div class="identity-list-item"><p>No tienes asignaciones departamentales.</p></div>
                    @endforelse
                </div>
            </section>
        </div>

        <section class="panel identity-panel" aria-labelledby="sessions-heading">
            <div class="identity-section-heading"><div><h2 id="sessions-heading">Tus sesiones</h2><p class="muted">Cierra las sesiones que ya no necesites.</p></div></div>
            <div class="identity-table-wrap" tabindex="0" role="region" aria-label="Sesiones de la cuenta">
                <table class="identity-table">
                    <thead><tr><th scope="col">Sesión</th><th scope="col">Inicio</th><th scope="col">Última actividad</th><th scope="col">Vencimiento</th><th scope="col">Acción</th></tr></thead>
                    <tbody>
                        @forelse ($sessions as $session)
                            <tr>
                                <td>@if ($session->id === $currentSessionId)<span class="pill">Esta sesión</span>@else<span>Otra sesión</span>@endif</td>
                                <td class="identity-session-date">{{ $session->created_at?->format('d/m/Y H:i') ?? 'No registrado' }}</td>
                                <td class="identity-session-date">{{ $session->last_used_at?->format('d/m/Y H:i') ?? 'Sin actividad registrada' }}</td>
                                <td class="identity-session-date">{{ $session->expires_at?->format('d/m/Y H:i') ?? 'No registrado' }}</td>
                                <td><form method="POST" action="{{ route('sessions.destroy', $session->id) }}">@csrf @method('DELETE')<button type="submit" class="button secondary identity-danger">{{ $session->id === $currentSessionId ? 'Cerrar esta sesión' : 'Cerrar sesión' }}</button></form></td>
                            </tr>
                        @empty
                            <tr><td colspan="5">No hay sesiones para mostrar.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
