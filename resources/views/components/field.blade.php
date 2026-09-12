@props(['name', 'label', 'value' => '', 'type' => 'text'])
<div class="field">
    <label for="{{ $name }}">{{ $label }}</label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ old($name, $value) }}" {{ $attributes }}>
    @error($name)<span class="field-error">{{ $message }}</span>@enderror
</div>
