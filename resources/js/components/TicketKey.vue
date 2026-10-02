<script setup>
defineProps({
    value: { type: String, required: true },
});
</script>

<template>
    <!-- Stamped, in mono: a key is something you copy and match, not something you read past. -->
    <span class="inline-block rounded-[3px] border border-current px-1.5 font-mono text-[0.72rem] leading-5 font-medium tracking-wider whitespace-nowrap text-ink">
        {{ value }}
    </span>
</template>
