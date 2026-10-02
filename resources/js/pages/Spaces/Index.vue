<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import SpaceLabel from '../../components/SpaceLabel.vue';
import { ago } from '../../time.js';

defineProps({
    jiraConfigured: { type: Boolean, required: true },
    spaces: { type: Array, required: true },
});

const states = {
    draft: { label: 'Draft', tone: 'text-muted' },
    active: { label: 'Active', tone: 'text-done' },
    paused: { label: 'Paused', tone: 'text-waiting' },
};

function post(space, action) {
    router.post(`/spaces/${space.id}/${action}`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Spaces" />

    <p class="eyebrow">Spaces</p>
    <h1 class="display mt-3 text-5xl leading-[1.05] sm:text-6xl">The Jira spaces it watches.</h1>
    <p class="mt-5 max-w-2xl text-lg text-muted">
        Each space is set up once: which of its tickets count, and which systems they are about. Only active spaces
        sync.
    </p>

    <p v-if="!jiraConfigured" class="mt-8 rounded-md border border-signal/40 bg-surface px-4 py-3 text-sm text-signal">
        Jira is not connected yet. <Link href="/setup" class="underline">See Setup.</Link>
    </p>

    <div class="mt-8">
        <Link href="/spaces/create" class="btn btn-signal">Add a space</Link>
    </div>

    <p v-if="spaces.length === 0" class="mt-10 text-muted">No spaces yet.</p>

    <ul class="mt-10 space-y-4">
        <li v-for="space in spaces" :key="space.id" class="card p-5">
            <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                <h2 class="text-lg font-medium"><SpaceLabel :label="space.label" :colour="space.colour" /></h2>
                <span class="text-sm text-muted">{{ space.name }}</span>
                <span class="font-mono text-xs text-muted">{{ space.project_key }}</span>
                <span class="text-xs text-muted">{{ space.type === 'service' ? 'Service space' : 'Plain space' }}</span>
                <span class="ml-auto font-mono text-xs font-medium tracking-wide uppercase" :class="states[space.state]?.tone">
                    {{ states[space.state]?.label ?? space.state }}
                </span>
            </div>

            <p class="mt-3 text-sm">
                <span>{{ space.open_count }} open {{ space.open_count === 1 ? 'ticket' : 'tickets' }}</span>
                <span v-if="space.sync_error" class="text-signal"> · sync failed: {{ space.sync_error }}</span>
                <span v-else-if="space.synced_at" class="text-muted"> · synced {{ ago(space.synced_at) }}</span>
                <span v-else-if="space.state === 'active'" class="text-muted"> · first sync on its way</span>
            </p>

            <div class="mt-4 flex flex-wrap gap-2">
                <Link :href="`/spaces/${space.id}/edit`" class="btn px-3 py-1.5">Set up</Link>
                <button v-if="space.state === 'active'" type="button" class="btn px-3 py-1.5" @click="post(space, 'sync')">Sync now</button>
                <button v-if="space.state === 'active'" type="button" class="btn px-3 py-1.5" @click="post(space, 'pause')">Pause</button>
                <button v-else type="button" class="btn px-3 py-1.5" @click="post(space, 'activate')">Activate</button>
            </div>
        </li>
    </ul>
</template>
