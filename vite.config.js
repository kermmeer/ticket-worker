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
                // Italics are real ones, not slanted by the browser; only the upright text
                // weights are preloaded, the rest load when a page uses them.
                bunny('Geist', {
                    weights: [400, 500, 600, 700],
                    styles: ['normal', 'italic'],
                    preload: [{ weight: 400 }, { weight: 600 }],
                }),
                bunny('Geist Mono', { weights: [400, 500], preload: [{ weight: 400 }] }),
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
