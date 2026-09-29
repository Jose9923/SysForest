@extends('layouts.institutional')
@section('title', 'Usuarios de la organización')
@push('styles')<link rel="stylesheet" href="{{ asset('css/auth.css') }}">@endpush
@section('content')
    <div class="page-heading">
        <div><p class="eyebrow">EQUIPO DE LA ORGANIZACIÓN</p><h1>Usuarios y membresías</h1><p class="muted">{{ $organization->name }}</p></div>
        @can('users.invite')<a class="button" href="{{ route('invitations.create') }}">Invitar usuario</a>@endcan
    </div>
    <div class="identity-callout identity-no-top-margin">El estado de una membresía afecta solo el acceso a esta organización. El estado de la cuenta global se muestra como información.</div>
    <section class="identity-member-list" aria-label="Membresías de la organización">
        @forelse ($members as $member)
            <article class="identity-member">
                <div>
                    <h2>{{ $member->user->name }}</h2>
                    <p class="identity-member-email">{{ $member->user->email }}</p>
                    <div class="identity-badges">
                        <span class="pill {{ $member->active ? '' : 'identity-neutral' }}">{{ $member->active ? 'Membresía activa' : 'Membresía inactiva' }}</span>
                        <span class="pill {{ $member->user->active ? 'identity-neutral' : 'identity-warning' }}">{{ $member->user->active ? 'Cuenta global activa' : 'Cuenta global desactivada' }}</span>
                    </div>
                </div>
                @can('memberships.update')
                    <form class="identity-member-form" method="POST" action="{{ route('memberships.update', $member->id) }}">
                        @csrf
                        @method('PATCH')
                        <div class="field">
                            <label for="membership-active-{{ $member->id }}">Estado de la membresía</label>
                            <select id="membership-active-{{ $member->id }}" name="active" required><option value="1" @selected($member->active)>Activa</option><option value="0" @selected(!$member->active)>Inactiva</option></select>
                        </div>
                        @can('roles.assign-organization')
                            <div class="field">
                                <label for="membership-role-{{ $member->id }}">Rol en la organización</label>
                                <select id="membership-role-{{ $member->id }}" name="role" required>
                                    <option value="" disabled @selected(!$member->roleAssignment)>Selecciona un rol</option>
                                    @foreach ($roles as $role)<option value="{{ $role->name }}" @selected($member->roleAssignment?->role?->name === $role->name)>{{ $role->name }}</option>@endforeach
                                </select>
                            </div>
                        @else
                            <p class="identity-member-role">{{ $member->roleAssignment?->role?->name ?? 'Sin rol asignado' }}</p>
                        @endcan
                        <button type="submit" class="button secondary">Guardar</button>
                    </form>
                @else
                    <p class="identity-member-role">{{ $member->roleAssignment?->role?->name ?? 'Sin rol asignado' }}</p>
                @endcan
            </article>
        @empty
            <div class="panel empty"><h2>No hay miembros para mostrar</h2><p class="muted">Las personas invitadas aparecerán cuando completen su incorporación.</p></div>
        @endforelse
    </section>
    @include('partials.pagination', ['paginator' => $members])

    @if (isset($invitations))
        <section class="panel identity-panel" style="margin-top: 28px" aria-labelledby="invitations-heading">
            <div class="identity-section-heading"><div><h2 id="invitations-heading">Invitaciones enviadas</h2><p class="muted">Consulta el correo de destino y la fecha de vencimiento.</p></div></div>
            <div class="identity-table-wrap" tabindex="0" role="region" aria-label="Invitaciones enviadas">
                <table class="identity-table"><thead><tr><th scope="col">Correo</th><th scope="col">Estado</th><th scope="col">Vencimiento</th></tr></thead><tbody>
                    @forelse ($invitations as $invitation)
                        <tr><td>{{ $invitation->email }}</td><td>{{ $invitation->accepted_at ? 'Aceptada' : ($invitation->revoked_at ? 'Revocada' : 'Enviada') }}</td><td>{{ $invitation->expires_at?->format('d/m/Y H:i') ?? 'No registrado' }}</td></tr>
                    @empty
                        <tr><td colspan="3">No hay invitaciones registradas.</td></tr>
                    @endforelse
                </tbody></table>
            </div>
            @if ($invitations instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)@include('partials.pagination', ['paginator' => $invitations])@endif
        </section>
    @endif
@endsection
