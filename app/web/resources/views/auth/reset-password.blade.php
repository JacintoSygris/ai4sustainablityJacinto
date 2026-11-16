<x-guest-layout>
    {{-- Same layout as the Next auth forms (app/frontend/app/(auth)/forgot-password/page.tsx). --}}
    <form method="POST" action="{{ route('password.store') }}" class="w-full max-w-md space-y-6">
        @csrf

        <!-- Password Reset Token -->
        <input id="reset-token" type="hidden" name="token" value="">

        <div class="text-center">
            <h1 class="text-2xl font-semibold text-foreground">{{ __('Reset Password') }}</h1>
            <p class="mt-2 text-sm text-muted-foreground">{{ __('Choose a new password for your account.') }}</p>
        </div>

        @if ($errors->any())
            <div class="rounded-lg bg-destructive/10 p-3 text-sm text-destructive" role="alert">{{ $errors->first() }}</div>
        @endif

        <div class="space-y-4">
            <!-- Email Address -->
            <div class="space-y-2">
                <x-input-label for="email" :value="__('Email')" />
                <x-text-input id="email" type="email" name="email" :value="old('email', $request->email)" required autofocus autocomplete="username" />
            </div>

            <!-- Password -->
            <div class="space-y-2">
                <x-input-label for="password" :value="__('Password')" />
                <x-text-input id="password" type="password" name="password" required autocomplete="new-password" />
            </div>

            <!-- Confirm Password -->
            <div class="space-y-2">
                <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
                <x-text-input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" />
            </div>
        </div>

        <x-primary-button class="w-full">
            {{ __('Reset Password') }}
        </x-primary-button>

        <div class="text-center">
            <a href="/login" class="text-sm text-primary hover:underline">{{ __('Back to sign in') }}</a>
        </div>
    </form>

    <script>
        (() => {
            const fragment = new URLSearchParams(window.location.hash.slice(1));
            const token = fragment.get('token') ?? '';
            document.getElementById('reset-token').value = token;
            window.history.replaceState(null, '', window.location.pathname + window.location.search);
        })();
    </script>
</x-guest-layout>
