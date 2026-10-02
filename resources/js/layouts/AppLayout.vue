<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import ThemeSwitch from '../components/ThemeSwitch.vue';

const page = usePage();

const nav = [
    { href: '/', label: 'Overview', active: (url) => url === '/' },
    { href: '/setup', label: 'Setup', active: (url) => url.startsWith('/setup') },
    { href: '/docs/concept', label: 'Concept', active: (url) => url.startsWith('/docs') },
    { href: '/design', label: 'Design', active: (url) => url.startsWith('/design') },
];

const url = computed(() => page.url.split(/[?#]/)[0]);
const env = computed(() => page.props.app?.env);
</script>

<template>
    <div class="min-h-screen">
        <header class="sticky top-0 z-10 border-b border-line bg-page/90 backdrop-blur">
            <div class="mx-auto flex max-w-6xl flex-wrap items-end gap-x-10 px-4 pt-4 sm:px-6">
                <Link href="/" class="flex items-baseline gap-2 pb-3">
                    <span class="font-serif text-[1.65rem] leading-none tracking-tight">Ticket Worker</span>
                    <span v-if="env && env !== 'production'" class="eyebrow">{{ env }}</span>
                </Link>
                <div class="ml-auto pb-2.5 sm:order-last">
                    <ThemeSwitch />
                </div>
                <nav aria-label="Main" class="-mb-px flex w-full gap-6 overflow-x-auto text-sm sm:w-auto">
                    <Link
                        v-for="item in nav"
                        :key="item.href"
                        :href="item.href"
                        class="border-b-2 pt-1 pb-3 whitespace-nowrap transition-colors"
                        :class="item.active(url) ? 'border-signal font-medium text-ink' : 'border-transparent text-muted hover:text-ink'"
                        :aria-current="item.active(url) ? 'page' : undefined"
                    >
                        {{ item.label }}
                    </Link>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-4 py-10 sm:px-6 sm:py-14">
            <slot />
        </main>
    </div>
</template>
