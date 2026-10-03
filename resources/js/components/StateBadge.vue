<script setup>
import { computed } from 'vue';

const props = defineProps({
    state: { type: String, required: true },
});

// Every state is a word and a shape as well as a colour: colour alone tells nothing
// to someone who cannot tell these apart, or to a screen in bright sunlight.
const states = {
    'needs-you': { label: 'Needs you', tone: 'text-signal', shape: 'dot' },
    'new-activity': { label: 'New activity', tone: 'text-signal', shape: 'spark' },
    working: { label: 'Working', tone: 'text-working', shape: 'half' },
    sleeping: { label: 'Sleeping', tone: 'text-waiting', shape: 'moon' },
    'not-analysed': { label: 'Not analysed', tone: 'text-muted', shape: 'ring' },
    parked: { label: 'Parked', tone: 'text-muted', shape: 'pause' },
    hidden: { label: 'Hidden', tone: 'text-muted', shape: 'ring' },
    closed: { label: 'Closed', tone: 'text-done', shape: 'check' },
};

const current = computed(() => states[props.state] ?? states['not-analysed']);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full border border-current/35 px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="current.tone"
    >
        <svg aria-hidden="true" viewBox="0 0 12 12" class="size-3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <circle v-if="current.shape === 'dot'" cx="6" cy="6" r="3.5" fill="currentColor" stroke="none" />
            <circle v-else-if="current.shape === 'ring'" cx="6" cy="6" r="3.5" />
            <template v-else-if="current.shape === 'half'">
                <circle cx="6" cy="6" r="4" />
                <path d="M6 2a4 4 0 0 1 0 8z" fill="currentColor" stroke="none" />
            </template>
            <path v-else-if="current.shape === 'moon'" d="M9.5 7.6A4 4 0 0 1 4.4 2.5 4 4 0 1 0 9.5 7.6z" fill="currentColor" stroke="none" />
            <path v-else-if="current.shape === 'pause'" d="M4.5 3v6M7.5 3v6" />
            <path v-else-if="current.shape === 'check'" d="M2.5 6.5 5 9l4.5-6" />
            <path v-else d="M6 1.5v9M1.5 6h9M2.8 2.8l6.4 6.4M9.2 2.8l-6.4 6.4" />
        </svg>
        {{ current.label }}
    </span>
</template>
