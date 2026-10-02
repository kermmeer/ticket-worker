<script setup>
defineProps({
    // What the agent is doing right now, and what it has done so far this turn.
    now: { type: String, required: true },
    tally: { type: String, default: '' },
});
</script>

<template>
    <p class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5 font-mono text-xs text-working" role="status">
        <span class="activity-dot" aria-hidden="true"></span>
        <span class="min-w-0 [overflow-wrap:anywhere]">{{ now }}</span>
        <span v-if="tally" class="text-muted">· {{ tally }}</span>
    </p>
</template>
