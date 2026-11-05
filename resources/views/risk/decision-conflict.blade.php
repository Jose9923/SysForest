@extends('layouts.institutional')
@section('title', 'La revisión de riesgo cambió')
@include('forestry.assets')
@push('styles')<link rel="stylesheet" href="{{ asset('css/risk.css') }}">@endpush
@section('content')
<section class="risk-decision forestry-page"><header class="page-heading"><p class="eyebrow">Gestión del riesgo</p><h1>La revisión cambió</h1></header>@include('risk.navigation')<section class="panel"><p role="alert">{{ $conflictMessage }}</p><p>Tu decisión no se guardó. Abre la última inspección disponible para revisar sus datos y confirmar nuevamente.</p><div class="form-actions"><a class="button" href="{{ route('risk.trees.decision', $treeId) }}">Actualizar revisión de riesgo</a><a class="button secondary" href="{{ route('risk.index') }}">Volver a Gestión del riesgo</a></div></section></section>
@endsection
