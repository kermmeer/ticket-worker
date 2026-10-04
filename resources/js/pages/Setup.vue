<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    checks: { type: Array, required: true },
    replyRules: { type: String, required: true },
    defaultReplyRules: { type: String, required: true },
});

const rules = useForm({ rules: props.replyRules });

function saveRules() {
    rules.put('/setup/reply-rules', { preserveScroll: true });
}

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

    <section class="mt-10">
        <h2 class="eyebrow">Replies</h2>
        <form class="card mt-3 p-5" @submit.prevent="saveRules">
            <label for="rules" class="font-medium">How replies to reporters are written</label>
            <p class="mt-1 max-w-3xl text-sm text-muted">
                Every reply draft the agent writes follows these rules, in the reply's own language. A change applies to the
                next draft, also in a conversation already going.
            </p>
            <textarea id="rules" v-model="rules.rules" rows="8" class="mt-3 w-full rounded-md border border-line bg-page px-3 py-2 text-sm"></textarea>
            <p v-if="rules.errors.rules" class="mt-1 text-sm text-signal">{{ rules.errors.rules }}</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="submit" class="btn btn-signal" :disabled="rules.processing || !rules.isDirty">Save</button>
                <button type="button" class="btn" @click="rules.rules = defaultReplyRules">Back to the default</button>
            </div>
        </form>
    </section>
</template>
