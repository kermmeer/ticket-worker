<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import ThemeSwitch from '../components/ThemeSwitch.vue';
import { connection, signInAgain } from '../connection.js';

const page = usePage();

const nav = [
    { href: '/', label: 'Overview', active: (url) => url === '/' },
    { href: '/spaces', label: 'Spaces', active: (url) => url.startsWith('/spaces') },
    { href: '/casebook', label: 'Casebook', active: (url) => url.startsWith('/casebook') },
    { href: '/systems', label: 'Systems', active: (url) => url.startsWith('/systems') },
    { href: '/setup', label: 'Setup', active: (url) => url.startsWith('/setup') },
    { href: '/docs/concept', label: 'Concept', active: (url) => url.startsWith('/docs') },
    { href: '/design', label: 'Design', active: (url) => url.startsWith('/design') },
];

const url = computed(() => page.url.split(/[?#]/)[0]);
const env = computed(() => page.props.app?.env);
const hyper = computed(() => page.props.hyper);
const flash = computed(() => page.props.flash ?? {});
const gate = computed(() => page.props.app?.gate);

function toggleHyper() {
    router.post('/hyper', {}, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <div class="min-h-screen">
        <header class="sticky top-0 z-10 border-b border-line bg-page/90 backdrop-blur">
            <div class="flex w-full flex-wrap items-end gap-x-10 px-4 pt-4 sm:px-6 lg:px-10">
                <Link href="/" class="flex items-center gap-2 pb-3">
                    <img src="/favicon.svg" alt="" class="size-6" />
                    <span class="display text-xl leading-none">Ticket Worker</span>
                    <span v-if="env && env !== 'production'" class="eyebrow">{{ env }}</span>
                </Link>
                <div class="ml-auto flex items-center gap-2 pb-2.5 sm:order-last">
                    <!-- On, it says so in the signal colour: every minute is not something to forget about. -->
                    <button
                        type="button"
                        class="rounded-md border px-2.5 py-1 text-xs font-medium transition-colors"
                        :class="hyper ? 'border-signal text-signal' : 'border-line bg-surface text-muted hover:text-ink'"
                        :aria-pressed="hyper"
                        @click="toggleHyper"
                    >
                        {{ hyper ? 'Hyper: every minute' : 'Hyper off' }}
                    </button>
                    <ThemeSwitch />
                    <button v-if="gate" type="button" class="rounded-md border border-line bg-surface px-2.5 py-1 text-xs text-muted hover:text-ink" @click="router.post('/logout')">
                        Sign out
                    </button>
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

        <main class="w-full px-4 py-10 sm:px-6 sm:py-14 lg:px-10">
            <div v-if="connection.signedOut" role="alert" class="mb-8 flex flex-wrap items-center gap-3 rounded-md border border-signal/40 bg-surface px-4 py-2.5 text-sm">
                <span class="text-signal">Your sign-in has expired, so nothing reaches Ticket Worker until you sign in again.</span>
                <span class="text-muted">A message you were typing to the agent is kept.</span>
                <button type="button" class="btn btn-signal ml-auto px-3 py-1" @click="signInAgain">Sign in again</button>
            </div>
            <p v-else-if="connection.offline" role="status" class="mb-8 rounded-md border border-waiting/40 bg-surface px-4 py-2.5 text-sm text-waiting">
                Cannot reach Ticket Worker. It tries again by itself; what you typed is kept.
            </p>
            <p v-if="flash.success" role="status" class="mb-8 rounded-md border border-done/40 bg-surface px-4 py-2.5 text-sm text-done">
                {{ flash.success }}
            </p>
            <p v-if="flash.error" role="alert" class="mb-8 rounded-md border border-signal/40 bg-surface px-4 py-2.5 text-sm text-signal">
                {{ flash.error }}
            </p>
            <slot />
        </main>
    </div>
</template>
