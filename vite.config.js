import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // Fetched once, at build time, and served from /build: no font CDN at runtime.
            fonts: [
                bunny('Instrument Sans', { weights: [400, 500, 600] }),
                bunny('Instrument Serif', { weights: [400], styles: ['normal', 'italic'] }),
                bunny('JetBrains Mono', { weights: [400, 500] }),
            ],
        }),
        vue({
            template: {
                transformAssetUrls: { base: null, includeAbsolute: false },
            },
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
