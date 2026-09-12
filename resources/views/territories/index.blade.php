@extends('layouts.institutional')
@section('title', 'Territorios')
@section('content')
    <div class="page-heading"><div><p class="eyebrow">BASE TERRITORIAL</p><h1>Del país al municipio</h1><p class="muted">La jerarquía que vincula cada organización con su territorio.</p></div></div>
    @forelse ($countries as $country)
        <section class="panel territory-catalog"><div class="section-heading"><div><span class="pill">{{ $country->iso_alpha_3 }}</span><h2>{{ $country->name }}</h2></div><span class="muted">Código {{ $country->code }}</span></div>
            @foreach ($country->departments as $department)
                <div class="department-row"><div><p class="eyebrow">DEPARTAMENTO</p><h3>{{ $department->name }}</h3><span class="muted">Código {{ $department->code }}</span></div>
                    <div class="municipalities">@foreach ($department->municipalities as $municipality)<div class="municipality-row"><strong>{{ $municipality->name }}</strong><span>Código {{ $municipality->code }}</span><span class="pill">{{ $municipality->active ? 'Activo' : 'Inactivo' }}</span></div>@endforeach</div>
                </div>
            @endforeach
        </section>
    @empty
        <div class="panel empty"><h2>No hay territorios registrados</h2></div>
    @endforelse
@endsection
