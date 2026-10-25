@extends('layouts.institutional')
@section('title', 'Carbono almacenado')
@include('forestry.assets')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/carbon.css') }}">
@endpush
@section('content')
<div class="carbon-page">
    <header class="carbon-heading">
        <div><p class="eyebrow">{{ $organization->name }}</p><h1>Carbono almacenado</h1><p>Del inventario forestal a una estimación del carbono aéreo.</p></div>
        <div class="carbon-heading-actions">
            <a class="button" href="{{ route('reports.index', ['report' => 'carbon', 'scope' => 'organization']) }}">Ver reporte de stock <span aria-hidden="true">↗</span></a>
            <a class="button secondary" href="{{ route('reports.index', ['report' => 'carbon_coverage', 'scope' => 'organization']) }}">Cobertura y pendientes</a>
        </div>
    </header>

    <section class="carbon-method panel" aria-labelledby="carbon-method-title">
        <div class="carbon-section-heading"><div><span class="section-kicker">EL RECORRIDO DEL CÁLCULO</span><h2 id="carbon-method-title">De la medida al carbono</h2><p>El sistema realiza estos pasos al guardar una inspección con datos completos y una referencia validada.</p></div><span class="carbon-badge">Estimación de stock aéreo</span></div>
        <div class="carbon-equations">
            <article class="carbon-equation">
                <div class="carbon-step"><span>01</span><h3>Diámetro</h3></div>
                <div class="carbon-formula" role="math" aria-label="DAP en centímetros igual a CAP en centímetros dividido entre pi"><span aria-hidden="true"><i>D</i> = <span class="carbon-fraction"><span>CAP</span><span>π</span></span></span></div>
                <p>Transformamos la circunferencia del tronco en su diámetro.</p><span class="carbon-unit">Resultado en cm</span>
            </article>
            <article class="carbon-equation">
                <div class="carbon-step"><span>02</span><h3>Biomasa aérea</h3></div>
                <div class="carbon-formula carbon-formula-biomass" role="math" aria-label="Biomasa aérea igual a 0,0673 por el producto de densidad, diámetro al cuadrado y altura, elevado a 0,976"><span aria-hidden="true"><i>B</i> = 0,0673 × <span class="carbon-power">(ρ × <i>D</i><sup>2</sup> × <i>H</i>)<sup>0,976</sup></span></span></div>
                <p>Estimamos la masa seca de la parte aérea del árbol.</p><span class="carbon-unit">Resultado en kg de biomasa</span>
            </article>
            <article class="carbon-equation">
                <div class="carbon-step"><span>03</span><h3>Carbono almacenado</h3></div>
                <div class="carbon-formula" role="math" aria-label="Carbono igual a biomasa aérea por 0,47"><span aria-hidden="true"><i>C</i> = <i>B</i> × 0,47</span></div>
                <p>Aplicamos la fracción de carbono a la biomasa seca.</p><span class="carbon-unit">Resultado en kg C</span>
            </article>
            <article class="carbon-equation carbon-equation-final">
                <div class="carbon-step"><span>04</span><h3>CO₂ correspondiente</h3></div>
                <div class="carbon-formula" role="math" aria-label="CO2 correspondiente al stock igual a carbono por 44 dividido entre 12"><span aria-hidden="true">CO<sub>2</sub> = <i>C</i> × <span class="carbon-fraction"><span>44</span><span>12</span></span></span></div>
                <p>Expresamos el carbono almacenado como masa de CO₂.</p><span class="carbon-unit">Resultado en kg CO₂</span>
            </article>
        </div>
        <dl class="carbon-symbols"><div><dt>ρ</dt><dd>Densidad básica <span>g/cm³</span></dd></div><div><dt>D</dt><dd>DAP <span>cm</span></dd></div><div><dt>H</dt><dd>Altura total <span>m</span></dd></div><div><dt>B / C</dt><dd>Biomasa / carbono <span>kg</span></dd></div></dl>
        <div class="carbon-method-footer"><p>Describe carbono aéreo almacenado. No incluye raíces ni suelo, ni representa captura anual, huella de emisiones o créditos de carbono.</p><div><a href="https://doi.org/10.1111/gcb.12629" rel="noopener noreferrer" target="_blank">Chave et al., 2014 ↗</a><a href="https://www.ipcc-nggip.iges.or.jp/public/2006gl/pdf/4_Volume4/V4_04_Ch4_Forest_Land.pdf#page=48" rel="noopener noreferrer" target="_blank">IPCC 2006 · tabla 4.3 ↗</a></div></div>
    </section>

    <p class="notice">Las referencias <strong>bibliográficas provisionales</strong> permiten estimaciones iniciales con fuentes publicadas. No acreditan revisión profesional local. Sus límites proceden del conjunto global de Chave: DAP de 5 a 212 cm y altura de 1,2 a 70,7 m; no son máximos biológicos de cada especie.</p>
    <div class="carbon-workspace">
        @if ($canManage)
        <form method="POST" action="{{ route('carbon.store') }}" class="panel carbon-form forestry-panel" aria-labelledby="carbon-form-title">
            @csrf
            <input type="hidden" name="settings_version" value="{{ old('settings_version', $settingsVersion) }}">
            <div class="carbon-section-heading"><div><span class="section-kicker">CONFIGURACIÓN INSTITUCIONAL</span><h2 id="carbon-form-title">Referencia de una especie</h2><p>Documenta los parámetros que utilizará esta entidad en sus próximas inspecciones.</p></div></div>
            <fieldset class="carbon-fieldset"><legend><span>1</span> Especie y aplicabilidad</legend>
                <div class="carbon-fields carbon-fields-two">
                    <div class="field"><label for="carbon-species">Especie <span class="carbon-required">(obligatoria)</span></label><select class="catalog-select" id="carbon-species" name="species_id" required aria-describedby="carbon-species-hint"><option value="">Selecciona una especie</option>@foreach ($species->where('active', true) as $item)<option value="{{ $item->id }}" @selected(old('species_id') === $item->id)>{{ $item->scientific_name }}</option>@endforeach</select><p class="field-hint" id="carbon-species-hint">Elige su nombre científico en el catálogo.</p>@error('species_id')<p class="field-error">{{ $message }}</p>@enderror</div>
                    <div class="field"><label for="carbon-applicable">Aplicabilidad de Chave 2014</label><select id="carbon-applicable" name="applicable" aria-describedby="carbon-applicable-hint"><option value="0" @selected((string) old('applicable', '0') === '0')>No aplicable / retirar del cálculo</option><option value="1" @selected((string) old('applicable') === '1')>Aplicable en este contexto</option></select><p class="field-hint" id="carbon-applicable-hint">Requiere validación para árboles tropicales leñosos de un fuste.</p>@error('applicable')<p class="field-error">{{ $message }}</p>@enderror</div>
                </div>
            </fieldset>
            <fieldset class="carbon-fieldset"><legend><span>2</span> Parámetros y límites</legend><p class="carbon-fieldset-hint">Completa los tres valores cuando el método sea aplicable. Los límites deben estar respaldados por la revisión profesional.</p>
                <div class="carbon-fields carbon-fields-three">
                    <div class="field"><label for="carbon-density">Densidad básica</label><div class="carbon-input-unit"><input id="carbon-density" name="wood_density" type="number" min="0.000001" max="2" step="0.000001" value="{{ old('wood_density') }}" aria-describedby="carbon-density-unit carbon-density-hint"><span id="carbon-density-unit">g/cm³</span></div><p class="field-hint" id="carbon-density-hint">Masa seca / volumen verde.</p>@error('wood_density')<p class="field-error">{{ $message }}</p>@enderror</div>
                    <div class="field"><label for="carbon-diameter">DAP máximo validado</label><div class="carbon-input-unit"><input id="carbon-diameter" name="max_diameter_cm" type="number" min="5" max="10000" step="0.001" value="{{ old('max_diameter_cm') }}" aria-describedby="carbon-diameter-unit carbon-diameter-hint"><span id="carbon-diameter-unit">cm</span></div><p class="field-hint" id="carbon-diameter-hint">El mínimo del método es 5 cm.</p>@error('max_diameter_cm')<p class="field-error">{{ $message }}</p>@enderror</div>
                    <div class="field"><label for="carbon-height">Altura máxima validada</label><div class="carbon-input-unit"><input id="carbon-height" name="max_height_m" type="number" min="0.001" max="1000" step="0.001" value="{{ old('max_height_m') }}" aria-describedby="carbon-height-unit carbon-height-hint"><span id="carbon-height-unit">m</span></div><p class="field-hint" id="carbon-height-hint">Utiliza altura total.</p>@error('max_height_m')<p class="field-error">{{ $message }}</p>@enderror</div>
                </div>
            </fieldset>
            <fieldset class="carbon-fieldset"><legend><span>3</span> Respaldo profesional</legend>
                <div class="carbon-fields">
                    <div class="field"><label for="carbon-reference">Fuente de la densidad <span class="carbon-required">(obligatoria)</span></label><textarea id="carbon-reference" name="reference" required maxlength="3000" rows="3" aria-describedby="carbon-reference-hint" placeholder="Autor, año, tabla o registro y enlace de consulta">{{ old('reference') }}</textarea><p class="field-hint" id="carbon-reference-hint">La referencia debe corresponder a densidad básica de la especie.</p>@error('reference')<p class="field-error">{{ $message }}</p>@enderror</div>
                    <div class="field"><label for="carbon-justification">Justificación de aplicabilidad <span class="carbon-required">(obligatoria)</span></label><textarea id="carbon-justification" name="justification" required maxlength="5000" rows="4" aria-describedby="carbon-justification-hint" placeholder="Describe el contexto local, por qué aplica el método y el respaldo de los límites elegidos">{{ old('justification') }}</textarea><p class="field-hint" id="carbon-justification-hint">Documenta también el motivo si retiras la especie del cálculo.</p>@error('justification')<p class="field-error">{{ $message }}</p>@enderror</div>
                </div>
            </fieldset>
            <div class="carbon-validation"><label for="carbon-validated"><input id="carbon-validated" type="checkbox" name="validated" value="1" required @checked(old('validated'))><span><strong>Confirmo la revisión profesional</strong><span>Revisé la densidad básica, la identificación de la especie y la aplicabilidad local del método y sus límites.</span></span></label>@error('validated')<p class="field-error">{{ $message }}</p>@enderror</div>
            <div class="carbon-form-footer"><p>Se publicará una nueva referencia solo para <strong>{{ $organization->short_name ?: $organization->name }}</strong>. Las versiones anteriores se conservan.</p><button class="button" type="submit">Publicar referencia <span aria-hidden="true">→</span></button></div>
        </form>
        @endif
        <aside class="carbon-guidance" aria-label="Orientación para las referencias de carbono">
            <section class="panel carbon-guide"><span class="section-kicker">ANTES DE PUBLICAR</span><h2>Una referencia con respaldo</h2><ul><li><strong>Identificación correcta</strong><span>Especie y hábito Árbol, un solo fuste y medidas completas.</span></li><li><strong>Densidad adecuada</strong><span>Usa masa seca / volumen verde. No densidad aparente a otra humedad.</span></li><li><strong>Aplicación local</strong><span>Revisa la pertinencia del modelo tropical para el contexto y el arbolado urbano.</span></li></ul><p class="carbon-guide-note">Palmas, arbustos y árboles con varios fustes requieren métodos específicos. Los datos faltantes permanecen pendientes y no cuentan como cero.</p></section>
            @if ($canManage)
            <form class="panel carbon-refresh" method="POST" action="{{ route('carbon.refresh') }}">@csrf<span class="section-kicker">VISITAS EXISTENTES</span><h2>Aplicar las referencias actuales</h2><p>Las nuevas visitas usan la última referencia de su especie. Para aplicarla a las visitas anteriores, actualiza sus estimaciones.</p><p class="carbon-refresh-note">Se conserva la historia de resultados, mediciones y fechas. Repetir sin cambios no duplica registros.</p><button class="button secondary" type="submit">Actualizar estimaciones</button></form>
            @endif
        </aside>
    </div>

    <section class="panel carbon-history" aria-labelledby="carbon-history-title"><div class="carbon-section-heading"><div><span class="section-kicker">TRAZABILIDAD</span><h2 id="carbon-history-title">Referencias conservadas</h2><p>La referencia más reciente de cada especie es la vigente. Cada entidad conserva sus propios parámetros.</p></div></div>
        <div class="carbon-table-wrap" tabindex="0" role="region" aria-label="Historial de referencias de carbono"><table><thead><tr><th>Referencia</th><th>Especie</th><th>Aplicabilidad</th><th>Densidad básica</th><th>Límites de aplicación</th><th>Respaldo</th></tr></thead><tbody>
        @forelse ($profiles as $profile)
            <tr><td><strong>Versión {{ $profile->id }}</strong><small>{{ $profile->created_at->format('d/m/Y H:i') }} UTC</small></td><td><em>{{ $species->firstWhere('id', $profile->species_id)?->scientific_name ?? 'Referencia histórica' }}</em></td><td><span class="carbon-badge {{ $profile->applicable ? '' : 'carbon-badge-neutral' }}">{{ $profile->applicable ? 'Aplicable' : 'No aplicable' }}</span><small>{{ \App\Support\CarbonPresentation::referenceLabel($profile->validation_status) }}</small></td><td>{{ $profile->wood_density ?? '—' }}<small>g/cm³</small></td><td>DAP: {{ $profile->max_diameter_cm ?? '—' }} cm<small>Altura: {{ $profile->max_height_m ?? '—' }} m</small></td><td><details><summary>Ver fuente y justificación</summary><p>{{ $profile->reference }}</p><p>{{ $profile->justification }}</p></details></td></tr>
        @empty
            <tr><td colspan="6"><div class="carbon-empty"><span aria-hidden="true">ρ</span><strong>Aún no hay referencias publicadas</strong><p>Al publicar la primera referencia de una especie, sus próximas inspecciones podrán utilizarla. No se asignan densidades supuestas.</p></div></td></tr>
        @endforelse
        </tbody></table></div>
    </section>
</div>
@endsection
