<div class="inspection-conditions-grid">
    @foreach ($categories as $category)
        <details class="inspection-condition-group" data-condition-group open>
            <summary>{{ $category->name }} <span data-selected-count>Selecciones registradas</span></summary>
            <fieldset><legend>{{ $assessmentLabel }} · {{ $category->name }}</legend>
                @foreach ($category->conditions as $condition)
                    <label class="inspection-condition" for="condition-{{ $condition->id }}">
                        <input id="condition-{{ $condition->id }}" type="checkbox" name="{{ $fieldName }}[]" value="{{ $condition->id }}" @checked(in_array($condition->id, $selectedIds, true))>
                        <span>{{ $condition->name }}@if (!$condition->active || !$category->active)<small>Referencia histórica inactiva</small>@endif</span>
                    </label>
                @endforeach
            </fieldset>
        </details>
    @endforeach
</div>
@error($fieldName)<span class="field-error">{{ $message }}</span>@enderror
@error($fieldName.'.*')<span class="field-error">{{ $message }}</span>@enderror
