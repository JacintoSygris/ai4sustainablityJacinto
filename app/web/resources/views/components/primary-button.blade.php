{{-- Mirrors the default variant of components/ui/button.tsx in the Next app. --}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex h-9 shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow-sm transition-all hover:bg-primary/90 focus:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50']) }}>
    {{ $slot }}
</button>
