<script setup>
defineProps({
    time: { type: String, required: true },
    // "agent" or "you". The two differ by the colour of the rule and the word in the margin.
    author: { type: String, default: 'agent' },
});
</script>

<template>
    <article class="grid grid-cols-[3.5rem_1fr] gap-x-3 sm:grid-cols-[4.5rem_1fr] sm:gap-x-4">
        <div class="pt-0.5 text-right font-mono text-[0.7rem] leading-5">
            <div class="text-muted">{{ time }}</div>
            <div :class="author === 'agent' ? 'text-working' : 'text-ink'">{{ author === 'agent' ? 'agent' : 'you' }}</div>
        </div>
        <div class="min-w-0 space-y-2 border-l-2 pb-1 pl-4" :class="author === 'agent' ? 'border-working/50' : 'border-ink/40'">
            <slot />
        </div>
    </article>
</template>
