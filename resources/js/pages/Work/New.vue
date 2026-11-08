<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import SpaceLabel from '../../components/SpaceLabel.vue';

// Something you saw yourself: start on it now, make it a Jira ticket when it is ready.
const props = defineProps({
    spaces: { type: Array, required: true },
    types: { type: Array, required: true },
});

const stored = (() => {
    try {
        return Number(localStorage.getItem('work.space')) || null;
    } catch {
        return null;
    }
})();
const form = useForm({
    space_id: props.spaces.find((space) => space.id === stored)?.id ?? props.spaces[0]?.id ?? null,
    title: '',
    description: '',
    type: 'Improvement',
});
function start() {
    try {
        localStorage.setItem('work.space', String(form.space_id));
    } catch {
        // fine
    }
    form.post('/work');
}
const field = 'mt-1 w-full rounded-md border border-line bg-page px-3 py-2 text-sm placeholder:text-muted';
</script>

<template>
    <Head title="New work" />
    <p class="eyebrow"><Link href="/" class="hover:text-ink">Overview</Link> / New work</p>
    <h1 class="display mt-3 text-3xl">Something you saw yourself</h1>
    <p class="mt-2 max-w-2xl text-muted">
        Start on it now, without a ticket: ask, refine and build it like any other. When it is ready, the agent drafts the
        Jira ticket from what was done, your screenshots in it, and the work moves onto that ticket.
    </p>

    <form class="card mt-8 max-w-2xl space-y-4 p-5" @submit.prevent="start">
        <fieldset>
            <legend class="text-sm">Space <span class="text-muted">(its systems and, later, its Jira project)</span></legend>
            <div class="mt-2 flex flex-wrap gap-2">
                <label
                    v-for="space in spaces"
                    :key="space.id"
                    class="flex cursor-pointer items-center gap-2 rounded-full border px-3 py-1 text-sm"
                    :class="form.space_id === space.id ? 'border-ink' : 'border-line text-muted'"
                >
                    <input v-model="form.space_id" type="radio" :value="space.id" class="sr-only" />
                    <SpaceLabel :label="space.label" :colour="space.colour" />
                </label>
            </div>
        </fieldset>
        <label class="block text-sm">What is it <span class="text-muted">(a working title; the ticket's comes later)</span>
            <input v-model="form.title" required maxlength="200" placeholder="The export misses the CSV option" :class="field" />
        </label>
        <label class="block text-sm">What you are after <span class="text-muted">(optional: what you saw, what it should do)</span>
            <textarea v-model="form.description" rows="6" :class="field"></textarea>
        </label>
        <label class="block text-sm">Kind
            <select v-model="form.type" :class="field" class="max-w-48">
                <option v-for="type in types" :key="type" :value="type">{{ type }}</option>
            </select>
        </label>
        <p v-if="Object.keys(form.errors).length" class="text-sm text-signal">{{ Object.values(form.errors)[0] }}</p>
        <div class="flex gap-2">
            <button type="submit" class="btn btn-signal" :disabled="form.processing || !form.title.trim() || !form.space_id">Start</button>
            <Link href="/" class="btn">Cancel</Link>
        </div>
    </form>
</template>
