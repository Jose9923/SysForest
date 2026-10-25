<div class="import-rows">
    @forelse ($rows as $row)
        <article class="import-row" aria-labelledby="import-row-{{ $row->row_number }}"><header class="import-row-heading"><div><h3 id="import-row-{{ $row->row_number }}">Fila {{ $row->row_number }}</h3><span class="import-code">{{ $row->tree_code ?: 'Código por asignar' }}</span></div><span class="pill {{ $row->status === 'invalid' ? 'import-invalid' : '' }}">{{ $rowStatusLabels[$row->status] ?? $row->status }}</span></header>
            @if ($row->errors)
                <ul class="import-row-errors" aria-label="Errores de la fila {{ $row->row_number }}">@foreach ($row->errors as $field => $messages)@foreach ((array) $messages as $message)<li><strong>{{ $fieldDefinitions[$field]['label'] ?? $field }}:</strong> {{ \App\Support\ExcelReferencePresentation::riskMessage(\App\Support\ExcelReferencePresentation::text($message)) }}</li>@endforeach @endforeach</ul>
            @endif
            <details class="import-row-values"><summary>Ver valores de la fila {{ $row->row_number }}</summary><dl>@foreach ($row->raw_values as $field => $value)<div><dt>{{ $fieldDefinitions[$field]['label'] ?? $field }}</dt><dd>{{ $value === null || $value === '' ? 'Sin dato' : (is_scalar($value) ? \App\Support\ExcelReferencePresentation::text((string) $value) : 'Valor no válido') }}</dd></div>@endforeach</dl></details>
            @if (!empty($calculatedFields[$row->row_number]))
                <details class="import-row-values"><summary>Ver cálculos del servidor</summary><dl>@foreach ($calculatedFields[$row->row_number] as $field)<div><dt>{{ $field['label'] }}</dt><dd>{{ $field['value'] === null || $field['value'] === '' ? 'Sin dato' : $field['value'] }}</dd></div>@endforeach</dl></details>
            @endif
            @if ($row->tree_id || $row->inspection_id)<div class="import-result-links">@if ($row->tree_id)<a class="text-link" href="{{ route('trees.show', $row->tree_id) }}">Ver árbol →</a>@endif @if ($row->inspection_id)<a class="text-link" href="{{ route('inspections.show', $row->inspection_id) }}">Ver inspección →</a>@endif</div>@endif
        </article>
    @empty
        <p class="muted import-empty">No hay filas para mostrar en este lote.</p>
    @endforelse
</div>
