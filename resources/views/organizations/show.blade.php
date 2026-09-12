@extends('layouts.institutional')
@section('title', $organization->short_name ?? $organization->name)
@section('content')
    <a class="back-link" href="{{ route('organizations.index') }}">← Tus organizaciones</a>
    <div class="page-heading"><div><p class="eyebrow">ORGANIZACIÓN ACTIVA</p><h1>{{ $organization->name }}</h1><p class="muted">{{ $organization->municipality->name }}, {{ $organization->municipality->department->name }}, {{ $organization->municipality->department->country->name }}.</p></div>
        @can('update', $organization)<a class="button" href="{{ route('organizations.edit', $organization->id) }}">Editar organización</a>@endcan
    </div>
    <div class="detail-grid">
        <section class="panel"><h2>Información institucional</h2><dl class="details">
            <div><dt>Tipo</dt><dd>{{ $organization->type->label() }}</dd></div>
            <div><dt>Código interno</dt><dd>{{ $organization->code }}</dd></div>
            <div><dt>Nombre corto</dt><dd>{{ $organization->short_name ?? 'Sin registrar' }}</dd></div>
            <div><dt>Prefijo para códigos de árboles</dt><dd>{{ $organization->tree_code_prefix }}</dd></div>
            <div><dt>Correo</dt><dd>{{ $organization->email ?? 'Sin registrar' }}</dd></div>
            <div><dt>Teléfono</dt><dd>{{ $organization->phone ?? 'Sin registrar' }}</dd></div>
            <div><dt>Estado</dt><dd><span class="pill">Activa</span></dd></div>
        </dl></section>
        <section class="panel territory-panel"><p class="eyebrow">UBICACIÓN</p><h2>Una institución, un territorio</h2>
            <ol class="territory-chain">
                <li><span>País</span><strong>{{ $organization->municipality->department->country->name }}</strong></li>
                <li><span>Departamento</span><strong>{{ $organization->municipality->department->name }}</strong></li>
                <li><span>Municipio</span><strong>{{ $organization->municipality->name }}</strong></li>
            </ol>
            <a class="text-link" href="{{ route('territories.index') }}">Consultar territorios →</a>
        </section>
    </div>
@endsection
