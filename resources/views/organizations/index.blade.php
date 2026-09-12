@extends('layouts.institutional')
@section('title', 'Organizaciones')
@section('content')
    <div class="page-heading"><div><p class="eyebrow">TERRITORIO · COMUNIDAD · GESTIÓN</p><h1>Tus organizaciones</h1><p class="muted">Un espacio de trabajo para cada institución a la que perteneces.</p></div>
        @can('create', \App\Models\Organization::class)<a class="button" href="{{ route('organizations.create') }}">+ Crear organización</a>@endcan
    </div>
    <div class="summary-card"><div class="summary-icon" aria-hidden="true">⌂</div><div><strong>{{ $organizations->total() }} {{ $organizations->total() === 1 ? 'organización disponible' : 'organizaciones disponibles' }}</strong><p>Selecciona una organización para consultar sus datos y trabajar en su contexto.</p></div><span class="pill">Membresías activas</span></div>
    <section class="organization-grid" aria-label="Organizaciones disponibles">
        @forelse ($organizations as $organization)
            <article class="organization-card">
                <div class="card-top"><span class="org-monogram" aria-hidden="true">⌂</span><span class="pill">{{ $organization->type->label() }}</span></div>
                <p class="code">{{ $organization->code }}</p>
                <h2>{{ $organization->name }}</h2>
                <p class="location">{{ $organization->municipality->name }}, {{ $organization->municipality->department->name }}, {{ $organization->municipality->department->country->name }}</p>
                <div class="card-bottom"><span class="muted">Activa</span>
                    <form method="POST" action="{{ route('tenant.select') }}">@csrf<input type="hidden" name="organization_id" value="{{ $organization->id }}"><button class="button secondary">Abrir organización <span aria-hidden="true">→</span></button></form>
                </div>
            </article>
        @empty
            <div class="panel empty"><h2>No hay organizaciones disponibles</h2><p>Tu cuenta necesita una membresía activa en una organización vigente.</p></div>
        @endforelse
    </section>
    @include('partials.pagination', ['paginator' => $organizations])
@endsection
