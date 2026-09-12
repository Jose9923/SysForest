@push('styles')
    <link rel="stylesheet" href="{{ asset('vendor/select2/select2.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/municipality-select.css') }}">
@endpush
@push('scripts')
    <script src="{{ asset('vendor/jquery/jquery.min.js') }}" defer></script>
    <script src="{{ asset('vendor/select2/select2.min.js') }}" defer></script>
    <script src="{{ asset('vendor/select2/es.js') }}" defer></script>
    <script src="{{ asset('js/municipality-select.js') }}" defer></script>
@endpush
<h2>Identidad institucional</h2>
<div class="form-grid">
    <div class="wide"><x-field name="name" label="Nombre de la organización *" :value="$organization->name ?? ''" required maxlength="200" autocomplete="organization" /></div>
    <x-field name="short_name" label="Nombre corto" :value="$organization->short_name ?? ''" maxlength="80" />
    <div class="field"><label for="type">Tipo de organización *</label><select id="type" name="type" required>
        <option value="">Selecciona un tipo</option>
        @foreach ($types as $type)<option value="{{ $type->value }}" @selected(old('type', isset($organization) ? $organization->type->value : '') === $type->value)>{{ $type->label() }}</option>@endforeach
    </select>@error('type')<span class="field-error">{{ $message }}</span>@enderror</div>
    <x-field name="code" label="Código interno *" :value="$organization->code ?? ''" required maxlength="40" placeholder="INEM-VVC" />
    <x-field name="tree_code_prefix" label="Prefijo de árboles *" :value="$organization->tree_code_prefix ?? ''" required maxlength="60" placeholder="COL-MET-VVC-INEM" />
</div>
<p class="help">Los códigos utilizan letras mayúsculas, números y guiones. El prefijo identifica a la institución; aquí no se crean árboles.</p>
<hr><h2>Vinculación territorial</h2>
<div class="field"><label for="municipality_id">Municipio *</label><select class="municipality-select" id="municipality_id" name="municipality_id" required>
    <option value="">Selecciona un municipio</option>
    @foreach ($municipalities as $municipality)<option value="{{ $municipality->id }}" @selected(old('municipality_id', $organization->municipality_id ?? '') === $municipality->id)>{{ $municipality->name }} · {{ $municipality->department->name }} · {{ $municipality->department->country->name }}</option>@endforeach
</select>@error('municipality_id')<span class="field-error">{{ $message }}</span>@enderror</div>
<p class="help">Todas las organizaciones, incluidas las entidades nacionales y departamentales, deben tener un municipio.</p>
<hr><h2>Contacto y estado</h2>
<div class="form-grid">
    <x-field name="email" label="Correo institucional" type="email" :value="$organization->email ?? ''" maxlength="255" autocomplete="email" />
    <x-field name="phone" label="Teléfono" type="tel" :value="$organization->phone ?? ''" maxlength="40" autocomplete="tel" />
    <div class="field"><label for="active">Estado *</label><select id="active" name="active" required>
        <option value="1" @selected((string) old('active', isset($organization) ? (int) $organization->active : 1) === '1')>Activa</option>
        <option value="0" @selected((string) old('active', isset($organization) ? (int) $organization->active : 1) === '0')>Inactiva</option>
    </select><p class="help">Una organización inactiva deja de estar disponible como contexto de trabajo.</p></div>
</div>
