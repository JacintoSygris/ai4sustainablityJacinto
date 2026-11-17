{{-- Same markup and classes as components/dashboard/dashboard-header.tsx in the Next app
     (brand, help link, LanguageSelector, avatar with user menu), so /profile looks like /dashboard. --}}
@php
    $displayName = Auth::user()->name ?: Auth::user()->email;
    $initials = mb_strtoupper(mb_substr(collect(explode(' ', trim($displayName)))->map(fn ($part) => mb_substr($part, 0, 1))->implode(''), 0, 2));
    $isEnglish = app()->getLocale() === 'en';
@endphp
<header class="sticky top-0 z-50 w-full border-b border-border/40 bg-background/95 backdrop-blur">
    <div class="container mx-auto flex h-16 items-center justify-between px-4">
        <a href="/dashboard" class="flex items-center gap-1">
            <span class="text-2xl font-bold text-primary">Airis</span>
            <span class="text-xs text-muted-foreground">{{ __('By Sygris') }}</span>
        </a>

        <div class="flex items-center gap-4">
            <a href="/help" class="flex items-center gap-2 text-sm text-primary hover:underline">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><path d="M12 17h.01"></path></svg>
                {{ __('¿Necesitas ayuda?') }}
            </a>

            <form method="POST" action="{{ route('api.locale.update') }}" class="flex flex-wrap items-center gap-2 text-sm">
                @csrf
                <input type="hidden" name="return_to" value="{{ request()->getPathInfo() }}">
                <label for="app-language">{{ $isEnglish ? 'Language' : 'Idioma' }}</label>
                {{-- appearance-auto/bg-none/pr-2 undo @tailwindcss/forms so the select renders natively, as in Next. --}}
                <select id="app-language" name="locale" onchange="this.form.requestSubmit()"
                        class="appearance-auto rounded-md border border-input bg-background bg-none px-2 py-2 pr-2 text-sm focus-visible:ring-2 focus-visible:ring-primary">
                    <option value="es" @selected(! $isEnglish)>{{ $isEnglish ? 'Spanish' : 'Español' }}</option>
                    <option value="en" @selected($isEnglish)>{{ $isEnglish ? 'English' : 'Inglés' }}</option>
                </select>
                <noscript><button type="submit" class="rounded-md border border-input px-2 py-2">{{ $isEnglish ? 'Apply' : 'Aplicar' }}</button></noscript>
            </form>

            <div x-data="{ open: false }" class="relative" @keydown.escape.window="open = false">
                <button type="button" @click="open = ! open" :aria-expanded="open" aria-haspopup="menu"
                        class="rounded-full focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2"
                        aria-label="{{ __('Abrir menú de usuario') }}">
                    <span class="flex h-10 w-10 items-center justify-center overflow-hidden rounded-full border-2 border-accent bg-accent text-sm font-medium text-accent-foreground">{{ $initials }}</span>
                </button>

                <div x-cloak x-show="open" @click.outside="open = false" x-transition.opacity role="menu"
                     class="absolute right-0 z-50 mt-2 w-56 min-w-[8rem] overflow-hidden rounded-md border border-border bg-popover p-1 text-popover-foreground shadow-md">
                    <div class="px-2 py-1.5">
                        <p class="text-sm font-medium">{{ $displayName }}</p>
                        <p class="text-xs text-muted-foreground">{{ Auth::user()->email }}</p>
                    </div>
                    <div class="-mx-1 my-1 h-px bg-border"></div>
                    <a href="/settings" role="menuitem" class="block rounded-sm px-2 py-1.5 text-sm hover:bg-secondary">{{ __('Configuración') }}</a>
                    <a href="{{ route('profile.edit') }}" role="menuitem" class="block rounded-sm px-2 py-1.5 text-sm hover:bg-secondary">{{ __('Profile') }}</a>
                    <div class="-mx-1 my-1 h-px bg-border"></div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" role="menuitem" class="block w-full rounded-sm px-2 py-1.5 text-left text-sm text-destructive hover:bg-secondary">{{ __('Log Out') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</header>
