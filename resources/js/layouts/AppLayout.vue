<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import ThemeSwitch from '../components/ThemeSwitch.vue';
import { connection, signInAgain } from '../connection.js';

const page = usePage();

// What each role may open: members work tickets; editors also run spaces and systems;
// admins also the global setup and the docs.
const allNav = [
    { href: '/', label: 'Overview', active: (url) => url === '/', role: 'member' },
    { href: '/spaces', label: 'Spaces', active: (url) => url.startsWith('/spaces'), role: 'member' },
    { href: '/casebook', label: 'Casebook', active: (url) => url.startsWith('/casebook'), role: 'member' },
    { href: '/systems', label: 'Systems', active: (url) => url.startsWith('/systems'), role: 'editor' },
    { href: '/setup', label: 'Setup', active: (url) => url.startsWith('/setup'), role: 'admin' },
];
const may = (role) => role === 'member' || (role === 'editor' ? page.props.me?.is_editor : page.props.me?.is_admin);
const nav = computed(() => allNav.filter((item) => may(item.role)));

// Reference, not work: in the footer, out of the way.
const more = [
    { href: '/docs/concept', label: 'Concept' },
    { href: '/docs/team', label: 'Team' },
    { href: '/docs/outbox', label: 'Outbox API' },
    { href: '/design', label: 'Design' },
];

const url = computed(() => page.url.split(/[?#]/)[0]);
const env = computed(() => page.props.app?.env);
const hyper = computed(() => page.props.hyper);
const flash = computed(() => page.props.flash ?? {});
const gate = computed(() => page.props.app?.gate);
const me = computed(() => page.props.me);

function toggleHyper() {
    router.post('/hyper', {}, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <div class="min-h-screen">
        <!-- Slim: one quiet row. The work is the page, not the frame around it. -->
        <header class="sticky top-0 z-10 border-b border-line bg-page/90 backdrop-blur">
            <div class="flex w-full flex-wrap items-center gap-x-8 px-4 sm:px-6 lg:px-10">
                <Link href="/" class="flex items-center gap-2 py-2.5">
                    <img src="/favicon.svg" alt="" class="size-5" />
                    <span class="text-sm font-semibold tracking-tight">Ticket Worker</span>
                    <span v-if="env && env !== 'production'" class="eyebrow">{{ env }}</span>
                </Link>
                <div class="ml-auto flex items-center gap-2 py-1.5 sm:order-last">
                    <!-- On, it says so in the signal colour: every minute is not something to forget about. -->
                    <button
                        v-if="me?.is_editor"
                        type="button"
                        class="rounded-md px-2 py-1 text-xs transition-colors"
                        :class="hyper ? 'border border-signal font-medium text-signal' : 'text-muted hover:text-ink'"
                        :aria-pressed="hyper"
                        @click="toggleHyper"
                    >
                        {{ hyper ? 'Hyper: every minute' : 'Hyper off' }}
                    </button>
                    <span v-else-if="hyper" class="rounded-md border border-signal px-2 py-1 text-xs font-medium text-signal">Hyper: every minute</span>
                    <ThemeSwitch />
                    <Link v-if="me" href="/account" class="rounded-md px-2 py-1 text-xs hover:text-ink" :class="url.startsWith('/account') ? 'text-ink' : 'text-muted'">
                        {{ me.name }}<span v-if="!me.can_run_agents" class="text-signal"> · no Claude sign-in</span>
                    </Link>
                    <button v-if="gate" type="button" class="px-2 py-1 text-xs text-muted hover:text-ink" @click="router.post('/logout')">Sign out</button>
                </div>
                <nav aria-label="Main" class="-mb-px flex w-full gap-5 overflow-x-auto text-[0.8125rem] sm:w-auto">
                    <Link
                        v-for="item in nav"
                        :key="item.href"
                        :href="item.href"
                        class="border-b-2 py-2.5 whitespace-nowrap transition-colors"
                        :class="item.active(url) ? 'border-signal text-ink' : 'border-transparent text-muted hover:text-ink'"
                        :aria-current="item.active(url) ? 'page' : undefined"
                    >
                        {{ item.label }}
                    </Link>
                </nav>
            </div>
        </header>

        <main class="w-full px-4 py-8 sm:px-6 sm:py-10 lg:px-10">
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

        <footer v-if="me?.is_admin" class="flex flex-wrap gap-x-5 gap-y-1 border-t border-line px-4 py-4 text-xs text-muted sm:px-6 lg:px-10">
            <Link v-for="item in more" :key="item.href" :href="item.href" class="hover:text-ink" :class="{ 'text-ink': url.startsWith(item.href) }">{{ item.label }}</Link>
        </footer>
    </div>
</template>
