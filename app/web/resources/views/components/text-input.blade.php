@props(['disabled' => false])

{{-- Mirrors components/ui/input.tsx in the Next app. --}}
<input @disabled($disabled) {{ $attributes->merge(['class' => 'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base text-foreground shadow-sm outline-none transition-[color,box-shadow] placeholder:text-muted-foreground focus:border-ring focus:ring-[3px] focus:ring-ring/50 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm']) }}>
