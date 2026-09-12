@extends('layouts.institutional')
@section('title', 'Usuarios globales')
@push('styles')<link rel="stylesheet" href="{{ asset('css/auth.css') }}">@endpush
@section('content')
    <div class="page-heading"><div><p class="eyebrow">ADMINISTRACIÓN NACIONAL</p><h1>Usuarios globales</h1><p class="muted">Consulta las cuentas de la plataforma y sus estados.</p></div></div>
    <section class="panel identity-panel" aria-label="Cuentas de usuario">
        <div class="identity-table-wrap">
            <table class="identity-table"><thead><tr><th scope="col">Usuario</th><th scope="col">Cuenta global</th><th scope="col">Correo</th><th scope="col">Acción</th></tr></thead><tbody>
                @forelse ($users as $user)
                    <tr>
                        <td><strong>{{ $user->name }}</strong><small>{{ $user->email }}</small></td>
                        <td><span class="pill {{ $user->active ? '' : 'identity-warning' }}">{{ $user->active ? 'Activa' : 'Desactivada' }}</span></td>
                        <td>{{ $user->email_verified_at ? 'Verificado' : 'Verificación pendiente' }}</td>
                        <td><a class="button secondary" href="{{ route('users.show', $user->id) }}">Ver cuenta</a></td>
                    </tr>
                @empty
                    <tr><td colspan="4">No hay cuentas para mostrar.</td></tr>
                @endforelse
            </tbody></table>
        </div>
    </section>
    @include('partials.pagination', ['paginator' => $users])
@endsection
