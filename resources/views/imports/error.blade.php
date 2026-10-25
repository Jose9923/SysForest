@extends('layouts.institutional')
@section('title', 'Importación no disponible')
@section('content')
    <section class="card">
        <h1>Revisa la importación</h1>
        <p>{{ \App\Support\ExcelReferencePresentation::riskMessage(\App\Support\ExcelReferencePresentation::text($message)) }}</p>
        @if ($errors !== [])
            <ul>
                @foreach ($errors as $messages)
                    @foreach ($messages as $error)
                        <li>{{ \App\Support\ExcelReferencePresentation::riskMessage(\App\Support\ExcelReferencePresentation::text($error)) }}</li>
                    @endforeach
                @endforeach
            </ul>
        @endif
        <a class="button" href="{{ route('imports.index') }}">Volver a importaciones</a>
    </section>
@endsection
