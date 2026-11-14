<script setup>
import { computed, onMounted, ref, watch } from 'vue';

// "When sent, also": the status change Jira offers from where the ticket is, with the fields
// it asks for, and who has the ticket after. Prefilled with what the agent suggests.
const props = defineProps({
    ticketId: { type: Number, required: true },
    // The agent's suggestion: a status by name, and keep / reporter / me.
    suggestStatus: { type: String, default: null },
    suggestAssign: { type: String, default: null },
});
const model = defineModel({ type: Object, default: () => ({ transition: '', fields: {}, assignee: 'keep' }) });

const options = ref(null);
const error = ref(null);
const search = ref('');
const people = ref([]);
const picked = ref(null); // a person picked by name

onMounted(async () => {
    try {
        const answer = await (await fetch(`/tickets/${props.ticketId}/send-options`, { headers: { Accept: 'application/json' } })).json();
        options.value = answer;
        error.value = answer.error ?? null;
        // The agent's suggestion, where Jira offers it.
        const match = (answer.transitions ?? []).find((t) => props.suggestStatus && t.to.toLowerCase() === props.suggestStatus.toLowerCase());
        if (match && !model.value.transition) model.value = { ...model.value, transition: match.id };
        if (props.suggestAssign === 'reporter' && answer.reporter?.assignable && model.value.assignee === 'keep') model.value = { ...model.value, assignee: 'reporter' };
        if (props.suggestAssign === 'me' && answer.me && model.value.assignee === 'keep') model.value = { ...model.value, assignee: 'me' };
    } catch {
        error.value = 'Jira did not answer: the status and assignee cannot be picked now.';
    }
});

const chosen = computed(() => (options.value?.transitions ?? []).find((t) => t.id === model.value.transition) ?? null);
function setField(key, value) {
    model.value = { ...model.value, fields: { ...model.value.fields, [key]: value } };
}
let timer = null;
watch(search, (text) => {
    clearTimeout(timer);
    if (text.trim().length < 2) {
        people.value = [];
        return;
    }
    timer = setTimeout(async () => {
        const answer = await (await fetch(`/tickets/${props.ticketId}/assignable?q=${encodeURIComponent(text)}`, { headers: { Accept: 'application/json' } })).json();
        people.value = answer.people ?? [];
    }, 250);
});
function pickPerson(person) {
    picked.value = person;
    model.value = { ...model.value, assignee: `account:${person.id}` };
    search.value = '';
    people.value = [];
}
function assign(value) {
    picked.value = null;
    model.value = { ...model.value, assignee: value };
}
const pill = (active) => ['rounded-md border px-2.5 py-1 text-xs', active ? 'border-ink text-ink' : 'border-line text-muted hover:text-ink'];
</script>

<template>
    <fieldset class="space-y-3 rounded-md border border-line p-3">
        <legend class="px-1 text-xs font-medium tracking-wide text-muted uppercase">When sent, also</legend>
        <p v-if="error" class="text-sm text-signal">{{ error }}</p>
        <label class="block text-sm">Status <span v-if="options?.status" class="text-muted">(now {{ options.status }})</span>
            <select v-model="model.transition" class="mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5 text-sm" :disabled="!options">
                <option value="">{{ options ? 'Keep the status' : 'Asking Jira…' }}</option>
                <option v-for="t in options?.transitions ?? []" :key="t.id" :value="t.id">{{ t.to }}<template v-if="t.name !== t.to"> ({{ t.name }})</template></option>
            </select>
        </label>
        <!-- What Jira's own dialog asks on that step: often required by a rule it does not mark. -->
        <div v-for="field in chosen?.fields ?? []" :key="field.key" class="text-sm">
            <label :for="`f-${field.key}`">{{ field.name }} <span class="text-muted">{{ field.required ? '(required)' : '(if Jira asks for it)' }}</span></label>
            <select v-if="field.options.length" :id="`f-${field.key}`" class="mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5" :value="model.fields[field.key] ?? ''" @change="setField(field.key, $event.target.value)">
                <option value="">Choose…</option>
                <option v-for="o in field.options" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
            <input v-else :id="`f-${field.key}`" class="mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5" :value="model.fields[field.key] ?? ''" @input="setField(field.key, $event.target.value)" />
        </div>
        <div class="text-sm">
            <p>Assign to <span v-if="options?.assignee !== undefined" class="text-muted">(now {{ options?.assignee ?? 'nobody' }})</span></p>
            <div class="mt-1 flex flex-wrap gap-1.5">
                <button type="button" :class="pill(model.assignee === 'keep')" @click="assign('keep')">Keep</button>
                <button v-if="options?.me" type="button" :class="pill(model.assignee === 'me')" @click="assign('me')">Me</button>
                <button v-if="options?.reporter?.assignable" type="button" :class="pill(model.assignee === 'reporter')" @click="assign('reporter')">The reporter ({{ options.reporter.name }})</button>
                <button type="button" :class="pill(model.assignee === 'unassign')" @click="assign('unassign')">Nobody</button>
                <span v-if="picked" :class="pill(true)">{{ picked.name }}</span>
            </div>
            <div class="relative mt-1.5">
                <input v-model="search" placeholder="Someone else: type a name" class="w-full rounded-md border border-line bg-page px-2 py-1.5 text-sm placeholder:text-muted" />
                <ul v-if="people.length" class="absolute z-10 mt-1 w-full rounded-md border border-line bg-surface shadow-lg">
                    <li v-for="person in people" :key="person.id"><button type="button" class="w-full px-3 py-2 text-left hover:bg-sunken" @click="pickPerson(person)">{{ person.name }}</button></li>
                </ul>
            </div>
        </div>
    </fieldset>
</template>
