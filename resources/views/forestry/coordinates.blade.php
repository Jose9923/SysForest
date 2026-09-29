@php
    $coordinateRecord = $coordinateRecord ?? null;
    $coordinatePrefix = $coordinatePrefix ?? 'tree';
@endphp
<div class="form-grid">
    <div class="field wide"><label for="{{ $coordinatePrefix }}-coordinate-raw">Coordenadas originales</label>
        <textarea id="{{ $coordinatePrefix }}-coordinate-raw" name="coordinate_raw" rows="2" maxlength="500" placeholder="Ej.: 4.609710, -74.081750" aria-describedby="{{ $coordinatePrefix }}-coordinate-help">{{ old('coordinate_raw', $coordinateRecord?->coordinate_raw) }}</textarea>
        <p class="field-hint" id="{{ $coordinatePrefix }}-coordinate-help">Pega la lectura completa del GPS. Se admiten grados decimales o grados, minutos y segundos. Orden: latitud, longitud.</p>
        @error('coordinate_raw')<span class="field-error">{{ $message }}</span>@enderror
    </div>
    <div class="field"><label for="{{ $coordinatePrefix }}-datum">Datum informado por el equipo</label>
        <select name="datum" id="{{ $coordinatePrefix }}-datum"><option value="">No informado</option><option value="WGS84" @selected(old('datum', $coordinateRecord?->datum) === 'WGS84')>WGS84</option></select>
        @error('datum')<span class="field-error">{{ $message }}</span>@enderror
    </div>
    <div class="field"><label for="{{ $coordinatePrefix }}-accuracy">Precisión reportada (m)</label><input id="{{ $coordinatePrefix }}-accuracy" name="accuracy_m" type="number" min="0" step="any" value="{{ old('accuracy_m', $coordinateRecord?->accuracy_m) }}" placeholder="Opcional">
        @error('accuracy_m')<span class="field-error">{{ $message }}</span>@enderror
    </div>
    <div class="field"><label for="{{ $coordinatePrefix }}-brand">Marca del GPS</label><input id="{{ $coordinatePrefix }}-brand" name="gps_brand" maxlength="100" value="{{ old('gps_brand', $coordinateRecord?->gps_brand) }}" placeholder="Ej.: Garmin (opcional)">
        @error('gps_brand')<span class="field-error">{{ $message }}</span>@enderror
    </div>
    <div class="field"><label for="{{ $coordinatePrefix }}-model">Modelo del GPS</label><input id="{{ $coordinatePrefix }}-model" name="gps_model" maxlength="100" value="{{ old('gps_model', $coordinateRecord?->gps_model) }}" placeholder="No informado">
        @error('gps_model')<span class="field-error">{{ $message }}</span>@enderror
    </div>
    <div class="field wide"><label for="{{ $coordinatePrefix }}-captured">Fecha y hora de captura (hora de Colombia)</label><input id="{{ $coordinatePrefix }}-captured" name="captured_at_local" type="datetime-local" step="0.001" value="{{ old('captured_at_local', $captureDate['input']) }}" aria-describedby="{{ $coordinatePrefix }}-time-help {{ $coordinatePrefix }}-time-error" @if ($errors->has('captured_at_local')) aria-invalid="true" @endif><p id="{{ $coordinatePrefix }}-time-help" class="field-hint">Selecciona el día y la hora local en el calendario. Zona: America/Bogota. Déjala vacía si desconoces cuándo se capturó la ubicación.</p>
        @error('captured_at_local')<span class="field-error" id="{{ $coordinatePrefix }}-time-error">{{ $message }}</span>@enderror
        @error('captured_at')<span class="field-error">{{ $message }}</span>@enderror
    </div>
</div>
<p class="forestry-note">El mapa trabaja en WGS84. Deja el datum, la precisión y los datos del GPS sin informar si no los conoces.</p>
