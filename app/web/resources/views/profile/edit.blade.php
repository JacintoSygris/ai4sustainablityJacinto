<x-app-layout>
    <x-slot name="header">
        <h1 class="text-3xl font-bold text-foreground">{{ __('Profile') }}</h1>
    </x-slot>

    {{-- One column of full-width cards, like the Next settings page. --}}
    <div class="mx-auto max-w-3xl space-y-6">
        @if (\App\Services\ConsentLearningAuthorizationAuthority::enabled() || $user->learning_consent_at)
            <div class="rounded-xl border border-border bg-white p-6 shadow-sm sm:p-8">
                @include('profile.partials.learning-consent')
            </div>
        @endif

        <div class="rounded-xl border border-border bg-white p-6 shadow-sm sm:p-8">
            @include('profile.partials.update-profile-information-form')
        </div>

        <div class="rounded-xl border border-border bg-white p-6 shadow-sm sm:p-8">
            @include('profile.partials.update-password-form')
        </div>

        <div class="rounded-xl border border-border bg-white p-6 shadow-sm sm:p-8">
            @include('profile.partials.delete-user-form')
        </div>
    </div>
</x-app-layout>
