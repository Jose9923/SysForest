<div class="panel" data-risk-rule>
@php($prefix = $ruleKind.'['.$index.']')
<input type="hidden" name="{{ $prefix }}[enabled]" value="0"><label><input type="checkbox" name="{{ $prefix }}[enabled]" value="1" @checked($rule['enabled'] ?? true)> Regla vigente en la nueva versión</label>
@if ($ruleKind === 'conflicts')
<label>Cuando se marque<select name="{{ $prefix }}[left]"><option value="">Selecciona una condición</option>@foreach ($conditions as $condition)<option value="{{ $condition->code }}" @selected(($rule['left'] ?? '') === $condition->code)>{{ $condition->category->name }} / {{ $condition->name }}</option>@endforeach</select></label>
<label>No permitir ninguna de estas condiciones<select multiple name="{{ $prefix }}[right][]">@foreach ($conditions as $condition)<option value="{{ $condition->code }}" @selected(in_array($condition->code, $rule['right'] ?? [], true))>{{ $condition->category->name }} / {{ $condition->name }}</option>@endforeach</select></label>
@else
<label>Tipo de revisión<select name="{{ $prefix }}[kind]"><option value="healthy_severe" @selected(($rule['kind'] ?? '') === 'healthy_severe')>Condición marcada junto con afectación local severa o crítica</option><option value="insect_unspecified" @selected(($rule['kind'] ?? '') === 'insect_unspecified')>Condición marcada sin ninguna de las aclaraciones seleccionadas</option></select></label>
<label>Condición que activa la revisión<select name="{{ $prefix }}[condition]"><option value="">Selecciona una condición</option>@foreach ($conditions as $condition)<option value="{{ $condition->code }}" @selected(($rule['condition'] ?? '') === $condition->code)>{{ $condition->category->name }} / {{ $condition->name }}</option>@endforeach</select></label>
<label>Condiciones que aclaran la observación (segundo tipo de revisión)<select multiple name="{{ $prefix }}[unless][]">@foreach ($conditions as $condition)<option value="{{ $condition->code }}" @selected(in_array($condition->code, $rule['unless'] ?? [], true))>{{ $condition->category->name }} / {{ $condition->name }}</option>@endforeach</select></label>
<label>Mensaje al profesional<input name="{{ $prefix }}[message]" maxlength="300" value="{{ $rule['message'] ?? '' }}"></label>
@endif
</div>
