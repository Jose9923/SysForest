@php
    $coordinateRecord = $coordinateRecord ?? null;
    $coordinatePrefix = $coordinatePrefix ?? 'tree';
    $capturedValue = $coordinateRecord?->captured_at?->format('Y-m-d\TH:i:s.uP') ?? '';
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
    <div class="field wide"><label for="{{ $coordinatePrefix }}-captured">Fecha y hora de captura (con zona horaria)</label><input id="{{ $coordinatePrefix }}-captured" name="captured_at" type="text" value="{{ old('captured_at', $capturedValue) }}" placeholder="2026-09-12T14:30:00-05:00" aria-describedby="{{ $coordinatePrefix }}-time-help"><p id="{{ $coordinatePrefix }}-time-help" class="field-hint">Formato ISO 8601: fecha, T, hora y zona. Colombia usa -05:00; +00:00 indica UTC. Conserva la lectura original si incluye segundos o fracciones.</p>
        @error('captured_at')<span class="field-error">{{ $message }}</span>@enderror
    </div>
</div>
<p class="forestry-note">El mapa trabaja en WGS84. Deja el datum, la precisión y los datos del GPS sin informar si no los conoces.</p>
