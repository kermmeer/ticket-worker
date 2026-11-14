<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import ThemeSwitch from '../components/ThemeSwitch.vue';
import { connection, reachable, signInAgain } from '../connection.js';

const page = usePage();

// Whatever a click is refused with, said where you are: the banners above sit at the top of
// a page you may have scrolled far down, and a refusal nobody sees looks like a broken button.
const toast = ref(null);
let toastTimer = null;
function showToast(text) {
    if (!text) return;
    toast.value = text;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => (toast.value = null), 12000);
}
let stopListening = [];
onMounted(() => {
    stopListening = [
        router.on('success', (event) => showToast(event.detail.page.props.flash?.error)),
        router.on('error', (event) => showToast(Object.values(event.detail.errors ?? {})[0])),
    ];
});
onBeforeUnmount(() => stopListening.forEach((stop) => stop()));

// What each role may open: members work tickets; editors also run spaces and systems;
// admins also the global setup and the docs.
const allNav = [
    { href: '/', label: 'Overview', active: (url) => url === '/', role: 'member' },
    { href: '/agents', label: 'Agents', active: (url) => url.startsWith('/agents'), role: 'member' },
    { href: '/spaces', label: 'Spaces', active: (url) => url.startsWith('/spaces'), role: 'member' },
    { href: '/casebook', label: 'Casebook', active: (url) => url.startsWith('/casebook'), role: 'member' },
    { href: '/systems', label: 'Systems', active: (url) => url.startsWith('/systems'), role: 'editor' },
    { href: '/setup', label: 'Setup', active: (url) => url.startsWith('/setup'), role: 'admin' },
];
const may = (role) => role === 'member' || (role === 'editor' ? page.props.me?.is_editor : page.props.me?.is_admin);
const nav = computed(() => allNav.filter((item) => may(item.role)));
// The phone's bar: handling tickets, in reach of a thumb. Everything else is behind ☰.
const phoneNav = [
    { href: '/', label: 'Tickets', active: (url) => url === '/' || url.startsWith('/tickets'), icon: 'M4 5h12M4 10h12M4 15h8' },
    { href: '/agents', label: 'Agents', active: (url) => url.startsWith('/agents'), icon: 'M10 3v2m0 10v2m7-7h-2M5 10H3m11.5-4.5L13 7M7 13l-1.5 1.5m9 0L13 13M7 7 5.5 5.5M10 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z' },
    { href: '/casebook', label: 'Casebook', active: (url) => url.startsWith('/casebook'), icon: 'M5 3.5h8.5a1 1 0 0 1 1 1v12l-5-3-5 3v-12a1 1 0 0 1 .5-1Z' },
    { href: '/account', label: 'You', active: (url) => url.startsWith('/account'), icon: 'M10 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm-6 7a6 6 0 0 1 12 0' },
];

// Reference, not work: in the footer, out of the way.
const more = [
    { href: '/docs/concept', label: 'Concept' },
    { href: '/docs/team', label: 'Team' },
    { href: '/docs/outbox', label: 'Outbox API' },
    { href: '/design', label: 'Design' },
    { href: '/audit', label: 'Audit trail' },
];

const url = computed(() => page.url.split(/[?#]/)[0]);
const env = computed(() => page.props.app?.env);
const hyper = computed(() => page.props.hyper);
const hyperNext = computed(() => page.props.hyperNext);
const flash = computed(() => page.props.flash ?? {});
const gate = computed(() => page.props.app?.gate);
const me = computed(() => page.props.me);

// On a phone: the whole menu in a panel from the left, behind ☰. The bar at the bottom keeps
// the four things used most; this holds everything, so a phone can do all a desk can.
const menuOpen = ref(false);
const menuClose = ref(null);
function openMenu() {
    menuOpen.value = true;
    nextTick(() => menuClose.value?.focus());
}
watch(menuOpen, (open) => (document.body.style.overflow = open ? 'hidden' : ''));
const onKey = (event) => event.key === 'Escape' && (menuOpen.value = false);
let stopNavigate = null;
onMounted(() => {
    window.addEventListener('keydown', onKey);
    stopNavigate = router.on('navigate', () => (menuOpen.value = false));
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    stopNavigate?.();
    document.body.style.overflow = '';
});

function toggleHyper() {
    router.post('/hyper', {}, { preserveScroll: true, preserveState: true, onSuccess: () => hyperChannel?.postMessage('changed') });
}

// Hyper mode is one switch for everyone: every open window follows it. A switch in this
// browser reaches its other windows at once; the schedule's, or another person's, within a minute.
const refreshHyper = () => reachable() && router.reload({ only: ['hyper', 'hyperNext'], async: true, preserveScroll: true, preserveState: true, showProgress: false });
let hyperChannel = null;
let hyperTimer = null;
const onVisible = () => document.visibilityState === 'visible' && refreshHyper();
onMounted(() => {
    hyperChannel = typeof BroadcastChannel !== 'undefined' ? new BroadcastChannel('ticket-worker-hyper') : null;
    if (hyperChannel) hyperChannel.onmessage = refreshHyper;
    hyperTimer = setInterval(() => document.visibilityState === 'visible' && refreshHyper(), 60000);
    document.addEventListener('visibilitychange', onVisible);
});
onBeforeUnmount(() => {
    hyperChannel?.close();
    clearInterval(hyperTimer);
    document.removeEventListener('visibilitychange', onVisible);
});
</script>

<template>
    <div class="min-h-screen">
        <!-- Slim: one quiet row. The work is the page, not the frame around it. -->
        <header class="sticky top-0 z-10 border-b border-line bg-page/90 backdrop-blur">
            <div class="flex w-full flex-wrap items-center gap-x-8 px-4 sm:px-6 lg:px-10">
                <button
                    type="button"
                    class="-ml-2 -mr-6 inline-flex size-10 items-center justify-center rounded-md text-muted hover:text-ink sm:hidden"
                    aria-label="Menu"
                    :aria-expanded="menuOpen"
                    aria-controls="phone-menu"
                    @click="openMenu"
                >
                    <svg aria-hidden="true" viewBox="0 0 20 20" class="size-5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M3.5 5.5h13M3.5 10h13M3.5 14.5h13" /></svg>
                </button>
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
                        {{ hyper ? 'Hyper: every minute' : 'Hyper off' }}<span v-if="hyperNext" class="font-normal opacity-75"> · {{ hyperNext }}</span>
                    </button>
                    <span v-else-if="hyper" class="rounded-md border border-signal px-2 py-1 text-xs font-medium text-signal">Hyper: every minute<span v-if="hyperNext" class="font-normal opacity-75"> · {{ hyperNext }}</span></span>
                    <span class="hidden sm:block"><ThemeSwitch /></span>
                    <Link v-if="me" href="/account" class="rounded-md px-2 py-1 text-xs hover:text-ink max-sm:hidden" :class="url.startsWith('/account') ? 'text-ink' : 'text-muted'">
                        {{ me.name }}<span v-if="!me.can_run_agents" class="text-signal"> · no Claude sign-in</span>
                    </Link>
                    <button v-if="gate" type="button" class="px-2 py-1 text-xs text-muted hover:text-ink" @click="router.post('/logout')">Sign out</button>
                </div>
                <nav aria-label="Main" class="-mb-px hidden w-full gap-5 overflow-x-auto text-[0.8125rem] sm:flex sm:w-auto">
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

        <!-- A long path or address in an agent's text may break anywhere rather than widen the page. -->
        <main class="w-full px-4 pt-6 pb-28 [overflow-wrap:anywhere] sm:px-6 sm:py-10 lg:px-10">
            <div v-if="connection.signedOut" role="alert" class="mb-8 flex flex-wrap items-center gap-3 rounded-md border border-signal/40 bg-surface px-4 py-2.5 text-sm">
                <span class="text-signal">Your sign-in has expired, so nothing reaches Ticket Worker until you sign in again.</span>
                <span class="text-muted">A message you were typing to the agent is kept.</span>
                <button type="button" class="btn btn-signal ml-auto px-3 py-1" @click="signInAgain">Sign in again</button>
            </div>
            <p v-else-if="connection.problem" role="alert" class="mb-8 flex items-start gap-3 rounded-md border border-signal/40 bg-surface px-4 py-2.5 text-sm text-signal">
                <span class="flex-1">{{ connection.problem }}</span>
                <button type="button" class="text-muted hover:text-ink" aria-label="Dismiss" @click="connection.problem = null">✕</button>
            </p>
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

        <p v-if="toast" role="alert" class="fixed inset-x-4 bottom-20 z-30 mx-auto flex max-w-xl items-start gap-3 rounded-md border border-signal/50 bg-surface px-4 py-3 text-sm text-signal shadow-lg sm:bottom-6">
            <span class="flex-1">{{ toast }}</span>
            <button type="button" class="text-muted hover:text-ink" aria-label="Dismiss" @click="toast = null">✕</button>
        </p>

        <!-- On a phone: everything, behind ☰. -->
        <div v-if="menuOpen" class="fixed inset-0 z-40 sm:hidden">
            <div class="absolute inset-0 bg-ink/30" aria-hidden="true" @click="menuOpen = false"></div>
            <nav
                id="phone-menu"
                aria-label="Menu"
                role="dialog"
                aria-modal="true"
                class="absolute inset-y-0 left-0 flex w-72 max-w-[85vw] flex-col overflow-y-auto border-r border-line bg-page pb-[env(safe-area-inset-bottom)] shadow-xl"
            >
                <div class="flex items-center gap-2 border-b border-line px-4 py-2">
                    <img src="/favicon.svg" alt="" class="size-5" />
                    <span class="text-sm font-semibold tracking-tight">Ticket Worker</span>
                    <span v-if="env && env !== 'production'" class="eyebrow">{{ env }}</span>
                    <button ref="menuClose" type="button" class="ml-auto inline-flex size-10 items-center justify-center rounded-md text-muted hover:text-ink" aria-label="Close the menu" @click="menuOpen = false">✕</button>
                </div>

                <Link v-if="me" href="/account" class="border-b border-line px-4 py-3 text-sm" :class="url.startsWith('/account') ? 'text-ink' : 'text-muted'">
                    <span class="block font-medium text-ink">{{ me.name }}</span>
                    <span class="text-xs" :class="me.can_run_agents ? 'text-muted' : 'text-signal'">{{ me.can_run_agents ? 'Your account' : 'No Claude sign-in yet: set one up' }}</span>
                </Link>

                <ul class="py-2">
                    <li v-for="item in nav" :key="item.href">
                        <Link
                            :href="item.href"
                            class="flex min-h-12 items-center border-l-2 px-4 text-[0.9375rem]"
                            :class="item.active(url) ? 'border-signal font-medium text-ink' : 'border-transparent text-muted'"
                            :aria-current="item.active(url) ? 'page' : undefined"
                        >
                            {{ item.label }}
                        </Link>
                    </li>
                    <li>
                        <Link href="/work/new" class="flex min-h-12 items-center border-l-2 px-4 text-[0.9375rem]" :class="url.startsWith('/work') ? 'border-signal font-medium text-ink' : 'border-transparent text-muted'">New work</Link>
                    </li>
                </ul>

                <template v-if="me?.is_admin">
                    <p class="eyebrow border-t border-line px-4 pt-4 pb-1">Reference</p>
                    <ul class="pb-2">
                        <li v-for="item in more" :key="item.href">
                            <Link :href="item.href" class="flex min-h-11 items-center px-4 text-sm" :class="url.startsWith(item.href) ? 'text-ink' : 'text-muted'">{{ item.label }}</Link>
                        </li>
                    </ul>
                </template>

                <div class="mt-auto space-y-3 border-t border-line px-4 py-4 text-sm">
                    <button
                        v-if="me?.is_editor"
                        type="button"
                        class="w-full rounded-md px-3 py-2 text-left"
                        :class="hyper ? 'border border-signal font-medium text-signal' : 'border border-line text-muted'"
                        :aria-pressed="hyper"
                        @click="toggleHyper"
                    >
                        {{ hyper ? 'Hyper: syncing every minute (tap to stop)' : 'Hyper off: syncing at the usual pace' }}
                        <span v-if="hyperNext" class="block text-xs font-normal opacity-75">Its schedule: {{ hyperNext }}</span>
                    </button>
                    <div class="flex items-center justify-between gap-3"><span class="text-muted">Day or night</span><ThemeSwitch /></div>
                    <button v-if="gate" type="button" class="text-muted underline" @click="router.post('/logout')">Sign out</button>
                </div>
            </nav>
        </div>

        <!-- On a phone: what you work with most, in reach of a thumb; the rest behind ☰. -->
        <nav aria-label="Main" class="fixed inset-x-0 bottom-0 z-20 grid grid-cols-4 border-t border-line bg-page/95 pb-[env(safe-area-inset-bottom)] backdrop-blur sm:hidden">
            <Link
                v-for="item in phoneNav"
                :key="item.href"
                :href="item.href"
                class="flex min-h-14 flex-col items-center justify-center gap-0.5 border-t-2 text-xs"
                :class="item.active(url) ? 'border-signal font-medium text-ink' : 'border-transparent text-muted'"
                :aria-current="item.active(url) ? 'page' : undefined"
            >
                <svg aria-hidden="true" viewBox="0 0 20 20" class="size-5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path :d="item.icon" /></svg>
                {{ item.label }}
            </Link>
        </nav>

        <footer v-if="me?.is_admin" class="flex flex-wrap gap-x-5 gap-y-1 border-t border-line px-4 py-4 text-xs text-muted sm:px-6 lg:px-10">
            <Link v-for="item in more" :key="item.href" :href="item.href" class="hover:text-ink" :class="{ 'text-ink': url.startsWith(item.href) }">{{ item.label }}</Link>
        </footer>
    </div>
</template>
