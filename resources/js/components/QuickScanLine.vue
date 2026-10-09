<script setup>
// A ticket's quick scan in one line of the overview: what it is, and for a data problem
// whether it is still there. Words with every colour.
defineProps({ scan: { type: Object, required: true } });

const kinds = { bug: 'bug', data: 'data', configuration: 'config', question: 'question', feature: 'feature', unknown: 'unclear' };
const still = {
    yes: { label: 'still there', tone: 'text-signal' },
    no: { label: 'already gone', tone: 'text-done' },
    unknown: { label: 'not checked', tone: 'text-muted' },
};
</script>

<template>
    <p v-if="scan.state === 'queued' || scan.state === 'running'" class="mt-1.5 text-sm text-working">Quick scan…</p>
    <p v-else-if="scan.state === 'pending'" class="mt-1.5 text-sm text-muted">Quick scan waiting its turn</p>
    <p v-else-if="scan.state === 'failed'" class="mt-1.5 text-sm text-muted">Quick scan failed</p>
    <p v-else-if="scan.result" class="mt-1.5 flex flex-wrap items-baseline gap-x-1.5 text-sm">
        <span class="rounded-[3px] border border-line px-1.5 font-mono text-[0.66rem] leading-5 tracking-wide text-muted uppercase">{{ kinds[scan.result.kind] ?? scan.result.kind }}</span>
        <span v-if="scan.result.system && scan.result.system !== 'unknown'" class="font-mono text-xs text-muted">{{ scan.result.system }}</span>
        <span class="min-w-0">{{ scan.result.summary }}</span>
        <span v-if="scan.result.data_check" class="font-medium" :class="still[scan.result.data_check.still_present]?.tone">
            · {{ still[scan.result.data_check.still_present]?.label }}
        </span>
    </p>
</template>
