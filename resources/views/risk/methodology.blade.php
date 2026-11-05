@extends('layouts.institutional')
@section('title', 'Gestión del riesgo')
@include('forestry.assets')
@push('styles')<link rel="stylesheet" href="{{ asset('css/risk.css') }}">@endpush
@section('content')
<div class="risk-management">
<div class="page-heading"><div><p class="eyebrow">{{ $organization->name }}</p><h1>Gestión del riesgo</h1><p>Prioridad de Gestión Fitosanitaria · parametrización propia de esta institución.</p></div></div>
@include('risk.navigation')
<p class="notice">La metodología orienta el seguimiento; no estima probabilidad de caída. El profesional confirma la prioridad y registra su criterio en observaciones. Publicar una versión no cambia evaluaciones anteriores.</p>
@if ($errors->any())<div role="alert" class="field-error"><ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif

<form method="POST" action="{{ route('risk.store') }}">
@csrf
<input type="hidden" name="lock_version" value="{{ old('lock_version', $settingsVersion) }}">
<fieldset @disabled(!$canManage)>
<section class="panel"><h2>Metodología institucional</h2><label for="risk-name">Nombre</label><input id="risk-name" name="name" maxlength="150" required value="{{ old('name', $methodology?->name ?? 'Prioridad de Gestión Fitosanitaria') }}"><p>Versión vigente: {{ $methodology?->version ?? 'Inicial pendiente de publicación' }}. Guardar publica una nueva versión.</p></section>
<section class="panel"><h2>Condiciones y pesos</h2><p>Incorporar habilita la condición para esta metodología. Peso 0 conserva información sin aumentar la severidad. El mínimo fuerza una prioridad, aunque el peso sea 0. El componente general no cuenta en la extensión.</p>
<div class="table-wrap"><table><thead><tr><th>Condición del formulario</th><th>Incorporar</th><th>Peso (0–4)</th><th>Mínimo</th><th>Componente</th></tr></thead><tbody>
@foreach ($conditions as $condition)
@php($rule = $configuration['conditions'][$condition->code] ?? null)
<tr><td>{{ $condition->category->name }} / {{ $condition->name }}@if (!$condition->active || !$condition->category->active)<br><small>Inactiva</small>@endif</td><td><input type="hidden" name="conditions[{{ $condition->code }}][enabled]" value="0"><input aria-label="Incorporar {{ $condition->category->name }} {{ $condition->name }}" type="checkbox" name="conditions[{{ $condition->code }}][enabled]" value="1" @checked(old('conditions.'.$condition->code.'.enabled', $rule !== null))></td>
<td><input aria-label="Peso de {{ $condition->name }}" type="number" min="0" max="4" required name="conditions[{{ $condition->code }}][weight]" value="{{ old('conditions.'.$condition->code.'.weight', $rule['weight'] ?? 0) }}"></td>
<td><select aria-label="Prioridad mínima de {{ $condition->name }}" name="conditions[{{ $condition->code }}][minimum]">@foreach ([0 => 'Sin mínimo', 1 => 'Bajo', 2 => 'Moderado', 3 => 'Alto', 4 => 'Crítico'] as $value => $label)<option value="{{ $value }}" @selected((int) old('conditions.'.$condition->code.'.minimum', $rule['minimum'] ?? 0) === $value)>{{ $label }}</option>@endforeach</select></td>
<td><select aria-label="Componente de {{ $condition->name }}" name="conditions[{{ $condition->code }}][component]">@foreach (['PHT_ROOT' => 'Raíz', 'PHT_STEM' => 'Fuste', 'PHT_BRANCH' => 'Ramas', 'PHT_FOLIAGE' => 'Follaje', 'PHT_FLOWER_FRUIT' => 'Flores/frutos', 'PHT_GENERAL' => 'General'] as $value => $label)<option value="{{ $value }}" @selected(old('conditions.'.$condition->code.'.component', $rule['component'] ?? $condition->category->code) === $value)>{{ $label }}</option>@endforeach</select></td></tr>
@endforeach
</tbody></table></div></section>
<section class="panel"><h2>Matriz de prioridad</h2><p>Severidad máxima de 0 a 4 y extensión: E0 sin componentes afectados; E1 uno; E2 dos o tres; E3 cuatro o cinco. La prioridad puede mantenerse o aumentar, nunca disminuir, al avanzar en severidad o extensión. Esta regla se comprueba antes de publicar, también en las celdas de respaldo.</p><table><thead><tr><th>Severidad</th>@foreach (['E0', 'E1', 'E2', 'E3'] as $label)<th>{{ $label }}</th>@endforeach</tr></thead><tbody>
@for ($severity = 0; $severity <= 4; $severity++)<tr><th>{{ $severity }}</th>@for ($extension = 0; $extension <= 3; $extension++)<td><select aria-label="Severidad {{ $severity }}, extensión E{{ $extension }}" name="matrix[{{ $severity }}][{{ $extension }}]">@foreach (\App\Enums\RiskPriority::cases() as $priority)<option value="{{ $priority->value }}" @selected((int) old('matrix.'.$severity.'.'.$extension, $configuration['matrix'][$severity][$extension]) === $priority->value)>{{ $priority->label() }}</option>@endforeach</select></td>@endfor</tr>@endfor
</tbody></table></section>
<section class="panel"><h2>Contradicciones</h2><p>Las condiciones incompatibles bloquean el guardado. Desactiva una regla para retirarla de la nueva versión.</p>
<div data-rule-list="conflicts">
@foreach (old('conflicts', $configuration['conflicts']) as $index => $rule)
@include('risk.rule', ['ruleKind' => 'conflicts'])
@endforeach
</div>
@if ($canManage)<button class="button secondary" type="button" data-add-rule="conflicts">Agregar contradicción</button>@endif
</section>
<section class="panel"><h2>Advertencias</h2><p>Permiten guardar y confirmar la decisión, pero solicitan revisar la observación. No corrigen selecciones automáticamente.</p>
<div data-rule-list="warnings">
@foreach (old('warnings', $configuration['warnings']) as $index => $rule)
@include('risk.rule', ['ruleKind' => 'warnings'])
@endforeach
</div>
@if ($canManage)<button class="button secondary" type="button" data-add-rule="warnings">Agregar advertencia</button>@endif
</section>
@if ($canManage)
@foreach (['conflicts', 'warnings'] as $ruleKind)
<template data-rule-template="{{ $ruleKind }}">@include('risk.rule', ['index' => '__INDEX__', 'rule' => ['enabled' => 1]])</template>
@endforeach
@endif
@if ($canManage)<button class="button" type="submit">Publicar nueva versión para {{ $organization->short_name ?: $organization->name }}</button>@endif
</fieldset></form>
<section class="panel"><h2>Versiones conservadas</h2><ul>@forelse ($versions as $version)<li>Versión {{ $version->version }} · {{ $version->name }} · {{ $version->created_at->format('d/m/Y H:i') }} UTC<details><summary>Consultar parámetros históricos</summary><table><thead><tr><th>Condición</th><th>Peso</th><th>Prioridad mínima</th></tr></thead><tbody>@foreach ($version->configuration['conditions'] as $row)<tr><td>{{ $row['label'] }}</td><td>{{ $row['weight'] }}</td><td>{{ $row['minimum'] ? \App\Enums\RiskPriority::from($row['minimum'])->label() : 'Sin mínimo' }}</td></tr>@endforeach</tbody></table>@include('risk.version', ['historicConfig' => $version->configuration])</details></li>@empty<li>Aún no hay versiones publicadas.</li>@endforelse</ul></section>
</div>
@endsection
@push('scripts')<script src="{{ asset('js/risk/methodology.js') }}" defer></script>@endpush
