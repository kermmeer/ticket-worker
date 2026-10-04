<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import ActivityLine from '../../components/ActivityLine.vue';
import LogEntry from '../../components/LogEntry.vue';
import SpaceLabel from '../../components/SpaceLabel.vue';
import StatusLabel from '../../components/StatusLabel.vue';
import TicketKey from '../../components/TicketKey.vue';
import { phrase, shortName, tone } from '../../sla.js';
import { short, tokens } from '../../time.js';

const props = defineProps({
    ticket: { type: Object, required: true },
    issue: { type: Object, default: null },
    issueError: { type: String, default: null },
    session: { type: Object, default: null },
    languages: { type: Array, required: true },
    agentReady: { type: Boolean, required: true },
    outboxReady: { type: Boolean, required: true },
});

const language = ref(props.session?.reply_language ?? 'auto');
const message = useForm({ text: '' });

const busy = computed(() => props.session?.busy ?? false);
const proposal = computed(() => props.session?.proposal ?? null);
const lastTool = computed(() => {
    const events = props.session?.turns.flatMap((turn) => turn.events) ?? [];
    return [...events].reverse().find((event) => event.type === 'tool')?.summary ?? 'starting';
});

function post(path, data = {}) {
    router.post(`/tickets/${props.ticket.id}/${path}`, data, { preserveScroll: true });
}

function send() {
    message.post(`/tickets/${props.ticket.id}/messages`, { preserveScroll: true, onSuccess: () => message.reset() });
}

// The reply draft, editable before it goes to the outbox.
const draft = useForm({ body: '', visibility: 'public' });
watch(proposal, (value) => (draft.body = value?.reply_draft ?? ''), { immediate: true });

function toOutbox() {
    draft.post(`/tickets/${props.ticket.id}/draft`, { preserveScroll: true });
}

// While the agent works, the page asks for its steps every two seconds, quietly.
let poll = null;
watch(
    busy,
    (working) => {
        clearInterval(poll);
        if (working) {
            poll = setInterval(() => router.reload({ only: ['session'], preserveScroll: true, preserveState: true, showProgress: false }), 2000);
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => clearInterval(poll));

const turnLabels = { analysis: 'Analysis', message: 'Your question', proposal: 'Proposal restated', case: 'Casebook draft' };
const stateTone = { queued: 'text-muted', running: 'text-working', done: 'text-done', failed: 'text-signal', stopped: 'text-waiting' };
const confidenceTone = { high: 'text-done', medium: 'text-waiting', low: 'text-signal' };
</script>

<template>
    <Head :title="ticket.key" />

    <p class="eyebrow"><Link href="/" class="hover:text-ink">Overview</Link> / {{ ticket.key }}</p>
    <div class="mt-3 flex flex-wrap items-center gap-3">
        <TicketKey :value="ticket.key" />
        <StatusLabel :status="ticket.status" :tone="ticket.status_tone" />
        <SpaceLabel :label="ticket.space.label" :colour="ticket.space.colour" class="text-sm text-muted" />
        <span v-if="ticket.priority" class="text-sm text-muted">· {{ ticket.priority }}</span>
        <span class="text-sm text-muted">· {{ ticket.assignee ? `assigned to ${ticket.assignee}` : 'assigned to nobody' }}</span>
        <a :href="ticket.url" target="_blank" rel="noopener" class="ml-auto text-sm text-muted hover:text-ink">Open in the outbox ↗</a>
    </div>
    <h1 class="display mt-3 text-3xl leading-tight sm:text-4xl">{{ ticket.summary }}</h1>
    <p v-if="ticket.slas.length" class="mt-2 flex flex-wrap gap-x-5 text-sm">
        <span v-for="sla in ticket.slas" :key="sla.name">
            <span class="text-muted">{{ shortName(sla.name) }}</span>
            <span class="ml-1.5 font-medium" :style="{ color: `var(--tone-${tone(sla)})` }">{{ phrase(sla) }}</span>
        </span>
    </p>

    <div class="mt-10 grid gap-8 xl:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
        <!-- The ticket, as Jira has it now -->
        <section class="card min-w-0 self-start p-5">
            <p class="eyebrow">Ticket</p>
            <p v-if="issueError" class="mt-3 text-sm text-signal">Jira did not answer: {{ issueError }}</p>
            <template v-else-if="issue">
                <p class="mt-3 text-sm text-muted">{{ issue.reporter }} · {{ short(issue.created) }} · {{ issue.type }}</p>
                <div class="mt-4 text-sm leading-relaxed break-words whitespace-pre-wrap">{{ issue.description || 'No description.' }}</div>

                <template v-if="issue.attachments.length">
                    <p class="mt-6 text-sm font-medium">Attachments ({{ issue.attachments.length }})</p>
                    <ul class="mt-1 space-y-0.5 font-mono text-xs text-muted">
                        <li v-for="file in issue.attachments" :key="file.id" class="break-all">{{ file.filename }}</li>
                    </ul>
                </template>

                <p class="mt-6 text-sm font-medium">Comments ({{ issue.comments.length }})</p>
                <div class="mt-2 space-y-4">
                    <div v-for="(comment, index) in issue.comments" :key="index" class="border-l-2 pl-3 text-sm" :class="comment.public ? 'border-line' : 'border-waiting/60'">
                        <p class="text-xs text-muted">
                            {{ comment.author }} · {{ short(comment.created) }}<span v-if="!comment.public" class="text-waiting"> · internal note</span>
                        </p>
                        <div class="mt-1 break-words whitespace-pre-wrap">{{ comment.body }}</div>
                    </div>
                </div>
            </template>
        </section>

        <!-- The agent -->
        <section class="min-w-0 space-y-6">
            <div class="card p-5">
                <p class="eyebrow">Agent</p>
                <p v-if="!agentReady" class="mt-3 text-sm text-signal">
                    No Anthropic sign-in yet (an API key or a subscription token), so no agent can run.
                    <Link href="/docs/concept#getting-an-anthropic-api-key" class="underline">How to get one</Link>.
                </p>
                <div v-else class="mt-3 flex flex-wrap items-center gap-3">
                    <label class="flex items-center gap-2 text-sm">
                        <span class="text-muted">Reply in</span>
                        <select v-model="language" class="rounded-md border border-line bg-page px-2 py-1.5 text-sm" :disabled="busy">
                            <option v-for="choice in languages" :key="choice" :value="choice">{{ choice === 'auto' ? "the ticket's language" : choice }}</option>
                        </select>
                    </label>
                    <button type="button" class="btn btn-signal" :disabled="busy" @click="post('analyse', { language })">
                        {{ session ? 'Analyse again' : 'Analyse' }}
                    </button>
                    <button v-if="busy" type="button" class="btn" @click="post('stop')">Stop</button>
                    <button v-if="session && !busy" type="button" class="btn" @click="post('close')">Close session</button>
                    <span v-if="session" class="ml-auto text-right font-mono text-xs text-muted">
                        ${{ session.cost_usd.toFixed(2) }} so far<br />
                        {{ tokens(session.tokens_in) }} tokens in<template v-if="session.tokens_cached"> ({{ tokens(session.tokens_cached) }} from cache)</template> · {{ tokens(session.tokens_out) }} out
                    </span>
                </div>
            </div>

            <!-- The log -->
            <div v-if="session" class="card space-y-6 p-5">
                <p class="eyebrow">Log</p>
                <template v-for="turn in session.turns" :key="turn.id">
                    <LogEntry v-if="turn.prompt" :time="short(turn.started_at ?? turn.finished_at).split(', ').pop()" author="you">
                        <p class="whitespace-pre-wrap">{{ turn.prompt }}</p>
                    </LogEntry>
                    <LogEntry :time="turn.started_at ? short(turn.started_at).split(', ').pop() : '…'" author="agent">
                        <p class="text-xs font-medium tracking-wide uppercase" :class="stateTone[turn.state]">
                            {{ turnLabels[turn.kind] }} · {{ turn.state }}<template v-if="turn.cost_usd != null"> · ${{ turn.cost_usd.toFixed(2) }}</template>
                            <template v-if="turn.tokens_in != null"> · {{ tokens(turn.tokens_in) }} in · {{ tokens(turn.tokens_out) }} out</template>
                        </p>
                        <template v-for="event in turn.events" :key="event.id">
                            <p v-if="event.type === 'text'" class="text-sm break-words whitespace-pre-wrap">{{ event.summary }}</p>
                            <p v-else-if="event.type === 'tool'" class="font-mono text-xs break-all text-muted">{{ event.summary }}</p>
                            <p v-else class="text-sm text-signal">{{ event.summary }}</p>
                        </template>
                        <ActivityLine v-if="turn.state === 'running'" :now="lastTool" :tally="`${turn.events.filter((e) => e.type === 'tool').length} steps`" />
                        <p v-if="turn.state === 'queued'" class="text-sm text-muted">Waiting for a free agent…</p>
                        <Link v-if="turn.case_draft" :href="`/casebook/create?turn=${turn.id}`" class="btn btn-signal self-start">Review the case and write it down</Link>
                    </LogEntry>
                </template>

                <form v-if="!busy" class="flex flex-wrap gap-2 pt-2" @submit.prevent="send">
                    <label for="message" class="sr-only">Message the agent</label>
                    <textarea
                        id="message"
                        v-model="message.text"
                        rows="2"
                        placeholder="Ask the agent: look at the batch job instead, the reporter says it worked last week…"
                        class="min-w-0 flex-1 rounded-md border border-line bg-page px-3 py-2 text-sm placeholder:text-muted"
                    ></textarea>
                    <div class="flex flex-col gap-2">
                        <button type="submit" class="btn btn-signal" :disabled="message.processing || !message.text.trim()">Send</button>
                        <button type="button" class="btn" @click="post('restate')">Update proposal</button>
                        <button type="button" class="btn" @click="post('case')">Draft a case</button>
                    </div>
                </form>
            </div>

            <!-- The proposal -->
            <div v-if="proposal" class="card space-y-5 p-5 text-sm">
                <div class="flex flex-wrap items-baseline justify-between gap-3">
                    <p class="eyebrow">Proposal</p>
                    <p class="font-mono text-xs" :class="confidenceTone[proposal.confidence]">{{ proposal.confidence }} confidence</p>
                </div>
                <p class="text-muted">{{ proposal.confidence_reason }}</p>
                <div><p class="font-medium">Problem</p><p class="mt-1">{{ proposal.problem }}</p></div>
                <div><p class="font-medium">System</p><p class="mt-1 font-mono">{{ proposal.system }}</p></div>
                <div>
                    <p class="font-medium">Cause</p>
                    <p class="mt-1 whitespace-pre-wrap">{{ proposal.cause }}</p>
                    <ul v-if="proposal.other_causes?.length" class="mt-2 list-disc pl-5 text-muted">
                        <li v-for="(other, index) in proposal.other_causes" :key="index">{{ other }}</li>
                    </ul>
                </div>
                <div v-if="proposal.evidence?.length">
                    <p class="font-medium">Evidence</p>
                    <ul class="mt-1 space-y-1.5">
                        <li v-for="(item, index) in proposal.evidence" :key="index">
                            <span class="rounded-sm border border-line bg-sunken px-1.5 py-0.5 font-mono text-xs break-all">{{ item.reference }}</span>
                            <span class="ml-2 text-muted">{{ item.note }}</span>
                        </li>
                    </ul>
                </div>
                <div><p class="font-medium">Fix</p><p class="mt-1 whitespace-pre-wrap">{{ proposal.fix }}</p></div>
                <div v-if="proposal.workaround"><p class="font-medium">Workaround</p><p class="mt-1 whitespace-pre-wrap">{{ proposal.workaround }}</p></div>
                <div v-if="proposal.questions?.length">
                    <p class="font-medium">Questions</p>
                    <ul class="mt-1 list-disc pl-5"><li v-for="(question, index) in proposal.questions" :key="index">{{ question }}</li></ul>
                </div>
                <p v-if="proposal.casebook" class="text-muted">
                    Casebook: <template v-if="proposal.casebook.case">case {{ proposal.casebook.case }}, {{ proposal.casebook.fit }}</template><template v-else>{{ proposal.casebook.fit || 'new' }}</template>
                </p>

                <form class="border-t border-line pt-5" @submit.prevent="toOutbox">
                    <label for="reply" class="font-medium">Reply draft<span v-if="proposal.reply_language" class="font-normal text-muted"> · {{ proposal.reply_language }}</span></label>
                    <textarea id="reply" v-model="draft.body" rows="8" class="mt-2 w-full rounded-md border border-line bg-page px-3 py-2 text-sm"></textarea>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <template v-if="ticket.space.type === 'service'">
                            <label class="flex items-center gap-1.5"><input v-model="draft.visibility" type="radio" value="public" /> Reply to customer</label>
                            <label class="flex items-center gap-1.5"><input v-model="draft.visibility" type="radio" value="internal" /> Internal note</label>
                        </template>
                        <button type="submit" class="btn btn-signal ml-auto" :disabled="!outboxReady || draft.processing || !draft.body.trim()">Send to the outbox as a draft</button>
                    </div>
                    <p class="mt-2 text-muted">Nothing goes to Jira from here: the draft waits in the outbox until you send it there.</p>
                </form>
            </div>
        </section>
    </div>
</template>
