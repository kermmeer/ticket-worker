<script setup>
import { Head } from '@inertiajs/vue3';
import { short } from '../time.js';

defineProps({ events: { type: Array, required: true } });
</script>

<template>
    <Head title="Audit trail" />
    <p class="eyebrow">Audit trail</p>
    <h1 class="display mt-2 text-3xl">Who changed what</h1>
    <p class="mt-2 max-w-3xl text-sm text-muted">Changes to spaces, projects, APIs and databases, the casebook, the rules and the people. A secret shows only as "changed", never its value.</p>
    <p v-if="events.length === 0" class="mt-8 text-muted">Nothing changed yet.</p>
    <ul v-else class="card mt-8 divide-y divide-line">
        <li v-for="event in events" :key="event.id" class="grid gap-x-4 gap-y-0.5 p-3 text-sm sm:grid-cols-[10rem_9rem_minmax(0,1fr)]">
            <span class="font-mono text-xs text-muted">{{ short(event.at) }}</span>
            <span>{{ event.who }}</span>
            <span><span class="font-mono text-xs text-muted">{{ event.action }}</span> <span v-if="event.subject" class="font-medium">{{ event.subject }}</span> · {{ event.summary }}</span>
        </li>
    </ul>
</template>
