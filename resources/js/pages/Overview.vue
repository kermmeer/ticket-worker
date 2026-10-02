<script setup>
import { Head, Link } from '@inertiajs/vue3';

// The groups the overview will sort tickets into (CONCEPT.md §6), shown empty until
// the first space syncs, so the page already says what it is going to do.
const groups = [
    { label: 'Needs you', hint: 'A proposal is ready, or the agent asked you something' },
    { label: 'Working', hint: 'An agent is busy on it right now' },
    { label: 'New activity', hint: 'Commented on or changed since the last analysis' },
    { label: 'Not analysed', hint: 'Synced, nothing started yet' },
    { label: 'Parked', hint: 'Session closed, ticket still open in Jira' },
];
</script>

<template>
    <Head title="Overview" />

    <p class="eyebrow">Overview</p>
    <h1 class="display mt-3 text-5xl leading-[1.05] sm:text-6xl">No cases yet.</h1>
    <p class="mt-5 max-w-2xl text-lg text-muted">
        Ticket Worker lists the open tickets of the Jira spaces you set up, grouped by what they need from you.
        Nothing is set up yet: the Jira connection and the first space come next.
    </p>

    <div class="mt-7 flex flex-wrap gap-3">
        <Link href="/setup" class="btn btn-signal">See what is set up</Link>
        <Link href="/docs/concept" class="btn">Read the concept</Link>
    </div>

    <section aria-label="Ticket groups" class="mt-14 grid gap-px overflow-hidden rounded-md border border-line bg-line sm:grid-cols-2 lg:grid-cols-5">
        <div v-for="group in groups" :key="group.label" class="bg-surface p-4">
            <div class="flex items-baseline justify-between gap-3">
                <h2 class="font-medium">{{ group.label }}</h2>
                <span class="font-mono text-sm text-muted">0</span>
            </div>
            <p class="mt-2 text-sm text-muted">{{ group.hint }}</p>
        </div>
    </section>
</template>
