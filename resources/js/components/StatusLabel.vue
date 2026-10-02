<script setup>
defineProps({
    status: { type: String, default: '' },
    // One of the --tone-* colours in app.css.
    tone: { type: String, default: 'grey' },
    filled: { type: Boolean, default: false },
});
</script>

<template>
    <!-- The same stamp as a ticket key, in the status's colour. The colour helps; the word decides. -->
    <span
        class="inline-block max-w-full rounded-[3px] border px-1.5 font-mono text-[0.66rem] leading-5 font-medium tracking-wide break-words uppercase"
        :style="filled ? { background: `var(--tone-${tone})`, borderColor: `var(--tone-${tone})`, color: 'var(--page)' } : { color: `var(--tone-${tone})`, borderColor: 'currentColor' }"
    >
        {{ status || 'No status' }}<slot />
    </span>
</template>
