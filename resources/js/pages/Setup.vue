<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    checks: { type: Array, required: true },
});

const groups = computed(() => {
    const byName = new Map();
    for (const check of props.checks) {
        if (!byName.has(check.group)) {
            byName.set(check.group, []);
        }
        byName.get(check.group).push(check);
    }
    return [...byName].map(([name, checks]) => ({ name, checks }));
});

const done = computed(() => props.checks.filter((check) => check.state === 'ok').length);

const marks = {
    ok: { label: 'Done', tone: 'text-done' },
    todo: { label: 'To do', tone: 'text-signal' },
    note: { label: 'Note', tone: 'text-waiting' },
};
</script>

<template>
    <Head title="Setup" />

    <p class="eyebrow">Setup</p>
    <h1 class="display mt-3 text-5xl leading-[1.05] sm:text-6xl">{{ done }} of {{ checks.length }} in place.</h1>
    <p class="mt-5 max-w-2xl text-lg text-muted">
        What Ticket Worker needs before it can read tickets and run agents. Secrets show as set or not set, never as
        their value.
    </p>

    <section v-for="group in groups" :key="group.name" class="mt-10">
        <h2 class="eyebrow">{{ group.name }}</h2>
        <ul class="mt-3 divide-y divide-line rounded-md border border-line bg-surface">
            <li v-for="check in group.checks" :key="check.key" class="grid grid-cols-[4.5rem_1fr] gap-x-4 p-4">
                <span class="pt-0.5 font-mono text-xs font-medium tracking-wide uppercase" :class="marks[check.state].tone">
                    {{ marks[check.state].label }}
                </span>
                <div class="min-w-0">
                    <p class="font-medium">{{ check.title }}</p>
                    <p class="mt-1 text-sm break-words text-muted">{{ check.detail }}</p>
                </div>
            </li>
        </ul>
    </section>
</template>
