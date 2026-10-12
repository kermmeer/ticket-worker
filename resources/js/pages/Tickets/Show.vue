<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import ActivityLine from '../../components/ActivityLine.vue';
import AgentText from '../../components/AgentText.vue';
import CodeBlock from '../../components/CodeBlock.vue';
import FoldingSection from '../../components/FoldingSection.vue';
import JiraText from '../../components/JiraText.vue';
import LogEntry from '../../components/LogEntry.vue';
import QuickScanCard from '../../components/QuickScanCard.vue';
import SpaceLabel from '../../components/SpaceLabel.vue';
import StatusLabel from '../../components/StatusLabel.vue';
import TicketKey from '../../components/TicketKey.vue';
import { reachable } from '../../connection.js';
import { copyText } from '../../copy.js';
import { phrase, shortName, tone } from '../../sla.js';
import { short, tokens } from '../../time.js';

const props = defineProps({
    ticket: { type: Object, required: true },
    issue: { type: Object, default: null },
    issueError: { type: String, default: null },
    session: { type: Object, default: null },
    history: { type: Array, default: () => [] },
    languages: { type: Array, required: true },
    agentReady: { type: Boolean, required: true },
    outboxReady: { type: Boolean, required: true },
    systems: { type: Array, default: () => [] },
    budget: { type: Object, default: null },
});

const language = ref(props.session?.reply_language ?? 'auto');
// What you are typing to the agent is kept in the browser until it is sent, so a reload
// (signing in again, say) never loses it.
const messageKey = `ticket.${props.ticket.id}.message`;
const stored = (write) => {
    try {
        return write();
    } catch {
        return null; // storage refused (private mode): it still works, just not across a reload
    }
};
const message = useForm({ text: stored(() => localStorage.getItem(messageKey)) ?? '' });
watch(() => message.text, (text) => stored(() => (text ? localStorage.setItem(messageKey, text) : localStorage.removeItem(messageKey))));

// Your own notes for the next analysis: what you know that the ticket does not say. Kept the
// same way until the analysis starts.
const noteKey = `ticket.${props.ticket.id}.note`;
const note = ref(stored(() => localStorage.getItem(noteKey)) ?? '');
watch(note, (text) => stored(() => (text ? localStorage.setItem(noteKey, text) : localStorage.removeItem(noteKey))));

// Closing, with a note for the next person.
const closing = ref(false);
const handover = ref('');
function closeSession() {
    router.post(`/tickets/${props.ticket.id}/close`, { note: handover.value }, { preserveScroll: true, onSuccess: () => { closing.value = false; handover.value = ''; } });
}

function analyse() {
    router.post(`/tickets/${props.ticket.id}/analyse`, { language: language.value, note: note.value }, {
        preserveScroll: true,
        onSuccess: () => (note.value = ''),
    });
}

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
    message.post(`/tickets/${props.ticket.id}/messages`, { preserveScroll: true, onSuccess: () => (message.text = '') });
}

// The reply draft, editable before it goes to the outbox.
const draft = useForm({ body: '', visibility: 'public' });
watch(proposal, (value) => (draft.body = value?.reply_draft ?? ''), { immediate: true });

// Its own site's name for it: another Jira, or the outbox.
const openLabel = props.ticket.open_label ?? usePage().props.openLabel ?? 'Jira';

// The latest word on top; the agent's ticket.md keeps Jira's order, oldest first.
const newestFirst = computed(() => [...(props.issue?.comments ?? [])].reverse());

// Attachments come through Ticket Worker, which holds the Jira token; images and PDFs open
// in the browser, everything else downloads.
const fileUrl = (file, download = false) => `/tickets/${props.ticket.id}/attachments/${file.id}${download ? '?download=1' : ''}`;
const size = (bytes) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} kB`);

// Without an outbox, the reply goes over by hand.
const copied = ref(false);
async function copyReply() {
    await copyText(draft.body);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}

// "and open it": the tab opens on the click itself, or the browser blocks it as a popup,
// and is sent to the draft once the outbox has it. If the outbox refuses, it closes again.
function toOutbox(open = false) {
    let tab = null;
    let sent = false;
    if (open) {
        tab = window.open('', '_blank');
        if (tab) {
            tab.opener = null;
            tab.document.title = 'Waiting for the outbox…';
        }
    }
    draft.post(`/tickets/${props.ticket.id}/draft`, {
        preserveScroll: true,
        onSuccess: (page) => {
            const url = page.props.flash?.outbox_url;
            if (tab && url) {
                tab.location.href = url;
                sent = true;
            }
        },
        // A refusal comes back as an error flash rather than an error: close it either way.
        onFinish: () => {
            if (tab && !sent) {
                tab.close();
            }
        },
    });
}

// While the agent works, the page asks for its steps every two seconds, quietly.
let poll = null;
watch(
    busy,
    (working) => {
        clearInterval(poll);
        if (working) {
            poll = setInterval(() => reachable() && router.reload({ only: ['session'], preserveScroll: true, preserveState: true, showProgress: false }), 2000);
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => clearInterval(poll));

// A quick scan takes a minute or two: look again every few seconds until it is done.
let scanPoll = null;
watch(
    () => ['queued', 'running'].includes(props.ticket.quick_scan?.state),
    (scanning) => {
        clearInterval(scanPoll);
        if (scanning) {
            scanPoll = setInterval(() => reachable() && router.reload({ only: ['ticket'], preserveScroll: true, preserveState: true, showProgress: false }), 3000);
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => clearInterval(scanPoll));

// A finished turn's steps, folded into one line: "23 steps: 14 files read, 6 searches, 3 API
// calls". The raw names of the tool's own tools (mcp__…) repeat the line it logs itself, and
// the closing JSON is no step: both are left out.
const stepWords = { reading: ['file read', 'files read'], searching: ['search', 'searches'], listing: ['listing', 'listings'], edit: ['edit', 'edits'], write: ['file written', 'files written'], api: ['API call', 'API calls'], sql: ['query', 'queries'] };
// What a finished turn answered: for a question, its last words. An analysis, a patch or a
// case draft answers in its own block (the proposal, the patch, the case), so nothing here.
function answerOf(turn) {
    if (turn.kind !== 'message') {
        return null;
    }
    const said = turn.events.filter((event) => event.type === 'text');
    return said.length ? said[said.length - 1].summary : null;
}
// Everything else it said while working.
function notesOf(turn) {
    const said = turn.events.filter((event) => event.type === 'text');
    return turn.kind === 'message' ? said.slice(0, -1) : said;
}

function steps(turn) {
    return turn.events.filter((event) => event.type === 'tool' && !/^(mcp__|structuredoutput)/i.test(event.summary));
}
function stepLine(turn) {
    const all = steps(turn);
    const counts = {};
    for (const event of all) {
        const word = event.summary.split(/\s/)[0].toLowerCase();
        counts[word] = (counts[word] ?? 0) + 1;
    }
    const parts = Object.entries(counts)
        .sort((a, b) => b[1] - a[1])
        .map(([word, n]) => (stepWords[word] ? `${n} ${stepWords[word][n === 1 ? 0 : 1]}` : null))
        .filter(Boolean);
    return `${all.length} step${all.length === 1 ? '' : 's'}${parts.length ? `: ${parts.join(', ')}` : ''}`;
}

// The brief: one line that says what this ticket is about and where it stands.
const scanResult = computed(() => props.ticket.quick_scan?.result ?? null);
const briefLine = computed(() =>
    proposal.value?.headline ?? proposal.value?.problem ?? scanResult.value?.summary ?? 'Not looked at yet: analyse it, or give it a quick scan first.',
);

// The jump bar's parts: only those the page has.
const jumps = computed(() => [
    { id: 'scan', label: 'Quick scan' },
    { id: 'agent', label: 'Agent' },
    ...(proposal.value ? [{ id: 'proposal', label: 'Proposal & reply' }] : []),
    { id: 'ticket', label: 'Ticket' },
]);

const turnLabels = { analysis: 'Analysis', message: 'Your question', proposal: 'Proposal restated', case: 'Casebook draft', patch: 'Patch' };

// A patch is for one system: the proposal's, unless you pick another.
const patchSystem = ref(null);
function preparePatch() {
    post('patch', patchSystem.value ? { system: patchSystem.value } : {});
}

// The diff, line by line, with its kind for colour: + added, - removed, @ position, file headers.
function diffLines(text) {
    const start = text.indexOf('\ndiff --git ');
    return (start === -1 ? text : text.slice(start + 1))
        .split('\n')
        .map((line) => ({
            line,
            kind: line.startsWith('diff --git') ? 'file' : line.startsWith('@@') ? 'at' : line.startsWith('+') && !line.startsWith('+++') ? 'add' : line.startsWith('-') && !line.startsWith('---') ? 'del' : 'ctx',
        }));
}
const diffTone = { file: 'font-medium text-ink', at: 'text-working', add: 'bg-done/10 text-done', del: 'bg-signal/10 text-signal', ctx: 'text-muted' };
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
        <button v-if="$page.props.me?.jira_account_id && !ticket.assigned_to_me" type="button" class="text-sm text-muted underline hover:text-ink" @click="post('assign-to-me')">Assign to me</button>
        <a :href="ticket.url" target="_blank" rel="noopener" class="ml-auto text-sm text-muted hover:text-ink">Open in {{ openLabel }} ↗</a>
    </div>
    <h1 class="display mt-3 text-3xl leading-tight sm:text-4xl">{{ ticket.summary }}</h1>
    <p v-if="ticket.slas.length" class="mt-2 flex flex-wrap gap-x-5 text-sm">
        <span v-for="sla in ticket.slas" :key="sla.name">
            <span class="text-muted">{{ shortName(sla.name) }}</span>
            <span class="ml-1.5 font-medium" :style="{ color: `var(--tone-${tone(sla)})` }">{{ phrase(sla) }}</span>
        </span>
    </p>

    <div v-if="!session && history[0]?.handover_note" role="note" class="mt-6 rounded-md border border-working/40 px-4 py-2.5 text-sm">
        <span class="font-medium text-working">{{ history[0].closed_by ?? 'Someone' }} left a note when closing the last session:</span>
        <span class="mt-1 block whitespace-pre-wrap">{{ history[0].handover_note }}</span>
    </div>

    <p v-if="budget" role="status" class="mt-6 rounded-md border px-4 py-2.5 text-sm" :class="budget.level === 'stop' ? 'border-signal/40 text-signal' : 'border-waiting/40 text-waiting'">
        {{ budget.text }}
    </p>

    <!-- First, at every width: on a narrow screen it would otherwise sit below the whole ticket. -->
    <!-- In short: enough to decide, and the next action, before any of the detail. -->
    <section aria-label="In short" class="card mt-6 border-l-4 p-5" :class="proposal ? 'border-l-done' : scanResult ? 'border-l-working' : 'border-l-line'">
        <p class="text-lg leading-snug font-medium">{{ briefLine }}</p>
        <dl v-if="proposal" class="mt-3 grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-sm">
            <dt class="text-muted">Cause</dt><dd class="line-clamp-3">{{ proposal.cause }}</dd>
            <dt class="text-muted">Fix</dt><dd class="line-clamp-3">{{ proposal.fix }}</dd>
            <dt class="text-muted">Sure?</dt><dd :class="confidenceTone[proposal.confidence]">{{ proposal.confidence }} confidence</dd>
        </dl>
        <dl v-else-if="scanResult" class="mt-3 grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-sm">
            <dt class="text-muted">Likely</dt><dd class="line-clamp-3">{{ scanResult.likely_cause }}</dd>
            <dt class="text-muted">Next</dt><dd class="line-clamp-3">{{ scanResult.next_step }}</dd>
        </dl>
        <p v-if="scanResult?.data_check" class="mt-2 text-sm">
            <span class="text-muted">The data now: </span>
            <span class="font-medium" :class="{ yes: 'text-signal', no: 'text-done', unknown: 'text-muted' }[scanResult.data_check.still_present]">
                {{ { yes: 'still wrong', no: 'already right', unknown: 'not checked' }[scanResult.data_check.still_present] }}
            </span>
        </p>
        <div class="mt-4 flex flex-wrap items-center gap-2">
            <template v-if="busy">
                <span class="text-sm text-working">The agent is working…</span>
                <button type="button" class="btn" @click="post('stop')">Stop</button>
            </template>
            <template v-else>
                <template v-if="proposal?.reply_draft">
                    <button type="button" class="btn btn-signal" @click="copyReply">{{ copied ? 'Copied' : 'Copy the reply' }}</button>
                    <button v-if="outboxReady" type="button" class="btn" :disabled="draft.processing" @click="toOutbox(true)">Draft and open in the outbox ↗</button>
                    <a href="#reply-form" class="btn">Read or edit the reply</a>
                </template>
                <button v-if="!session && agentReady" type="button" class="btn" :class="{ 'btn-signal': !proposal }" @click="analyse">Analyse</button>
                <button v-if="!ticket.quick_scan && agentReady" type="button" class="btn" @click="router.post(`/tickets/${ticket.id}/quick-scan`, {}, { preserveScroll: true })">Quick scan</button>
            </template>
        </div>
    </section>

    <!-- On a phone: a jump bar to each part, under the header as you scroll. -->
    <nav aria-label="On this page" class="sticky top-11 z-[5] -mx-4 mt-4 flex gap-1 overflow-x-auto border-b border-line bg-page/95 px-4 py-2 backdrop-blur sm:hidden">
        <a v-for="part in jumps" :key="part.id" :href="`#${part.id}`" class="shrink-0 rounded-full border border-line px-3 py-1.5 text-sm">{{ part.label }}</a>
    </nav>

    <!-- Folded once there is a proposal: the brief above says what matters of it. -->
    <FoldingSection id="scan" title="Quick scan" remember="ticket.quick-scan-2" :open="!proposal" class="mt-8 scroll-mt-28">
        <QuickScanCard :ticket-id="ticket.id" :scan="ticket.quick_scan" :agent-ready="agentReady" />
    </FoldingSection>

    <!-- One column on a phone (that can never grow wider than the screen), two on a wide one. -->
    <div class="mt-8 grid grid-cols-[minmax(0,1fr)] gap-8 xl:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
        <!-- The ticket, as Jira has it now -->
        <FoldingSection id="ticket" title="Ticket" remember="ticket.ticket" class="min-w-0 scroll-mt-28 self-start">
            <p v-if="issueError" class="text-sm text-signal">Jira did not answer: {{ issueError }}</p>
            <template v-else-if="issue">
                <p class="text-sm text-muted">{{ issue.reporter }} · {{ short(issue.created) }} · {{ issue.type }}</p>
                <JiraText v-if="issue.description" :text="issue.description" :attachments="issue.attachments" :file-url="fileUrl" class="mt-4 text-sm leading-relaxed" />
                <p v-else class="mt-4 text-sm text-muted">No description.</p>

                <template v-if="issue.attachments.length">
                    <p class="mt-6 text-sm font-medium">Attachments ({{ issue.attachments.length }})</p>
                    <ul class="mt-1 space-y-1 text-xs">
                        <li v-for="file in issue.attachments" :key="file.id" class="flex flex-wrap items-baseline gap-x-2">
                            <a :href="fileUrl(file)" target="_blank" rel="noopener" class="font-mono break-all underline decoration-muted/50 underline-offset-2 hover:decoration-ink">{{ file.filename }}</a>
                            <span class="text-muted">{{ size(file.size) }}</span>
                            <a :href="fileUrl(file, true)" class="text-muted hover:text-ink">download</a>
                        </li>
                    </ul>
                </template>

                <p class="mt-6 text-sm font-medium">Comments ({{ issue.comments.length }}<template v-if="issue.comments.length > 1">, newest first</template>)</p>
                <div class="mt-2 space-y-4">
                    <div v-for="(comment, index) in newestFirst" :key="index" class="border-l-2 pl-3 text-sm" :class="comment.public ? 'border-line' : 'border-waiting/60'">
                        <p class="text-xs text-muted">
                            {{ comment.author }} · {{ short(comment.created) }}<span v-if="!comment.public" class="text-waiting"> · internal note</span>
                        </p>
                        <JiraText :text="comment.body" :attachments="issue.attachments" :file-url="fileUrl" class="mt-1" />
                    </div>
                </div>
            </template>
        </FoldingSection>

        <!-- The agent -->
        <section class="min-w-0 space-y-6">
            <FoldingSection id="agent" title="Agent" remember="ticket.agent" class="scroll-mt-28">
                <p v-if="!agentReady" class="text-sm text-signal">
                    No Anthropic sign-in yet (an API key or a subscription token), so no agent can run.
                    <Link href="/docs/concept#getting-an-anthropic-api-key" class="underline">How to get one</Link>.
                </p>
                <div v-else class="flex flex-wrap items-center gap-3">
                    <label class="flex items-center gap-2 text-sm">
                        <span class="text-muted">Reply in</span>
                        <select v-model="language" class="rounded-md border border-line bg-page px-2 py-1.5 text-sm" :disabled="busy">
                            <option v-for="choice in languages" :key="choice" :value="choice">{{ choice === 'auto' ? "the ticket's language" : choice }}</option>
                        </select>
                    </label>
                    <button type="button" class="btn btn-signal" :disabled="busy" @click="analyse">
                        {{ session ? 'Analyse again' : 'Analyse' }}
                    </button>
                    <button v-if="busy" type="button" class="btn" @click="post('stop')">Stop</button>
                    <button v-if="session && !busy" type="button" class="btn" :aria-expanded="closing" @click="closing = !closing">Close session</button>
                    <span v-if="session?.owner" class="text-sm text-working">
                        {{ session.owner }}'s session
                        <button type="button" class="ml-1 text-muted underline hover:text-ink" @click="post('take-over')">Take over</button>
                    </span>
                    <span v-if="session" class="ml-auto text-right font-mono text-xs text-muted">
                        ${{ session.cost_usd.toFixed(2) }} so far<br />
                        {{ tokens(session.tokens_in) }} tokens in<template v-if="session.tokens_cached"> ({{ tokens(session.tokens_cached) }} from cache)</template> · {{ tokens(session.tokens_out) }} out
                    </span>
                </div>
                <form v-if="closing && session && !busy" class="mt-3 space-y-2 rounded-md border border-line p-3" @submit.prevent="closeSession">
                    <label for="handover" class="text-sm">A note for whoever picks this up <span class="text-muted">(optional)</span></label>
                    <textarea id="handover" v-model="handover" rows="2" placeholder="Where it stands, what you tried, what is left…" class="w-full rounded-md border border-line bg-page px-3 py-2 text-sm placeholder:text-muted"></textarea>
                    <div class="flex gap-2"><button type="submit" class="btn btn-signal">Close the session</button><button type="button" class="btn" @click="closing = false">Cancel</button></div>
                </form>
                <div v-if="agentReady && !busy" class="mt-3">
                    <label for="note" class="text-sm text-muted">Your notes for the agent <span class="text-xs">(optional, goes with the analysis)</span></label>
                    <textarea
                        id="note"
                        v-model="note"
                        rows="2"
                        placeholder="What you know that the ticket does not say: the customer's service ID, a suspicion, what to check first…"
                        class="mt-1 w-full rounded-md border border-line bg-page px-3 py-2 text-sm placeholder:text-muted"
                    ></textarea>
                </div>
            </FoldingSection>

            <!-- The log -->
            <FoldingSection v-if="session" title="Log" remember="ticket.log">
                <div class="space-y-6">
                <template v-for="turn in session.turns" :key="turn.id">
                    <LogEntry v-if="turn.prompt" :time="short(turn.started_at ?? turn.finished_at).split(', ').pop()" :author="turn.by ?? 'you'">
                        <p class="whitespace-pre-wrap">{{ turn.prompt }}</p>
                    </LogEntry>
                    <LogEntry :time="turn.started_at ? short(turn.started_at).split(', ').pop() : '…'" author="agent">
                        <p class="text-xs font-medium tracking-wide uppercase" :class="stateTone[turn.state]">
                            {{ turnLabels[turn.kind] }} · {{ turn.state }}<template v-if="turn.cost_usd != null"> · ${{ turn.cost_usd.toFixed(2) }}</template>
                            <template v-if="turn.tokens_in != null"> · {{ tokens(turn.tokens_in) }} in · {{ tokens(turn.tokens_out) }} out</template>
                        </p>
                        <!-- While it works, every step as it happens; once done, the steps fold away into
                             one line and what it said stays. -->
                        <template v-if="turn.state === 'running'">
                            <template v-for="event in turn.events" :key="event.id">
                                <AgentText v-if="event.type === 'text'" :text="event.summary" />
                                <p v-else-if="event.type === 'tool'" class="font-mono text-xs break-all text-muted">{{ event.summary }}</p>
                                <p v-else class="text-sm text-signal">{{ event.summary }}</p>
                            </template>
                        </template>
                        <template v-else>
                            <!-- Done: its answer, and only that. What it did and said on the way, folded. -->
                            <AgentText v-if="answerOf(turn)" :text="answerOf(turn)" />
                            <p v-for="event in turn.events.filter((e) => e.type === 'error')" :key="event.id" class="text-sm text-signal">{{ event.summary }}</p>
                            <details v-if="steps(turn).length || notesOf(turn).length" class="text-xs text-muted">
                                <summary class="cursor-pointer hover:text-ink">{{ stepLine(turn) }}<template v-if="notesOf(turn).length"> · its notes on the way</template></summary>
                                <ol class="mt-1 space-y-1 border-l border-line pl-3">
                                    <li v-for="event in [...steps(turn), ...notesOf(turn)].sort((a, b) => a.id - b.id)" :key="event.id" :class="event.type === 'tool' ? 'font-mono break-all' : ''">
                                        <AgentText v-if="event.type === 'text'" :text="event.summary" />
                                        <template v-else>{{ event.summary }}</template>
                                    </li>
                                </ol>
                            </details>
                        </template>
                        <ActivityLine v-if="turn.state === 'running'" :now="lastTool" :tally="`${turn.events.filter((e) => e.type === 'tool').length} steps`" />
                        <p v-if="turn.state === 'queued'" class="text-sm text-muted">Waiting for a free agent…</p>
                        <Link v-if="turn.case_draft" :href="`/casebook/create?turn=${turn.id}`" class="btn btn-signal self-start">Review the case and write it down</Link>
                        <div v-if="turn.patch?.text" class="space-y-2 text-sm">
                            <!-- What it changes and the file: in view. Why, how to test, the risks, the diff: one click away. -->
                            <p class="font-medium">{{ turn.patch.meta.subject }}</p>
                            <p class="font-mono text-xs text-muted">
                                <template v-for="(file, index) in turn.patch.meta.files" :key="file.path">{{ index ? ' · ' : '' }}{{ file.path.split('/').pop() }} <span class="text-done">+{{ file.added }}</span> <span class="text-signal">−{{ file.removed }}</span></template>
                            </p>
                            <div class="flex flex-wrap items-center gap-2">
                                <a :href="`/tickets/${ticket.id}/turns/${turn.id}/patch`" class="btn btn-signal">Download the patch</a>
                                <span class="text-xs text-waiting">untested: the agent cannot run code</span>
                            </div>
                            <details class="rounded-md border border-line">
                                <summary class="cursor-pointer px-3 py-2 text-muted hover:text-ink">How to test it, the risks, and the diff</summary>
                                <div class="space-y-3 border-t border-line p-3">
                                    <p v-if="turn.patch.meta.summary" class="whitespace-pre-wrap">{{ turn.patch.meta.summary }}</p>
                                    <div v-if="turn.patch.meta.how_to_test"><p class="font-medium">How to test</p><p class="mt-1 whitespace-pre-wrap">{{ turn.patch.meta.how_to_test }}</p></div>
                                    <div v-if="turn.patch.meta.risks"><p class="font-medium">Risks</p><p class="mt-1 whitespace-pre-wrap">{{ turn.patch.meta.risks }}</p></div>
                                    <p class="text-xs text-muted">
                                        Made against {{ turn.patch.meta.system }} at {{ turn.patch.meta.base?.slice(0, 10) }}. In your checkout:
                                        <code class="font-mono">git am &lt;file&gt;</code>
                                    </p>
                                    <ul class="font-mono text-xs">
                                        <li v-for="file in turn.patch.meta.files" :key="file.path" class="break-all">{{ turn.patch.meta.system }}/{{ file.path }}</li>
                                    </ul>
                                    <pre class="overflow-x-auto rounded-md border border-line py-2 font-mono text-xs leading-5"><span v-for="(entry, index) in diffLines(turn.patch.text)" :key="index" class="block px-3" :class="diffTone[entry.kind]">{{ entry.line || ' ' }}</span></pre>
                                </div>
                            </details>
                        </div>
                    </LogEntry>
                </template>

                <form v-if="!busy" class="flex flex-col gap-2 pt-2 sm:flex-row" @submit.prevent="send">
                    <label for="message" class="sr-only">Message the agent</label>
                    <textarea
                        id="message"
                        v-model="message.text"
                        rows="2"
                        placeholder="Ask the agent: look at the batch job instead, the reporter says it worked last week…"
                        class="min-w-0 flex-1 rounded-md border border-line bg-page px-3 py-2 text-sm placeholder:text-muted"
                    ></textarea>
                    <div class="flex flex-wrap gap-2 sm:flex-col">
                        <button type="submit" class="btn btn-signal" :disabled="message.processing || !message.text.trim()">Send</button>
                        <button type="button" class="btn" @click="post('restate')">Update proposal</button>
                        <button type="button" class="btn" @click="post('case')">Draft a case</button>
                        <div class="flex gap-1">
                            <select v-if="systems.length > 1" v-model="patchSystem" class="min-w-0 rounded-md border border-line bg-page px-1.5 text-sm" aria-label="System for the patch">
                                <option :value="null">the proposal's system</option>
                                <option v-for="system in systems" :key="system.id" :value="system.id">{{ system.name }}</option>
                            </select>
                            <button type="button" class="btn flex-1" @click="preparePatch">Prepare a patch</button>
                        </div>
                    </div>
                </form>
                </div>
            </FoldingSection>

            <!-- Earlier sessions: the work, not the conversation -->
            <FoldingSection v-if="history.length" title="Earlier sessions" remember="ticket.history" class="text-sm">
                <ul class="divide-y divide-line">
                    <li v-for="past in history" :key="past.id" class="py-3 first:pt-0 last:pb-0">
                        <p class="flex flex-wrap gap-x-3 gap-y-1">
                            <span class="font-medium">{{ short(past.started_at) }} – {{ short(past.closed_at) }}</span>
                            <span class="font-mono text-xs leading-5 text-muted">
                                ${{ past.cost_usd.toFixed(2) }} · {{ tokens(past.tokens_in) }} in · {{ tokens(past.tokens_out) }} out
                            </span>
                        </p>
                        <p class="mt-1 text-muted">
                            {{ past.turns }} {{ past.turns === 1 ? 'turn' : 'turns' }}<template v-if="past.questions">, {{ past.questions }} of them your questions</template>
                            · {{ past.steps }} steps<template v-if="past.minutes"> · {{ past.minutes }} min of agent time</template>
                            <template v-if="past.case_drafted"> · drafted a case</template>
                            <template v-if="past.started_by"> · started by {{ past.started_by }}</template><template v-if="past.closed_by">, closed by {{ past.closed_by }}</template>
                        </p>
                        <p v-if="past.handover_note" class="mt-1 whitespace-pre-wrap"><span class="text-muted">Note:</span> {{ past.handover_note }}</p>
                        <p v-if="past.cause" class="mt-1">
                            <span class="font-mono text-xs">{{ past.system }}</span>
                            <span class="text-muted"> · {{ past.confidence }} confidence · </span>{{ past.cause }}
                        </p>
                    </li>
                </ul>
            </FoldingSection>

            <!-- The proposal -->
            <FoldingSection v-if="proposal" id="proposal" title="Proposal" remember="ticket.proposal" class="scroll-mt-28 text-sm">
                <template #aside>
                    <p class="font-mono text-xs" :class="confidenceTone[proposal.confidence]">{{ proposal.confidence }} confidence</p>
                </template>
                <div class="space-y-4">
                <!-- The brief above says what it found; here: the full proposal, folded, and the reply, which is what you act on. -->
                <details class="rounded-md border border-line">
                    <summary class="cursor-pointer px-3 py-2 text-muted hover:text-ink">The full proposal: problem, cause, fix, evidence, commands</summary>
                    <div class="space-y-4 border-t border-line p-3">
                    <p v-if="proposal.headline" class="font-medium">{{ proposal.headline }}</p>
                    <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2">
                        <dt class="text-muted">Problem</dt><dd>{{ proposal.problem }}</dd>
                        <dt class="text-muted">Cause</dt><dd class="whitespace-pre-wrap">{{ proposal.cause }}</dd>
                        <dt class="text-muted">Fix</dt><dd><AgentText :text="proposal.fix" /></dd>
                        <template v-if="proposal.workaround"><dt class="text-muted">Meanwhile</dt><dd>{{ proposal.workaround }}</dd></template>
                        <template v-if="proposal.questions?.length"><dt class="text-muted">Open</dt><dd><ul class="list-disc pl-4"><li v-for="(question, index) in proposal.questions" :key="index">{{ question }}</li></ul></dd></template>
                    </dl>

                    <details class="rounded-md border border-line">
                        <summary class="cursor-pointer px-3 py-2 text-muted hover:text-ink">
                            Details: {{ [
                                proposal.evidence?.length ? `${proposal.evidence.length} pieces of evidence` : null,
                                proposal.commands?.length ? `${proposal.commands.length} command${proposal.commands.length === 1 ? '' : 's'}` : null,
                                proposal.other_causes?.length ? `${proposal.other_causes.length} other cause${proposal.other_causes.length === 1 ? '' : 's'}` : null,
                                'why this confidence',
                            ].filter(Boolean).join(', ') }}
                        </summary>
                        <div class="space-y-4 border-t border-line p-3">
                            <p class="text-muted"><span class="font-mono text-xs">{{ proposal.system }}</span> · {{ proposal.confidence }} confidence: {{ proposal.confidence_reason }}</p>
                            <div v-if="proposal.evidence?.length">
                                <p class="font-medium">Evidence</p>
                                <ul class="mt-1 space-y-1.5">
                                    <li v-for="(item, index) in proposal.evidence" :key="index">
                                        <span class="rounded-sm border border-line bg-sunken px-1.5 py-0.5 font-mono text-xs break-all">{{ item.reference }}</span>
                                        <span class="ml-2 text-muted">{{ item.note }}</span>
                                    </li>
                                </ul>
                            </div>
                            <div v-if="proposal.commands?.length">
                                <p class="font-medium">Commands</p>
                                <ol class="mt-2 space-y-4">
                                    <li v-for="(command, index) in proposal.commands" :key="index">
                                        <p class="flex flex-wrap items-baseline gap-x-2 text-sm">
                                            <span
                                                class="rounded-[3px] border px-1.5 font-mono text-[0.66rem] leading-5 font-medium tracking-wide uppercase"
                                                :class="command.kind === 'change' ? 'border-signal text-signal' : 'border-done text-done'"
                                            >{{ command.kind === 'change' ? 'changes' : 'reads' }}</span>
                                            <span>{{ command.purpose }}</span>
                                            <span class="text-muted">· {{ command.where }}</span>
                                        </p>
                                        <CodeBlock :code="command.command" class="mt-1.5" />
                                        <p v-if="command.undo" class="mt-1 text-xs text-muted">Undo: <code class="font-mono">{{ command.undo }}</code></p>
                                    </li>
                                </ol>
                            </div>
                            <div v-if="proposal.other_causes?.length">
                                <p class="font-medium">Other causes</p>
                                <ul class="mt-1 list-disc pl-5 text-muted"><li v-for="(other, index) in proposal.other_causes" :key="index">{{ other }}</li></ul>
                            </div>
                            <p v-if="proposal.casebook" class="text-muted">
                                Casebook: <template v-if="proposal.casebook.case">case {{ proposal.casebook.case }}, {{ proposal.casebook.fit }}</template><template v-else>{{ proposal.casebook.fit || 'new' }}</template>
                            </p>
                        </div>
                    </details>
                    </div>
                </details>

                <form id="reply-form" class="border-t border-line pt-5" @submit.prevent="toOutbox(false)">
                    <label for="reply" class="font-medium">Reply draft<span v-if="proposal.reply_language" class="font-normal text-muted"> · {{ proposal.reply_language }}</span></label>
                    <textarea id="reply" v-model="draft.body" rows="8" class="mt-2 w-full rounded-md border border-line bg-page px-3 py-2 text-sm"></textarea>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <template v-if="outboxReady && ticket.space.type === 'service'">
                            <label class="flex items-center gap-1.5"><input v-model="draft.visibility" type="radio" value="public" /> Reply to customer</label>
                            <label class="flex items-center gap-1.5"><input v-model="draft.visibility" type="radio" value="internal" /> Internal note</label>
                        </template>
                        <button type="button" class="btn ml-auto" :class="{ 'btn-signal': !outboxReady }" :disabled="!draft.body.trim()" @click="copyReply">
                            {{ copied ? 'Copied' : 'Copy reply' }}
                        </button>
                        <template v-if="outboxReady">
                            <button type="submit" class="btn" :disabled="draft.processing || !draft.body.trim()">Send to the outbox as a draft</button>
                            <button type="button" class="btn btn-signal" :disabled="draft.processing || !draft.body.trim()" @click="toOutbox(true)">
                                Draft and open in the outbox <span aria-hidden="true">↗</span>
                            </button>
                        </template>
                    </div>
                    <p v-if="outboxReady" class="mt-2 text-muted">Nothing goes to Jira from here: the draft waits in the outbox until you send it there.</p>
                    <p v-else class="mt-2 text-muted">
                        Nothing goes to Jira from here: copy the reply and paste it into the ticket in
                        <a :href="ticket.url" target="_blank" rel="noopener" class="underline hover:text-ink">{{ openLabel }} ↗</a>.
                    </p>
                </form>
                </div>
            </FoldingSection>
        </section>
    </div>
</template>
