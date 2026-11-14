<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';

// Done here: how it was solved, whether the agent was right, a note; and, if you like, the
// agent writes it up as a case from the chat. What every handled ticket teaches the tool.
const props = defineProps({
    ticket: { type: Object, required: true },
    doneHow: { type: Object, required: true },
    verdicts: { type: Object, required: true },
    // Asked by itself, when the ticket was resolved in Jira: open from the start.
    asked: { type: Boolean, default: false },
});
const open = ref(props.asked);
const form = useForm({ how: null, verdict: null, note: '', case: false });
const solvedHere = computed(() => ['reply', 'data', 'code', 'config'].includes(form.how));
function pick(how) {
    form.how = how;
    // A real fix is worth a case; "not ours" or a duplicate is not.
    form.case = props.ticket.worked && ['data', 'code', 'config'].includes(how);
}
function save() {
    form.post(`/tickets/${props.ticket.id}/done`, { preserveScroll: true, onSuccess: () => (open.value = false) });
}
const pill = 'cursor-pointer rounded-full border px-3 py-1 text-sm';
</script>

<template>
    <div v-if="ticket.done" class="text-sm">
        <span class="font-medium text-done">Done</span>
        <span class="text-muted"> · {{ doneHow[ticket.done.how] ?? ticket.done.how }}<template v-if="ticket.done.verdict"> · the agent {{ (verdicts[ticket.done.verdict] ?? '').toLowerCase() }}</template><template v-if="ticket.done.by"> · {{ ticket.done.by }}</template></span>
        <span v-if="ticket.done.note" class="block">{{ ticket.done.note }}</span>
        <button type="button" class="text-xs text-muted underline hover:text-ink" @click="router.post(`/tickets/${ticket.id}/undone`, {}, { preserveScroll: true })">Not done after all</button>
    </div>
    <template v-else>
        <button v-if="!open" type="button" class="btn" @click="open = true">Done…</button>
        <form v-else class="w-full space-y-3 rounded-md border p-3 text-sm" :class="asked ? 'border-waiting/50' : 'border-line'" @submit.prevent="save">
            <p v-if="asked" class="font-medium text-waiting">Resolved in Jira: how was it solved?</p>
            <p v-else class="font-medium">How was it solved?</p>
            <div class="flex flex-wrap gap-1.5">
                <label v-for="(label, key) in doneHow" :key="key" :class="[pill, form.how === key ? 'border-ink' : 'border-line text-muted']">
                    <input type="radio" class="sr-only" :checked="form.how === key" @change="pick(key)" />{{ label }}
                </label>
            </div>
            <div v-if="ticket.worked">
                <p class="text-muted">Was the agent's answer right?</p>
                <div class="mt-1 flex flex-wrap gap-1.5">
                    <label v-for="(label, key) in verdicts" :key="key" :class="[pill, form.verdict === key ? 'border-ink' : 'border-line text-muted']">
                        <input v-model="form.verdict" type="radio" class="sr-only" :value="key" />{{ label }}
                    </label>
                </div>
            </div>
            <label class="block">A note <span class="text-muted">(optional: what made the difference)</span>
                <input v-model="form.note" maxlength="500" class="mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5" />
            </label>
            <label v-if="ticket.worked && solvedHere" class="flex items-start gap-2">
                <input v-model="form.case" type="checkbox" class="mt-1" />
                <span>The agent writes it up as a case <span class="block text-xs text-muted">From the chat; you review it before it is saved. The next ticket like it starts from there.</span></span>
            </label>
            <p v-if="Object.keys(form.errors).length" class="text-signal">{{ Object.values(form.errors)[0] }}</p>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-signal" :disabled="form.processing || !form.how">Done</button>
                <button v-if="!asked" type="button" class="btn" @click="open = false">Cancel</button>
            </div>
        </form>
    </template>
</template>
