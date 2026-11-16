@props(['value'])

{{-- Mirrors components/ui/label.tsx in the Next app. --}}
<label {{ $attributes->merge(['class' => 'flex items-center gap-2 text-sm font-medium leading-none text-foreground']) }}>
    {{ $value ?? $slot }}
</label>
