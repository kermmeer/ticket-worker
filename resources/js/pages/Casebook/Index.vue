<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ago } from '../../time.js';

const props = defineProps({
    entries: { type: Array, required: true },
});

const states = {
    approved: { label: 'Approved', tone: 'text-done' },
    draft: { label: 'Draft', tone: 'text-waiting' },
    retired: { label: 'Retired', tone: 'text-muted' },
};

const stateFilter = ref(null);
const search = ref('');

const shown = computed(() => {
    const query = search.value.trim().toLowerCase();
    return props.entries.filter(
        (entry) =>
            (stateFilter.value === null || entry.state === stateFilter.value) &&
            (query === '' || `${entry.title} ${entry.symptoms} ${entry.source_tickets.join(' ')}`.toLowerCase().includes(query)),
    );
});
</script>

<template>
    <Head title="Casebook" />

    <p class="eyebrow">Casebook</p>
    <h1 class="display mt-3 text-5xl leading-[1.05] sm:text-6xl">Solved before.</h1>
    <p class="mt-5 max-w-2xl text-lg text-muted">
        Each case is a problem as tickets show it, its cause, and what fixed it. Every sync matches open tickets against
        the approved cases, and the agents will read them before they read any code: a known fix costs a fraction of a
        fresh investigation.
    </p>

    <div class="mt-8 flex flex-wrap items-center gap-2">
        <Link href="/casebook/create" class="btn btn-signal">Write a case</Link>
        <span class="mx-2 hidden h-5 border-l border-line sm:block"></span>
        <button
            v-for="(state, key) in { all: { label: 'All' }, ...states }"
            :key="key"
            type="button"
            class="rounded-full border px-3 py-1 text-sm transition-colors"
            :class="(key === 'all' ? stateFilter === null : stateFilter === key) ? 'border-ink bg-ink text-page' : 'border-line text-muted hover:text-ink'"
            @click="stateFilter = key === 'all' ? null : key"
        >
            {{ state.label }}
        </button>
        <label class="ml-auto flex w-full items-center sm:w-80">
            <span class="sr-only">Search the casebook</span>
            <input v-model="search" type="search" placeholder="Search title, symptoms, ticket" class="w-full rounded-md border border-line bg-surface px-3 py-1.5 text-sm placeholder:text-muted" />
        </label>
    </div>

    <p v-if="entries.length === 0" class="mt-10 max-w-2xl text-muted">
        Nothing yet. Write down a problem you solved more than once, or start one from a ticket in the overview with
        <span class="text-ink">Write it up</span>.
    </p>
    <p v-else-if="shown.length === 0" class="mt-10 text-muted">Nothing matches.</p>

    <ul class="mt-8 space-y-3">
        <li v-for="entry in shown" :key="entry.id" class="card p-5">
            <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                <Link :href="`/casebook/${entry.id}/edit`" class="text-lg font-medium hover:underline">{{ entry.title }}</Link>
                <span v-if="entry.system" class="font-mono text-xs text-muted">{{ entry.system }}</span>
                <span class="ml-auto font-mono text-xs font-medium tracking-wide uppercase" :class="states[entry.state]?.tone">
                    {{ states[entry.state]?.label ?? entry.state }}
                </span>
            </div>
            <p class="mt-2 max-w-4xl text-sm text-muted">{{ entry.symptoms }}</p>
            <p class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                <span v-if="entry.state === 'approved'">matches {{ entry.open_matches }} open {{ entry.open_matches === 1 ? 'ticket' : 'tickets' }}</span>
                <span>used by agents {{ entry.used_count }} {{ entry.used_count === 1 ? 'time' : 'times' }}</span>
                <span v-if="entry.source_tickets.length" class="font-mono">from {{ entry.source_tickets.join(', ') }}</span>
                <span>written by {{ entry.written_by }}, updated {{ ago(entry.updated_at) }}</span>
            </p>
        </li>
    </ul>
</template>
