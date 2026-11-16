import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    // The Blade pages are light-only, like the Next app (which scopes `dark:` to a `.dark` class).
    // Without this, Tailwind applies `dark:` variants from the OS colour scheme and the Breeze
    // inputs turn black on the light layout.
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            // Same tokens as the Next app (app/frontend/app/globals.css, light theme) converted to hex,
            // so the Blade forms (reset password, verify email...) look like the React ones.
            colors: {
                background: '#f7f9fa',
                foreground: '#14151f',
                'muted-foreground': '#61626f',
                border: '#d8dfe4',
                input: '#d8dfe4',
                ring: '#4838bf',
                primary: {
                    DEFAULT: '#4838bf',
                    foreground: '#fcfcfc',
                },
                destructive: '#e7000b',
            },
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms],
};
