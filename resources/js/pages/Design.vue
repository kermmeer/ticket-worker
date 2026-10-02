<script setup>
import { nextTick, onMounted, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import ActivityLine from '../components/ActivityLine.vue';
import EvidenceChip from '../components/EvidenceChip.vue';
import LogEntry from '../components/LogEntry.vue';
import StateBadge from '../components/StateBadge.vue';
import StatusLabel from '../components/StatusLabel.vue';
import TicketKey from '../components/TicketKey.vue';
import TicketRow from '../components/TicketRow.vue';
import { theme } from '../theme.js';

// The specimen: the look of Ticket Worker on sample data, so both themes can be judged
// before there is anything real to show. Nothing on this page comes from Jira.

const roles = [
    { name: 'page', use: 'page' },
    { name: 'surface', use: 'cards, panes' },
    { name: 'sunken', use: 'sidebars, code' },
    { name: 'line', use: 'borders' },
    { name: 'ink', use: 'text' },
    { name: 'muted', use: 'secondary text' },
    { name: 'signal', use: 'needs you, the main action' },
    { name: 'working', use: 'agent busy' },
    { name: 'waiting', use: 'waiting on someone else' },
    { name: 'done', use: 'closed, confirmed' },
];

// Read from the stylesheet itself, so the numbers shown can never drift from it.
const values = ref({});

function readValues() {
    const style = getComputedStyle(document.documentElement);
    values.value = Object.fromEntries(roles.map((role) => [role.name, style.getPropertyValue(`--${role.name}`).trim()]));
}

onMounted(readValues);
watch(theme, () => nextTick(readValues));

const states = ['needs-you', 'new-activity', 'working', 'sleeping', 'not-analysed', 'parked', 'closed'];

const toneSamples = [
    ['To Do', 'grey'],
    ['In Progress', 'blue'],
    ['Waiting for customer', 'amber'],
    ['Waiting for support', 'rose'],
    ['On Hold', 'violet'],
    ['Done', 'green'],
    ['Escalated to vendor', 'teal'],
];

const minutesAgo = (minutes) => new Date(Date.now() - minutes * 60000).toISOString();
const space = { label: 'Support', colour: 'teal' };
const tickets = [
    { id: 1, key: 'SUP-1234', summary: 'Invoices not sent after a credit note', status: 'Waiting for support', status_tone: 'rose', priority: 'High', reporter: 'Finance team', updated_at: minutesAgo(4), group: 'needs-you', url: '#' },
    { id: 2, key: 'SUP-1241', summary: 'Export to accounting stops at 1,000 rows', status: 'In Progress', status_tone: 'blue', priority: 'Medium', reporter: 'Accounting', updated_at: minutesAgo(50), group: 'working', url: '#' },
    { id: 3, key: 'SUP-1198', summary: 'Password reset mail arrives twice', status: 'To Do', status_tone: 'grey', priority: 'Low', reporter: 'Helpdesk', updated_at: minutesAgo(600), group: 'not-analysed', url: '#' },
    { id: 4, key: 'SUP-1236', summary: 'Delivery address reverts after saving', status: 'Waiting for customer', status_tone: 'amber', priority: 'Medium', reporter: 'Shop team', updated_at: minutesAgo(2900), group: 'sleeping', url: '#' },
];

const rail = ['Synced', 'Analysed', 'Proposed', 'Working', 'Closed'];
const railAt = 2;
</script>

<template>
    <Head title="Design" />

    <p class="eyebrow">Design</p>
    <h1 class="display mt-3 text-5xl leading-[1.05] sm:text-6xl">A case file.</h1>
    <p class="mt-5 max-w-2xl text-lg text-muted">
        Each ticket is a case, the agent's findings are evidence, and the conversation reads as a log. This page shows
        the look on sample data, in whichever theme you pick at the top. Nothing here is real.
    </p>

    <!-- Colours -->
    <section class="mt-16">
        <h2 class="eyebrow">Colours, by role</h2>
        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
            <div v-for="role in roles" :key="role.name" class="card overflow-hidden">
                <div class="h-16 border-b border-line" :style="{ background: `var(--${role.name})` }"></div>
                <div class="p-3">
                    <p class="font-mono text-sm">{{ role.name }}</p>
                    <p class="mt-0.5 text-xs text-muted">{{ role.use }}</p>
                    <p class="mt-2 font-mono text-xs text-muted">{{ values[role.name] }}</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Type -->
    <section class="mt-16">
        <h2 class="eyebrow">Type</h2>
        <div class="card mt-4 divide-y divide-line">
            <div class="grid gap-2 p-5 sm:grid-cols-[10rem_1fr] sm:items-baseline">
                <p class="font-mono text-xs text-muted">Geist 600, tight</p>
                <p class="display text-4xl leading-tight">Invoices not sent after a credit note</p>
            </div>
            <div class="grid gap-2 p-5 sm:grid-cols-[10rem_1fr] sm:items-baseline">
                <p class="font-mono text-xs text-muted">Geist 400</p>
                <p class="max-w-prose">
                    Since Monday, customers who received a credit note no longer get their next invoice by mail. The
                    invoice itself is created and visible in the portal.
                </p>
            </div>
            <div class="grid gap-2 p-5 sm:grid-cols-[10rem_1fr] sm:items-baseline">
                <p class="font-mono text-xs text-muted">Geist Mono</p>
                <p class="font-mono text-sm break-all">app/Services/CreditNoteService.php:212 · 9f3e1c2 · SUP-1234</p>
            </div>
        </div>
    </section>

    <!-- States -->
    <section class="mt-16">
        <h2 class="eyebrow">States: always a word and a shape, never colour alone</h2>
        <div class="mt-4 flex flex-wrap gap-2">
            <StateBadge v-for="state in states" :key="state" :state="state" />
        </div>
        <h2 class="eyebrow mt-8">Jira statuses: a stamp like the key, a colour per status</h2>
        <div class="mt-4 flex flex-wrap gap-1.5">
            <StatusLabel v-for="[name, tone] in toneSamples" :key="name" :status="name" :tone="tone" />
        </div>
    </section>

    <!-- Overview rows -->
    <section class="mt-16">
        <h2 class="eyebrow">The overview</h2>
        <ul class="card mt-4 divide-y divide-line">
            <li v-for="ticket in tickets" :key="ticket.id" :class="{ 'working-edge': ticket.group === 'working' }">
                <TicketRow :ticket="ticket" :space="space" />
            </li>
        </ul>
    </section>

    <!-- A case -->
    <section class="mt-16">
        <h2 class="eyebrow">A ticket, being worked</h2>

        <div class="card mt-4 overflow-hidden">
            <header class="border-b border-line p-5">
                <div class="flex flex-wrap items-center gap-3">
                    <TicketKey value="SUP-1234" />
                    <StateBadge state="needs-you" />
                    <span class="text-sm text-muted">System billing · In progress in Jira</span>
                </div>
                <p class="display mt-3 text-3xl leading-tight sm:text-4xl">Invoices not sent after a credit note</p>
                <ol class="mt-5 grid grid-cols-5 gap-1.5" aria-label="Progress">
                    <li v-for="(step, index) in rail" :key="step">
                        <div class="h-1 rounded-full" :class="index < railAt ? 'bg-ink/60' : index === railAt ? 'bg-signal' : 'bg-line'"></div>
                        <p class="mt-1.5 font-mono text-[0.68rem]" :class="index === railAt ? 'font-medium text-ink' : 'text-muted'">
                            {{ step }}<span v-if="index === railAt" class="sr-only"> (current)</span>
                        </p>
                    </li>
                </ol>
            </header>

            <div class="grid lg:grid-cols-[17rem_1fr_19rem]">
                <!-- The ticket -->
                <aside class="space-y-4 border-b border-line bg-sunken/60 p-5 text-sm lg:border-r lg:border-b-0">
                    <p class="eyebrow">Ticket</p>
                    <p class="text-muted">Finance team · 3 days ago · High</p>
                    <p>
                        Since Monday, customers who received a credit note no longer get their next invoice by mail. The
                        invoice is created and visible in the portal.
                    </p>
                    <div>
                        <p class="font-medium">Comments (2)</p>
                        <p class="mt-1 text-muted">Second line: reproduced with customer 4411, see the screenshot.</p>
                    </div>
                    <div>
                        <p class="font-medium">Attachments (2)</p>
                        <ul class="mt-1 space-y-1 font-mono text-xs text-muted">
                            <li>credit-note-flow.png</li>
                            <li>mail-log.txt</li>
                        </ul>
                    </div>
                </aside>

                <!-- The log -->
                <div class="space-y-6 border-b border-line p-5 lg:border-b-0">
                    <p class="eyebrow">Log</p>
                    <LogEntry time="14:02" author="agent">
                        <p>
                            The credit note path returns before the mail is queued. <code class="font-mono text-[0.85em]">CreditNoteService::apply()</code>
                            was changed last Thursday to stop as soon as the balance reaches zero, and the invoice mail is
                            dispatched after that point.
                        </p>
                        <div class="flex flex-wrap gap-1.5">
                            <EvidenceChip label="app/Services/CreditNoteService.php:212" />
                            <EvidenceChip kind="commit" label="9f3e1c2" />
                            <EvidenceChip kind="quote" label="“no longer get their next invoice”" />
                        </div>
                        <p class="font-mono text-xs text-muted">6 files read · 2 searches</p>
                    </LogEntry>
                    <LogEntry time="14:05" author="you">
                        <p>What about the nightly batch job? It sends reminders too.</p>
                    </LogEntry>
                    <LogEntry time="14:06" author="agent">
                        <ActivityLine now="reading app/Jobs/SendInvoiceReminders.php" tally="14 files read · 3 searches" />
                    </LogEntry>
                    <form class="flex flex-wrap gap-2 pt-2" @submit.prevent>
                        <label class="sr-only" for="specimen-message">Message the agent</label>
                        <input
                            id="specimen-message"
                            type="text"
                            placeholder="Message the agent…"
                            class="min-w-0 flex-1 rounded-md border border-line bg-surface px-3 py-2 text-sm placeholder:text-muted"
                        />
                        <button type="submit" class="btn btn-signal">Send</button>
                        <button type="button" class="btn">Stop</button>
                    </form>
                </div>

                <!-- The proposal -->
                <aside class="space-y-5 p-5 text-sm lg:border-l lg:border-line">
                    <div class="flex items-center justify-between gap-3">
                        <p class="eyebrow">Proposal v2</p>
                        <p class="font-mono text-xs"><span class="text-done">●●●</span> high confidence</p>
                    </div>
                    <div>
                        <p class="font-medium">Cause</p>
                        <p class="mt-1 text-muted">
                            <code class="font-mono text-[0.85em] text-ink">apply()</code> returns early when the balance hits
                            zero, skipping <code class="font-mono text-[0.85em] text-ink">InvoiceMailer::queue()</code>.
                        </p>
                    </div>
                    <div>
                        <p class="font-medium">Evidence</p>
                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                            <EvidenceChip label="CreditNoteService.php:212" />
                            <EvidenceChip kind="commit" label="9f3e1c2" />
                        </div>
                    </div>
                    <div>
                        <p class="font-medium">Fix</p>
                        <p class="mt-1 text-muted">Queue the mail before the zero-balance return, or move that check below it.</p>
                    </div>
                    <div>
                        <p class="font-medium">Workaround</p>
                        <p class="mt-1 text-muted">Resend from the portal: Invoices → Resend.</p>
                    </div>
                    <div>
                        <p class="font-medium">Reply draft <span class="font-normal text-muted">· in the ticket's language</span></p>
                        <p class="mt-1 border-l-2 border-line pl-3 text-muted italic">
                            Bedankt voor de melding. We hebben de oorzaak gevonden: na een creditnota werd de volgende
                            factuur niet meer verstuurd…
                        </p>
                    </div>
                    <p class="border-t border-line pt-4 font-mono text-xs text-muted">$1.84 so far · 3 turns</p>
                </aside>
            </div>
        </div>
    </section>

    <!-- Actions -->
    <section class="mt-16">
        <h2 class="eyebrow">Actions and keys</h2>
        <div class="mt-4 flex flex-wrap items-center gap-3">
            <button type="button" class="btn btn-signal">Analyse</button>
            <button type="button" class="btn">Update proposal</button>
            <button type="button" class="btn">Close session</button>
        </div>
        <p class="mt-5 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-muted">
            <span><span class="kbd">/</span> search</span>
            <span><span class="kbd">j</span> <span class="kbd">k</span> move</span>
            <span><span class="kbd">Enter</span> open</span>
        </p>
    </section>
</template>
