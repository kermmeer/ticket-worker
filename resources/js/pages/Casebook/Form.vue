<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import TicketKey from '../../components/TicketKey.vue';

const props = defineProps({
    entry: { type: Object, required: true },
    systems: { type: Array, required: true },
    matches: { type: Array, required: true },
});

const form = useForm({
    title: props.entry.title,
    system_id: props.entry.system_id,
    symptoms: props.entry.symptoms,
    cause: props.entry.cause,
    solution: props.entry.solution,
    keywords: props.entry.keywords,
    source_tickets: props.entry.source_tickets,
    state: props.entry.state,
});

function save() {
    if (props.entry.id) {
        form.put(`/casebook/${props.entry.id}`, { preserveScroll: true });
    } else {
        form.post('/casebook');
    }
}

function remove() {
    if (window.confirm('Remove this case from the casebook?')) {
        router.delete(`/casebook/${props.entry.id}`);
    }
}

const fieldClass = 'mt-1 w-full rounded-md border border-line bg-page px-3 py-2 text-sm';
</script>

<template>
    <Head :title="entry.id ? entry.title : 'New case'" />

    <p class="eyebrow"><Link href="/casebook" class="hover:text-ink">Casebook</Link> / {{ entry.id ? `#${entry.id}` : 'New' }}</p>
    <h1 class="display mt-3 text-4xl leading-[1.1] sm:text-5xl">{{ entry.id ? form.title || 'Untitled case' : 'Write a case' }}</h1>
    <p class="mt-4 max-w-2xl text-muted">
        Write it general: the problem, not the customer. A case outlives the tickets it came from, so no names, addresses
        or other personal data go in.
    </p>

    <form class="mt-10 grid gap-8 xl:grid-cols-[minmax(0,1fr)_22rem]" @submit.prevent="save">
        <div class="space-y-6">
            <div>
                <label for="title" class="text-sm font-medium">The problem, in one line</label>
                <input id="title" v-model="form.title" :class="fieldClass" />
                <p v-if="form.errors.title" class="mt-1 text-sm text-signal">{{ form.errors.title }}</p>
            </div>
            <div>
                <label for="symptoms" class="text-sm font-medium">How it shows up</label>
                <p class="text-sm text-muted">What reporters write, error messages, which screen. Phrases in every language you see them in help the match.</p>
                <textarea id="symptoms" v-model="form.symptoms" rows="4" :class="fieldClass"></textarea>
                <p v-if="form.errors.symptoms" class="mt-1 text-sm text-signal">{{ form.errors.symptoms }}</p>
            </div>
            <div>
                <label for="cause" class="text-sm font-medium">The cause</label>
                <p class="text-sm text-muted">Where in the system, and why. File names and functions are welcome: the agent goes straight there.</p>
                <textarea id="cause" v-model="form.cause" rows="4" :class="fieldClass"></textarea>
            </div>
            <div>
                <label for="solution" class="text-sm font-medium">What fixed it</label>
                <p class="text-sm text-muted">As steps a colleague could follow: the fix, the workaround, what to tell the reporter.</p>
                <textarea id="solution" v-model="form.solution" rows="6" :class="fieldClass"></textarea>
                <p v-if="form.errors.solution" class="mt-1 text-sm text-signal">{{ form.errors.solution }}</p>
            </div>
        </div>

        <aside class="space-y-6">
            <div class="card space-y-5 p-5">
                <div>
                    <p class="text-sm font-medium">State</p>
                    <div class="mt-2 space-y-1.5 text-sm">
                        <label class="flex items-start gap-2"><input v-model="form.state" type="radio" value="draft" class="mt-1" /><span><span class="font-medium">Draft</span><span class="text-muted">: not offered yet</span></span></label>
                        <label class="flex items-start gap-2"><input v-model="form.state" type="radio" value="approved" class="mt-1" /><span><span class="font-medium">Approved</span><span class="text-muted">: matched and offered</span></span></label>
                        <label class="flex items-start gap-2"><input v-model="form.state" type="radio" value="retired" class="mt-1" /><span><span class="font-medium">Retired</span><span class="text-muted">: no longer true, kept for the record</span></span></label>
                    </div>
                </div>
                <div>
                    <label for="system" class="text-sm font-medium">System</label>
                    <select id="system" v-model="form.system_id" :class="fieldClass">
                        <option :value="null">Not one in particular</option>
                        <option v-for="system in systems" :key="system.id" :value="system.id">{{ system.name }}</option>
                    </select>
                </div>
                <div>
                    <label for="keywords" class="text-sm font-medium">Extra words to match on</label>
                    <input id="keywords" v-model="form.keywords" placeholder="invoice, factuur, facture, faktura" :class="fieldClass" />
                    <p class="mt-1 text-sm text-muted">They count most. Add the other languages' words here.</p>
                </div>
                <div>
                    <label for="sources" class="text-sm font-medium">Solved on</label>
                    <input id="sources" v-model="form.source_tickets" placeholder="SUP-1234, SUP-1301" :class="`${fieldClass} font-mono`" />
                </div>
                <p v-if="entry.id" class="text-sm text-muted">Written by {{ entry.written_by }}; used by agents {{ entry.used_count }} times.</p>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-signal" :disabled="form.processing">{{ entry.id ? 'Save' : 'Write it down' }}</button>
                    <button v-if="entry.id" type="button" class="btn" @click="remove">Remove</button>
                </div>
            </div>

            <div v-if="entry.id" class="card p-5">
                <p class="text-sm font-medium">Open tickets it matches now</p>
                <p v-if="entry.state !== 'approved'" class="mt-1 text-sm text-muted">None: only approved cases are matched.</p>
                <p v-else-if="matches.length === 0" class="mt-1 text-sm text-muted">None at the moment.</p>
                <ul v-else class="mt-2 space-y-2 text-sm">
                    <li v-for="match in matches" :key="match.key" class="flex gap-2">
                        <a :href="match.url" target="_blank" rel="noopener" class="shrink-0"><TicketKey :value="match.key" /></a>
                        <span class="min-w-0 break-words text-muted">{{ match.summary }}</span>
                    </li>
                </ul>
            </div>
        </aside>
    </form>
</template>
