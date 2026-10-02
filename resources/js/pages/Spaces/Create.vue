<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    projects: { type: Array, required: true },
    error: { type: String, default: null },
});

const filter = ref('');

const shown = computed(() => {
    const query = filter.value.trim().toLowerCase();
    return query === ''
        ? props.projects
        : props.projects.filter((project) => project.key.toLowerCase().includes(query) || project.name.toLowerCase().includes(query));
});

const form = useForm({ project_key: '' });

function pick(project) {
    form.project_key = project.key;
    form.post('/spaces');
}
</script>

<template>
    <Head title="Add a space" />

    <p class="eyebrow"><Link href="/spaces" class="hover:text-ink">Spaces</Link> / New</p>
    <h1 class="display mt-3 text-5xl leading-[1.05] sm:text-6xl">Which space?</h1>
    <p class="mt-5 max-w-2xl text-lg text-muted">
        The spaces this Jira account can see, minus the ones already set up. A service space offers both a reply to the
        customer and an internal note; Jira says which kind each one is.
    </p>

    <p v-if="error" class="mt-8 rounded-md border border-signal/40 bg-surface px-4 py-3 text-sm text-signal">
        {{ error }} <Link href="/setup" class="underline">See Setup.</Link>
    </p>
    <p v-if="form.errors.project_key" class="mt-8 rounded-md border border-signal/40 bg-surface px-4 py-3 text-sm text-signal">
        {{ form.errors.project_key }}
    </p>

    <template v-if="!error">
        <label class="mt-8 block max-w-sm">
            <span class="sr-only">Filter spaces</span>
            <input
                v-model="filter"
                type="search"
                placeholder="Filter by name or key"
                class="w-full rounded-md border border-line bg-surface px-3 py-2 text-sm placeholder:text-muted"
            />
        </label>

        <p v-if="projects.length === 0" class="mt-8 text-muted">Every space this account can see is set up already.</p>

        <ul class="card mt-6 divide-y divide-line">
            <li v-for="project in shown" :key="project.key" class="flex flex-wrap items-center gap-x-4 gap-y-2 p-4">
                <span class="font-mono text-sm">{{ project.key }}</span>
                <span class="min-w-0 flex-1 font-medium">{{ project.name }}</span>
                <span class="text-xs text-muted">{{ project.service ? 'Service space' : 'Plain space' }}</span>
                <button type="button" class="btn px-3 py-1.5" :disabled="form.processing" @click="pick(project)">Set up</button>
            </li>
        </ul>
    </template>
</template>
