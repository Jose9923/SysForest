@if ($errors->any())
    <div class="error-box" role="alert">
        <strong>Revisa los datos del formulario.</strong>
        <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif
