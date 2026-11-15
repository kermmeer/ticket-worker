<script setup>
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import StatusLabel from './StatusLabel.vue';
import TicketKey from './TicketKey.vue';
import TicketRow from './TicketRow.vue';

// Your tickets, the outbox's way: your next ten on top, then yours by where they are in Jira.
// Yours: assigned to you, a chat of yours, or your own work. What needs you is a badge on the row.
const props = defineProps({
    tickets: { type: Array, required: true },
    nextTen: { type: Array, default: () => [] },
    spacesById: { type: Object, required: true },
});

const stored = (key, fallback) => {
    try {
        const value = localStorage.getItem(key);
        return value === null ? fallback : JSON.parse(value);
    } catch {
        return fallback;
    }
};
const hideWaiting = ref(stored('mine.hide-waiting', false));
watch(hideWaiting, (value) => {
    try {
        localStorage.setItem('mine.hide-waiting', JSON.stringify(value));
    } catch {
        // fine
    }
});
const search = ref('');

const byId = computed(() => Object.fromEntries(props.tickets.map((ticket) => [ticket.id, ticket])));
// Ranked deeper than ten: the first ten of the spaces picked (the tickets given are theirs).
const next = computed(() => props.nextTen.map((entry) => ({ ...entry, ticket: byId.value[entry.id] })).filter((entry) => entry.ticket).slice(0, 10));
const mine = computed(() => {
    const words = search.value.toLowerCase().split(/\s+/).filter(Boolean);
    return props.tickets.filter((ticket) => (ticket.mine || ticket.on_it === 'you' || ticket.local) && ticket.group !== 'hidden')
        .filter((ticket) => {
            const text = `${ticket.key} ${ticket.summary} ${ticket.status ?? ''} ${ticket.issue_type ?? ''}`.toLowerCase();
            return words.every((word) => text.includes(word));
        });
});
const sections = computed(() => [
    { key: 'progress', label: 'In progress', list: mine.value.filter((t) => t.group !== 'sleeping' && t.status_category === 'indeterminate') },
    { key: 'todo', label: 'To do', list: mine.value.filter((t) => t.group !== 'sleeping' && t.status_category !== 'indeterminate') },
    { key: 'waiting', label: 'Waiting on someone else', list: hideWaiting.value ? [] : mine.value.filter((t) => t.group === 'sleeping') },
]);
const waitingCount = computed(() => mine.value.filter((t) => t.group === 'sleeping').length);
</script>

<template>
    <!-- Your next ten, each with why. -->
    <section v-if="next.length" class="card mt-6 p-4" aria-labelledby="next-title">
        <h2 id="next-title" class="eyebrow">Your next {{ next.length }}</h2>
        <ol class="mt-2 divide-y divide-line">
            <li v-for="entry in next" :key="entry.id" class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2 text-sm">
                <Link :href="entry.ticket.page" class="shrink-0"><TicketKey :value="entry.ticket.key" /></Link>
                <Link :href="entry.ticket.page" class="min-w-0 flex-1 break-words hover:underline">{{ entry.ticket.summary }}</Link>
                <StatusLabel :status="entry.ticket.status" :tone="entry.ticket.status_tone" />
                <span class="w-full text-xs text-working sm:w-auto">{{ entry.reason }}</span>
            </li>
        </ol>
    </section>

    <div class="mt-6 flex flex-wrap items-center gap-3">
        <label class="min-w-0 flex-1">
            <span class="sr-only">Filter your tickets</span>
            <input v-model="search" type="search" placeholder="Filter: key, words, status" class="w-full max-w-md rounded-md border border-line bg-surface px-3 py-2 text-sm" />
        </label>
        <label class="flex items-center gap-2 text-sm text-muted">
            <input v-model="hideWaiting" type="checkbox" /> Hide tickets waiting on someone else<template v-if="hideWaiting && waitingCount"> ({{ waitingCount }})</template>
        </label>
    </div>

    <p v-if="!mine.length" class="mt-8 text-muted">
        {{ search ? 'Nothing of yours matches.' : 'Nothing assigned to you, no chat of yours open. Every ticket is under All.' }}
    </p>
    <section v-for="section in sections.filter((s) => s.list.length)" :key="section.key" class="mt-6">
        <h2 class="eyebrow">{{ section.label }} · {{ section.list.length }}</h2>
        <ul class="card mt-2 divide-y divide-line">
            <li v-for="ticket in section.list" :key="ticket.id">
                <TicketRow :ticket="ticket" :space="spacesById[ticket.space_id]" />
            </li>
        </ul>
    </section>
</template>
