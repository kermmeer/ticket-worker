<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import SpaceLabel from '../components/SpaceLabel.vue';
import StateBadge from '../components/StateBadge.vue';
import TicketKey from '../components/TicketKey.vue';
import { ago } from '../time.js';

const props = defineProps({
    hasSpaces: { type: Boolean, required: true },
    spaces: { type: Array, required: true },
    tickets: { type: Array, required: true },
});

// What each ticket needs from you (CONCEPT.md §6).
const groups = [
    { key: 'needs-you', label: 'Needs you', hint: 'A proposal is ready, or the agent asked you something' },
    { key: 'working', label: 'Working', hint: 'An agent is busy on it right now' },
    { key: 'new-activity', label: 'New activity', hint: 'Commented on or changed since the last analysis' },
    { key: 'not-analysed', label: 'Not analysed', hint: 'Synced, nothing started yet' },
    { key: 'parked', label: 'Parked', hint: 'Session closed, ticket still open in Jira' },
];

const spaceFilter = ref(null);
const search = ref('');
const searchBox = ref(null);

const spacesById = computed(() => Object.fromEntries(props.spaces.map((space) => [space.id, space])));

const shown = computed(() => {
    const query = search.value.trim().toLowerCase();
    return props.tickets.filter(
        (ticket) =>
            (spaceFilter.value === null || ticket.space_id === spaceFilter.value) &&
            (query === '' || ticket.key.toLowerCase().includes(query) || ticket.summary.toLowerCase().includes(query)),
    );
});

const inGroup = computed(() => Object.fromEntries(groups.map((group) => [group.key, shown.value.filter((ticket) => ticket.group === group.key)])));

// "/" goes to search, unless you are already typing somewhere.
function onKey(event) {
    if (event.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) {
        event.preventDefault();
        searchBox.value?.focus();
    }
}

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <Head title="Overview" />

    <template v-if="!hasSpaces || spaces.length === 0">
        <p class="eyebrow">Overview</p>
        <h1 class="display mt-3 text-5xl leading-[1.05] sm:text-6xl">No cases yet.</h1>
        <p class="mt-5 max-w-2xl text-lg text-muted">
            Ticket Worker lists the open tickets of the Jira spaces you set up, grouped by what they need from you.
            <template v-if="!hasSpaces">There is no space yet: set one up and its tickets appear here.</template>
            <template v-else>No space is active: activate one and its tickets appear here.</template>
        </p>
        <div class="mt-7 flex flex-wrap gap-3">
            <Link :href="hasSpaces ? '/spaces' : '/spaces/create'" class="btn btn-signal">
                {{ hasSpaces ? 'Go to the spaces' : 'Set up a space' }}
            </Link>
            <Link href="/docs/concept" class="btn">Read the concept</Link>
        </div>
    </template>

    <template v-else>
        <p class="eyebrow">Overview</p>
        <h1 class="display mt-3 text-5xl leading-[1.05] sm:text-6xl">
            {{ tickets.length }} open {{ tickets.length === 1 ? 'ticket' : 'tickets' }}
        </h1>

        <ul class="mt-4 flex flex-wrap gap-x-6 gap-y-1 text-sm text-muted">
            <li v-for="space in spaces" :key="space.id">
                <SpaceLabel :label="space.label" :colour="space.colour" class="text-ink" />
                <span v-if="space.sync_error" class="text-signal"> · sync failed: {{ space.sync_error }}</span>
                <span v-else-if="space.synced_at"> · synced {{ ago(space.synced_at) }}</span>
                <span v-else> · first sync on its way</span>
            </li>
        </ul>

        <section aria-label="Ticket groups" class="mt-10 grid grid-cols-2 gap-px overflow-hidden rounded-md border border-line bg-line lg:grid-cols-5">
            <div v-for="group in groups" :key="group.key" class="bg-surface p-3 last:col-span-2 sm:p-4 lg:last:col-span-1">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="font-medium">{{ group.label }}</h2>
                    <span class="font-mono text-sm" :class="inGroup[group.key].length ? 'text-ink' : 'text-muted'">
                        {{ inGroup[group.key].length }}
                    </span>
                </div>
                <p class="mt-2 hidden text-sm text-muted sm:block">{{ group.hint }}</p>
            </div>
        </section>

        <div class="mt-10 flex flex-wrap items-center gap-2">
            <button
                type="button"
                class="rounded-full border px-3 py-1 text-sm transition-colors"
                :class="spaceFilter === null ? 'border-ink bg-ink text-page' : 'border-line text-muted hover:text-ink'"
                :aria-pressed="spaceFilter === null"
                @click="spaceFilter = null"
            >
                All spaces
            </button>
            <button
                v-for="space in spaces"
                :key="space.id"
                type="button"
                class="rounded-full border px-3 py-1 text-sm transition-colors"
                :class="spaceFilter === space.id ? 'border-ink bg-ink text-page' : 'border-line text-muted hover:text-ink'"
                :aria-pressed="spaceFilter === space.id"
                @click="spaceFilter = space.id"
            >
                <SpaceLabel :label="space.label" :colour="space.colour" />
            </button>
            <label class="ml-auto flex w-full items-center gap-2 sm:w-72">
                <span class="sr-only">Search by key or summary</span>
                <input
                    ref="searchBox"
                    v-model="search"
                    type="search"
                    placeholder="Search key or summary"
                    class="w-full rounded-md border border-line bg-surface px-3 py-1.5 text-sm placeholder:text-muted"
                />
                <span class="kbd hidden sm:inline-block" aria-hidden="true">/</span>
            </label>
        </div>

        <p v-if="shown.length === 0" class="mt-10 text-muted">Nothing matches.</p>

        <template v-for="group in groups" :key="group.key">
            <section v-if="inGroup[group.key].length" class="mt-10">
                <h2 class="eyebrow">{{ group.label }} · {{ inGroup[group.key].length }}</h2>
                <ul class="card mt-3 divide-y divide-line">
                    <li v-for="ticket in inGroup[group.key]" :key="ticket.id">
                        <a
                            :href="ticket.url"
                            target="_blank"
                            rel="noopener"
                            class="grid gap-x-4 gap-y-2 p-4 transition-colors hover:bg-sunken/50 sm:grid-cols-[7rem_1fr_auto] sm:items-center"
                        >
                            <div><TicketKey :value="ticket.key" /></div>
                            <div class="min-w-0">
                                <p class="font-medium break-words">{{ ticket.summary }}</p>
                                <p class="mt-0.5 text-sm text-muted">
                                    <SpaceLabel :label="spacesById[ticket.space_id]?.label ?? ''" :colour="spacesById[ticket.space_id]?.colour" />
                                    · {{ ticket.status }}<template v-if="ticket.priority"> · {{ ticket.priority }}</template>
                                    <template v-if="ticket.reporter"> · {{ ticket.reporter }}</template>
                                    · updated {{ ago(ticket.updated_at) }}
                                </p>
                            </div>
                            <div class="flex items-center gap-3">
                                <StateBadge :state="ticket.group" />
                                <span class="text-sm whitespace-nowrap text-muted">Jira ↗</span>
                            </div>
                        </a>
                    </li>
                </ul>
            </section>
        </template>
    </template>
</template>
