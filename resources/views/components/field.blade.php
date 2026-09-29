@props(['name', 'label', 'value' => '', 'type' => 'text'])
@php
    $fieldAttributes = $attributes;
    if ($errors->has($name)) {
        $fieldAttributes = $attributes->except(['aria-invalid', 'aria-describedby'])->merge([
            'aria-invalid' => 'true',
            'aria-describedby' => trim($attributes->get('aria-describedby', '').' '.$name.'-error'),
        ]);
    }
@endphp
<div class="field">
    <label for="{{ $name }}">{{ $label }}</label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ old($name, $value) }}" {{ $fieldAttributes }}>
    @error($name)<span id="{{ $name }}-error" class="field-error">{{ $message }}</span>@enderror
</div>
