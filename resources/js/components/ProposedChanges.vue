<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

// Changes the agent proposed through an API that allows them after approval. Nothing is sent
// until an editor presses Run it; refused ones are told to the agent, so it does not ask again.
const props = defineProps({
    ticketId: { type: Number, required: true },
    changes: { type: Array, required: true },
});
const page = usePage();
const mayRun = computed(() => page.props.me?.is_editor);
const waiting = computed(() => props.changes.filter((change) => change.state === 'waiting'));
const decided = computed(() => props.changes.filter((change) => change.state !== 'waiting'));

function run(change) {
    if (confirm(`Run ${change.method} ${change.path} on ${change.api}? This changes data in that system.`)) {
        router.post(`/tickets/${props.ticketId}/changes/${change.id}/run`, {}, { preserveScroll: true });
    }
}
function refuse(change) {
    router.post(`/tickets/${props.ticketId}/changes/${change.id}/refuse`, {}, { preserveScroll: true });
}
const pretty = (value) => JSON.stringify(value, null, 2);
const states = { done: { label: 'run', tone: 'text-done' }, failed: { label: 'failed', tone: 'text-signal' }, refused: { label: 'refused', tone: 'text-muted' }, running: { label: 'running…', tone: 'text-working' } };
</script>

<template>
    <div v-if="changes.length" class="space-y-3">
        <div v-for="change in waiting" :key="change.id" class="rounded-md border border-waiting/50 p-3 text-sm">
            <p class="text-xs font-medium tracking-wide text-waiting uppercase">A change the agent proposes · waiting for you</p>
            <p class="mt-1.5 font-mono text-xs break-all"><span class="font-semibold">{{ change.method }}</span> {{ change.path }} <span class="text-muted">on {{ change.api }}</span></p>
            <p v-if="change.reason" class="mt-1.5">{{ change.reason }}</p>
            <pre v-if="change.query" class="mt-2 overflow-x-auto rounded-md border border-line bg-sunken p-2 font-mono text-xs">query {{ pretty(change.query) }}</pre>
            <pre v-if="change.body" class="mt-2 max-h-64 overflow-auto rounded-md border border-line bg-sunken p-2 font-mono text-xs">{{ pretty(change.body) }}</pre>
            <p class="mt-3 flex flex-wrap items-center gap-2">
                <button v-if="mayRun" type="button" class="btn btn-signal" @click="run(change)">Run it</button>
                <button type="button" class="btn" @click="refuse(change)">Refuse</button>
                <span v-if="!mayRun" class="text-xs text-muted">Running a change is for editors and admins.</span>
                <span class="text-xs text-muted">Nothing has been sent yet.</span>
            </p>
        </div>
        <details v-if="decided.length" class="text-sm">
            <summary class="cursor-pointer text-muted hover:text-ink">Changes decided · {{ decided.length }}</summary>
            <ul class="mt-2 space-y-2">
                <li v-for="change in decided" :key="change.id" class="rounded-md border border-line p-2">
                    <p class="font-mono text-xs break-all">
                        <span class="font-semibold">{{ change.method }}</span> {{ change.path }} <span class="text-muted">on {{ change.api }}</span>
                        · <span :class="states[change.state]?.tone">{{ states[change.state]?.label ?? change.state }}<template v-if="change.status"> (HTTP {{ change.status }})</template></span>
                        <span v-if="change.decided_by" class="text-muted"> · by {{ change.decided_by }}</span>
                    </p>
                    <pre v-if="change.response" class="mt-1.5 max-h-48 overflow-auto rounded-md border border-line bg-sunken p-2 font-mono text-xs">{{ change.response }}</pre>
                </li>
            </ul>
        </details>
    </div>
</template>
