/** @type {import('tailwindcss').Config} */
export default {
    content: ['./resources/views/**/*.blade.php'],
    theme: {
        extend: {
            fontFamily: {
                sans: ['IBM Plex Sans Arabic', 'IBM Plex Sans', 'system-ui', 'sans-serif'],
            },
            colors: {
                brand: {
                    DEFAULT: '#0846D0',
                    dark: '#0A1F3D',
                },
            },
        },
    },
    plugins: [],
};
