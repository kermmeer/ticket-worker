<script setup>
defineProps({
    // What the chip points at: a file (with a line), a commit, or a quote from the ticket.
    kind: { type: String, default: 'code' },
    label: { type: String, required: true },
});
</script>

<template>
    <button
        type="button"
        class="inline-flex max-w-full items-center gap-1.5 rounded-sm text-left border border-line bg-sunken px-1.5 py-0.5 font-mono text-xs text-ink transition-colors hover:border-signal"
    >
        <svg aria-hidden="true" viewBox="0 0 12 12" class="size-3 shrink-0 text-muted" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round">
            <template v-if="kind === 'commit'">
                <circle cx="6" cy="6" r="2" />
                <path d="M1 6h3M8 6h3" />
            </template>
            <path v-else-if="kind === 'quote'" d="M2.5 4.5h3v3l-1.5 2M6.5 4.5h3v3l-1.5 2" />
            <path v-else d="M3 1.5h4l2.5 2.5v6.5H3zM7 1.5V4h2.5" />
        </svg>
        <span class="min-w-0 [overflow-wrap:anywhere]">{{ label }}</span>
    </button>
</template>
