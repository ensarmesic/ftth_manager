@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'options' => [],
    'placeholder' => '',
    'required' => false,
])

<label class="grid gap-1 text-sm">
    <span>{{ $label }}</span>
    @if ($type === 'select')
        <select name="{{ $name }}" @required($required) {{ $attributes->merge(['class' => 'rounded-md border border-slate-300 px-3 py-2']) }}>
            @foreach ($options as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected((string) old($name, $value) === (string) $optionValue)>{{ $optionLabel }}</option>
            @endforeach
        </select>
    @elseif ($type === 'textarea')
        <textarea name="{{ $name }}" placeholder="{{ $placeholder }}" @required($required) {{ $attributes->merge(['class' => 'rounded-md border border-slate-300 px-3 py-2']) }}>{{ old($name, $value) }}</textarea>
    @else
        <input name="{{ $name }}" type="{{ $type }}" value="{{ old($name, $value) }}" placeholder="{{ $placeholder }}" @required($required) {{ $attributes->merge(['class' => 'rounded-md border border-slate-300 px-3 py-2']) }}>
    @endif
    @error($name)
        <span class="text-xs font-semibold text-red-700">{{ $message }}</span>
    @enderror
</label>
