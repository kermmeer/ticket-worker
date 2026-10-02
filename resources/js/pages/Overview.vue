<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import SpaceLabel from '../components/SpaceLabel.vue';
import StatusLabel from '../components/StatusLabel.vue';
import TicketRow from '../components/TicketRow.vue';
import { ago } from '../time.js';

const props = defineProps({
    hasSpaces: { type: Boolean, required: true },
    spaces: { type: Array, required: true },
    tickets: { type: Array, required: true },
});

// What each ticket needs from you (CONCEPT.md §6). Sleeping tickets wait on the
// requester; they get a segment of their own below the rest, folded away.
const groups = [
    { key: 'needs-you', label: 'Needs you', hint: 'A proposal is ready, or the agent asked you something' },
    { key: 'working', label: 'Working', hint: 'An agent is busy on it right now' },
    { key: 'new-activity', label: 'New activity', hint: 'Commented on or changed since the last analysis' },
    { key: 'not-analysed', label: 'Not analysed', hint: 'Synced, nothing started yet' },
    { key: 'parked', label: 'Parked', hint: 'Session closed, ticket still open in Jira' },
    { key: 'sleeping', label: 'Sleeping', hint: 'Waiting on the requester' },
];
const awake = groups.filter((group) => group.key !== 'sleeping');

const spaceFilter = ref(null);
const statusFilter = ref(null);
const search = ref('');
const searchBox = ref(null);

const spacesById = computed(() => Object.fromEntries(props.spaces.map((space) => [space.id, space])));

// Every status in view, with its colour and how many tickets are in it.
const statuses = computed(() => {
    const counts = new Map();
    for (const ticket of props.tickets) {
        if (spaceFilter.value !== null && ticket.space_id !== spaceFilter.value) {
            continue;
        }
        const entry = counts.get(ticket.status) ?? { status: ticket.status, tone: ticket.status_tone, count: 0 };
        entry.count++;
        counts.set(ticket.status, entry);
    }
    return [...counts.values()].sort((a, b) => b.count - a.count);
});

const shown = computed(() => {
    const query = search.value.trim().toLowerCase();
    return props.tickets.filter(
        (ticket) =>
            (spaceFilter.value === null || ticket.space_id === spaceFilter.value) &&
            (statusFilter.value === null || ticket.status === statusFilter.value) &&
            (query === '' || ticket.key.toLowerCase().includes(query) || ticket.summary.toLowerCase().includes(query)),
    );
});

const inGroup = computed(() => Object.fromEntries(groups.map((group) => [group.key, shown.value.filter((ticket) => ticket.group === group.key)])));

const sleepingCount = computed(() => props.tickets.filter((ticket) => ticket.group === 'sleeping').length);
const awakeCount = computed(() => props.tickets.length - sleepingCount.value);

// Folded unless you open it, or you are looking for something in particular.
function savedFold() {
    try {
        return localStorage.getItem('overview.sleeping') === 'open';
    } catch {
        return false;
    }
}

const sleepingOpen = ref(savedFold());
const showSleeping = computed(() => sleepingOpen.value || statusFilter.value !== null || search.value.trim() !== '');

function toggleSleeping() {
    sleepingOpen.value = !sleepingOpen.value;
    try {
        localStorage.setItem('overview.sleeping', sleepingOpen.value ? 'open' : 'closed');
    } catch {
        // Storage refused: it still works for this visit.
    }
}

function pickStatus(status) {
    statusFilter.value = statusFilter.value === status ? null : status;
}

function pickSpace(id) {
    spaceFilter.value = id;
    statusFilter.value = null;
}

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
            {{ awakeCount }} open {{ awakeCount === 1 ? 'ticket' : 'tickets' }}
        </h1>

        <ul class="mt-4 flex flex-wrap gap-x-6 gap-y-1 text-sm text-muted">
            <li v-if="sleepingCount">and {{ sleepingCount }} sleeping, waiting on the requester</li>
            <li v-for="space in spaces" :key="space.id">
                <SpaceLabel :label="space.label" :colour="space.colour" class="text-ink" />
                <span v-if="space.sync_error" class="text-signal"> · sync failed: {{ space.sync_error }}</span>
                <span v-else-if="space.synced_at"> · synced {{ ago(space.synced_at) }}</span>
                <span v-else> · first sync on its way</span>
            </li>
        </ul>

        <section aria-label="Ticket groups" class="mt-10 grid grid-cols-2 gap-px overflow-hidden rounded-md border border-line bg-line sm:grid-cols-3 xl:grid-cols-6">
            <div v-for="group in groups" :key="group.key" class="bg-surface p-3 sm:p-4">
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
                @click="pickSpace(null)"
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
                @click="pickSpace(space.id)"
            >
                <SpaceLabel :label="space.label" :colour="space.colour" />
            </button>
            <label class="ml-auto flex w-full items-center gap-2 sm:w-80">
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

        <!-- One status at a time: click it again, or "Every status", to see them all. -->
        <div class="mt-3 flex flex-wrap items-center gap-1.5" role="group" aria-label="Show only one status">
            <button
                type="button"
                class="rounded-[3px] border px-1.5 font-mono text-[0.66rem] leading-5 font-medium tracking-wide uppercase transition-colors"
                :class="statusFilter === null ? 'border-ink bg-ink text-page' : 'border-line text-muted hover:text-ink'"
                :aria-pressed="statusFilter === null"
                @click="statusFilter = null"
            >
                Every status
            </button>
            <button
                v-for="entry in statuses"
                :key="entry.status"
                type="button"
                class="rounded-[3px]"
                :aria-pressed="statusFilter === entry.status"
                @click="pickStatus(entry.status)"
            >
                <StatusLabel :status="entry.status" :tone="entry.tone" :filled="statusFilter === entry.status">
                    <span class="ml-1.5 opacity-75">{{ entry.count }}</span>
                </StatusLabel>
            </button>
        </div>

        <p v-if="shown.length === 0" class="mt-10 text-muted">Nothing matches.</p>

        <template v-for="group in awake" :key="group.key">
            <section v-if="inGroup[group.key].length" class="mt-10">
                <h2 class="eyebrow">{{ group.label }} · {{ inGroup[group.key].length }}</h2>
                <ul class="card mt-3 divide-y divide-line">
                    <li v-for="ticket in inGroup[group.key]" :key="ticket.id">
                        <TicketRow :ticket="ticket" :space="spacesById[ticket.space_id]" />
                    </li>
                </ul>
            </section>
        </template>

        <section v-if="inGroup.sleeping.length" class="mt-14 border-t border-line pt-8">
            <button type="button" class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-left" :aria-expanded="showSleeping" @click="toggleSleeping">
                <h2 class="eyebrow">Sleeping · {{ inGroup.sleeping.length }}</h2>
                <span class="text-sm text-muted">
                    Waiting on the requester ·
                    <span class="underline decoration-line underline-offset-2">{{ showSleeping ? 'fold away' : 'show them' }}</span>
                </span>
            </button>
            <ul v-if="showSleeping" class="card mt-3 divide-y divide-line">
                <li v-for="ticket in inGroup.sleeping" :key="ticket.id">
                    <TicketRow :ticket="ticket" :space="spacesById[ticket.space_id]" />
                </li>
            </ul>
        </section>
    </template>
</template>
