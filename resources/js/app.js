import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { watchConnection } from './connection.js';
import AppLayout from './layouts/AppLayout.vue';

watchConnection();

createInertiaApp({
    title: (title) => (title ? `${title} · Ticket Worker` : 'Ticket Worker'),
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.vue', { eager: true });
        const page = pages[`./pages/${name}.vue`];
        // Every page sits in the app's frame unless it names a layout of its own, or null.
        if (page.default.layout === undefined) {
            page.default.layout = AppLayout;
        }
        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: { color: '#c2410c' },
});
