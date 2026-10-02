<script setup>
defineProps({
    label: { type: String, required: true },
    colour: { type: String, default: 'slate' },
});
</script>

<template>
    <span class="inline-flex items-center gap-1.5 whitespace-nowrap">
        <span class="size-2 shrink-0 rounded-full" :style="{ background: `var(--space-${colour})` }" aria-hidden="true"></span>
        {{ label }}
    </span>
</template>
