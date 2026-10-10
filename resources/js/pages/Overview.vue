<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import FoldingSection from '../components/FoldingSection.vue';
import SpaceLabel from '../components/SpaceLabel.vue';
import StatusLabel from '../components/StatusLabel.vue';
import TicketRow from '../components/TicketRow.vue';
import TicketKey from '../components/TicketKey.vue';
import { reachable } from '../connection.js';
import { ago, short } from '../time.js';
import { byDueNext, dueAt, phrase, shortName, tone, urgency } from '../sla.js';

const props = defineProps({
    hasSpaces: { type: Boolean, required: true },
    spaces: { type: Array, required: true },
    tickets: { type: Array, required: true },
});

// What each ticket needs from you (CONCEPT.md §6). Sleeping tickets wait on the
// requester; they get a segment of their own below the rest, folded away.
const groups = [
    { key: 'needs-you', label: 'Needs you', hint: 'A proposal is ready, or the agent asked you something' },
    { key: 'working', label: 'Working', hint: 'An agent is busy on it right now' },
    { key: 'new-activity', label: 'New activity', hint: 'Commented on or changed since the last analysis' },
    { key: 'not-analysed', label: 'Not analysed', hint: 'Synced, nothing started yet' },
    { key: 'parked', label: 'Parked', hint: 'Session closed, ticket still open in Jira' },
    { key: 'sleeping', label: 'Sleeping', hint: 'Waiting on the requester' },
];
const awake = groups.filter((group) => group.key !== 'sleeping');

// Folded segments below the rest: tickets waiting on the requester, and tickets you hid.
const folds = [
    { key: 'sleeping', label: 'Sleeping', hint: 'Waiting on the requester' },
    { key: 'hidden', label: 'Hidden', hint: 'Tickets you will not take on; they stay hidden until you unhide them' },
];

// null: every space; 'mine': the spaces you follow; or one space's id. With spaces followed,
// the overview starts with yours.
const followed = computed(() => new Set(props.spaces.filter((space) => space.followed).map((space) => space.id)));
// The choice is kept in this browser, so a refresh or a new tab opens on it. A space you can
// no longer see (removed, or no longer yours) falls back to the default.
const stored = (key) => {
    try {
        return JSON.parse(localStorage.getItem(key) ?? 'null');
    } catch {
        return null;
    }
};
const keep = (key, value) => {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Storage refused: the choice holds for this visit.
    }
};
const savedSpace = stored('overview.space');
const spaceFilter = ref(
    savedSpace === 'all' ? null
    : savedSpace === 'mine' && props.spaces.some((space) => space.followed) ? 'mine'
    : typeof savedSpace === 'number' && props.spaces.some((space) => space.id === savedSpace) ? savedSpace
    : props.spaces.some((space) => space.followed) ? 'mine' : null,
);
watch(spaceFilter, (value) => keep('overview.space', value ?? 'all'));
// Assigned to you in Jira, once your account is linked to your Jira user.
const myJira = computed(() => usePage().props.me?.jira_account_id ?? null);
const mineOnly = ref(stored('overview.mine') === true);
watch(mineOnly, (value) => keep('overview.mine', value));

const inSpace = (ticket) => spaceFilter.value === null || (spaceFilter.value === 'mine' ? followed.value.has(ticket.space_id) : ticket.space_id === spaceFilter.value);
const statusFilter = ref(null);
const search = ref('');
const searchBox = ref(null);

const spacesById = computed(() => Object.fromEntries(props.spaces.map((space) => [space.id, space])));

// Every status in view, with its colour and how many tickets are in it.
const statuses = computed(() => {
    const counts = new Map();
    for (const ticket of props.tickets) {
        if (!inSpace(ticket)) {
            continue;
        }
        const entry = counts.get(ticket.status) ?? { status: ticket.status, tone: ticket.status_tone, count: 0 };
        entry.count++;
        counts.set(ticket.status, entry);
    }
    return [...counts.values()].sort((a, b) => b.count - a.count);
});

const shown = computed(() => {
    const query = search.value.trim().toLowerCase();
    return props.tickets.filter(
        (ticket) =>
            inSpace(ticket) &&
            (!mineOnly.value || ticket.mine) &&
            (statusFilter.value === null || ticket.status === statusFilter.value) &&
            (query === '' || ticket.key.toLowerCase().includes(query) || ticket.summary.toLowerCase().includes(query)),
    );
});

// Last update (as Jira sorts them), newest first, the next SLA deadline you can still
// make, or the furthest past one.
const sorts = { updated: 'Last update', created: 'Newest', due: 'SLA, due next', sla: 'SLA, most overdue' };

function savedSort() {
    try {
        const value = localStorage.getItem('overview.sort');
        return value in sorts ? value : 'updated';
    } catch {
        return 'updated';
    }
}

const sort = ref(savedSort());

function setSort(value) {
    sort.value = value;
    try {
        localStorage.setItem('overview.sort', value);
    } catch {
        // Storage refused: it still works for this visit.
    }
}

const sorted = computed(() => {
    const list = [...shown.value];
    if (sort.value === 'created') {
        list.sort((a, b) => (b.created_at ?? '').localeCompare(a.created_at ?? ''));
    } else if (sort.value === 'due') {
        const now = Date.now();
        list.sort((a, b) => byDueNext(a, b, now) || (b.updated_at ?? '').localeCompare(a.updated_at ?? ''));
    } else if (sort.value === 'sla') {
        list.sort((a, b) => urgency(a) - urgency(b) || (b.updated_at ?? '').localeCompare(a.updated_at ?? ''));
    }
    return list;
});

const inGroup = computed(() =>
    Object.fromEntries([...groups, folds[1]].map((group) => [group.key, sorted.value.filter((ticket) => ticket.group === group.key)])),
);

const perSpace = computed(() => {
    const counts = {};
    for (const ticket of props.tickets) {
        counts[ticket.space_id] = (counts[ticket.space_id] ?? 0) + 1;
    }
    return counts;
});

// The totals follow the space chosen (and Assigned to me); status and search narrow the list
// below, not the board's count.
const onBoard = computed(() => props.tickets.filter((ticket) => inSpace(ticket) && (!mineOnly.value || ticket.mine)));
const count = (group) => onBoard.value.filter((ticket) => ticket.group === group).length;
const sleepingCount = computed(() => count('sleeping'));
const hiddenCount = computed(() => count('hidden'));
const outboxCount = computed(() => onBoard.value.filter((ticket) => ticket.outbox?.length).length);
const awakeCount = computed(() => onBoard.value.length - sleepingCount.value - hiddenCount.value);

// The deadlines you can still make, closest first, whatever group the ticket is in.
// Tickets only overdue are left out: they are counted, not chased.
const dueSoon = computed(() => {
    const now = Date.now();
    const live = shown.value.filter((ticket) => ticket.group !== 'hidden');
    return {
        next: live.filter((ticket) => dueAt(ticket, now) !== null).sort((a, b) => byDueNext(a, b, now)).slice(0, 5),
        overdue: live.filter((ticket) => dueAt(ticket, now) === null && ticket.slas?.some((sla) => sla.state === 'breached')).length,
    };
});
const firstRunning = (ticket) => ticket.slas.filter((sla) => sla.state === 'running').sort((a, b) => Date.parse(a.due_at ?? 0) - Date.parse(b.due_at ?? 0))[0];

// Quick scans for everything in view that has none yet: the filters above decide what "in
// view" is. They go one at a time, after any analysis, so a batch costs time, not your turn.
const toScan = computed(() =>
    shown.value.filter((ticket) => ticket.group !== 'hidden' && (!ticket.quick_scan || ticket.quick_scan.state === 'failed')),
);
const scanBatch = computed(() => {
    const states = props.tickets.map((ticket) => ticket.quick_scan?.state);
    return { waiting: states.filter((state) => state === 'pending').length, running: states.filter((state) => state === 'queued' || state === 'running').length };
});
// What one cost so far, for the estimate; the first real one was $0.12.
const costPerScan = computed(() => {
    const costs = props.tickets.map((ticket) => ticket.quick_scan?.cost_usd).filter((cost) => cost != null);
    return costs.length ? costs.reduce((a, b) => a + b, 0) / costs.length : 0.12;
});

function scanAll() {
    const count = toScan.value.length;
    const hours = count / 60;
    const time = hours >= 1 ? `about ${Math.round(hours)} hour${Math.round(hours) === 1 ? '' : 's'}` : `about ${count} minutes`;
    if (confirm(`Quick scan ${count} tickets?\n\nAbout $${(count * costPerScan.value).toFixed(2)} at $${costPerScan.value.toFixed(2)} each, one at a time: ${time}. Your analyses go first, and you can stop the rest at any time.`)) {
        router.post('/quick-scans', { ids: toScan.value.map((ticket) => ticket.id) }, { preserveScroll: true, preserveState: true });
    }
}

function stopScans() {
    router.delete('/quick-scans', { preserveScroll: true, preserveState: true });
}

// A group shows its first forty, then more on request: hundreds of rows at once make a
// phone crawl, and nobody reads past the first screens anyway. A search shows every match.
const BATCH = 40;
const shownCounts = ref({});
const shownOf = (key) => (searching.value ? Infinity : (shownCounts.value[key] ?? BATCH));
function showMore(key) {
    shownCounts.value = { ...shownCounts.value, [key]: shownOf(key) + BATCH };
}

// While you search or show one status, every section opens, so no match stays folded away.
const searching = computed(() => statusFilter.value !== null || search.value.trim() !== '');

function pickStatus(status) {
    statusFilter.value = statusFilter.value === status ? null : status;
}

function pickSpace(id) {
    spaceFilter.value = id;
    statusFilter.value = null;
}

// "/" goes to search, unless you are already typing somewhere.
function onKey(event) {
    if (event.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) {
        event.preventDefault();
        searchBox.value?.focus();
    }
}

// The page keeps itself current: every 30 seconds while it is in view, and at once when you
// come back to the tab. A sync, a message scheduled in the outbox or a status changed in
// Jira shows without reloading; filters, search and scroll stay as they are.
const REFRESH_MS = 30000;
// A quick scan takes a minute or so: while one runs, look more often.
const SCANNING_MS = 5000;
let refresher = null;
let scanWatcher = null;

function refresh() {
    if (document.visibilityState === 'visible' && reachable()) {
        // Quietly: no progress bar, and rows are keyed, so only what changed is redrawn.
        router.reload({ only: ['tickets', 'spaces', 'hasSpaces'], preserveScroll: true, preserveState: true, showProgress: false });
    }
}

function onVisible() {
    if (document.visibilityState === 'visible') {
        refresh();
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKey);
    document.addEventListener('visibilitychange', onVisible);
    refresher = setInterval(refresh, REFRESH_MS);
    scanWatcher = setInterval(() => props.tickets.some((ticket) => ['queued', 'running'].includes(ticket.quick_scan?.state)) && refresh(), SCANNING_MS);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    document.removeEventListener('visibilitychange', onVisible);
    clearInterval(refresher);
    clearInterval(scanWatcher);
});
</script>

<template>
    <Head title="Overview" />

    <template v-if="!hasSpaces || spaces.length === 0">
        <p class="eyebrow">Overview</p>
        <h1 class="display mt-3 text-5xl leading-[1.05] sm:text-6xl">No cases yet.</h1>
        <p class="mt-5 max-w-2xl text-lg text-muted">
            Ticket Worker lists the open tickets of the Jira spaces you set up, grouped by what they need from you.
            <template v-if="!hasSpaces">There is no space yet: set one up and its tickets appear here.</template>
            <template v-else>No space is active: activate one and its tickets appear here.</template>
        </p>
        <div class="mt-7 flex flex-wrap gap-3">
            <Link :href="hasSpaces ? '/spaces' : '/spaces/create'" class="btn btn-signal">
                {{ hasSpaces ? 'Go to the spaces' : 'Set up a space' }}
            </Link>
            <Link href="/docs/concept" class="btn">Read the concept</Link>
        </div>
    </template>

    <template v-else>
        <p class="eyebrow">Overview</p>
        <h1 class="display mt-3 text-5xl leading-[1.05] sm:text-6xl">
            {{ awakeCount }} open {{ awakeCount === 1 ? 'ticket' : 'tickets' }}
        </h1>

        <ul class="mt-4 flex flex-wrap gap-x-6 gap-y-1 text-sm text-muted">
            <li v-if="sleepingCount">and {{ sleepingCount }} sleeping, waiting on the requester</li>
            <li v-if="hiddenCount">{{ hiddenCount }} hidden by you</li>
            <li v-if="outboxCount">{{ outboxCount }} with a message waiting in the outbox</li>
            <li v-for="space in spaces" :key="space.id">
                <SpaceLabel :label="space.label" :colour="space.colour" class="text-ink" />
                <span v-if="space.sync_error" class="text-signal"> · sync failed: {{ space.sync_error }}</span>
                <template v-else-if="space.synced_at">
                    <span> · synced {{ ago(space.synced_at) }}</span>
                    <!-- A rules mistake empties a space at the next sync; say so where it shows. -->
                    <Link v-if="!perSpace[space.id]" :href="`/spaces/${space.id}/edit`" class="text-signal underline"> · its rules match no tickets</Link>
                </template>
                <span v-else> · first sync on its way</span>
            </li>
        </ul>

        <section aria-label="Ticket groups" class="mt-10 grid grid-cols-2 gap-px overflow-hidden rounded-md border border-line bg-line sm:grid-cols-3 xl:grid-cols-6">
            <div v-for="group in groups" :key="group.key" class="bg-surface p-3 sm:p-4">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="font-medium">{{ group.label }}</h2>
                    <span class="font-mono text-sm" :class="inGroup[group.key].length ? 'text-ink' : 'text-muted'">
                        {{ inGroup[group.key].length }}
                    </span>
                </div>
                <p class="mt-2 hidden text-sm text-muted sm:block">{{ group.hint }}</p>
            </div>
        </section>

        <div class="mt-10 flex flex-wrap items-center gap-2">
            <button
                v-if="myJira"
                type="button"
                class="rounded-full border px-3 py-1 text-sm transition-colors"
                :class="mineOnly ? 'border-signal bg-signal text-page' : 'border-line text-muted hover:text-ink'"
                :aria-pressed="mineOnly"
                @click="mineOnly = !mineOnly"
            >
                Assigned to me
            </button>
            <button
                v-if="followed.size"
                type="button"
                class="rounded-full border px-3 py-1 text-sm transition-colors"
                :class="spaceFilter === 'mine' ? 'border-ink bg-ink text-page' : 'border-line text-muted hover:text-ink'"
                :aria-pressed="spaceFilter === 'mine'"
                @click="pickSpace('mine')"
            >
                My spaces
            </button>
            <button
                type="button"
                class="rounded-full border px-3 py-1 text-sm transition-colors"
                :class="spaceFilter === null ? 'border-ink bg-ink text-page' : 'border-line text-muted hover:text-ink'"
                :aria-pressed="spaceFilter === null"
                @click="pickSpace(null)"
            >
                All spaces
            </button>
            <button
                v-for="space in spaces"
                :key="space.id"
                type="button"
                class="rounded-full border px-3 py-1 text-sm transition-colors"
                :class="spaceFilter === space.id ? 'border-ink bg-ink text-page' : 'border-line text-muted hover:text-ink'"
                :aria-pressed="spaceFilter === space.id"
                @click="pickSpace(space.id)"
            >
                <SpaceLabel :label="space.label" :colour="space.colour" />
            </button>
            <label class="ml-auto flex items-center gap-2 text-sm">
                <span class="text-muted">Sort</span>
                <select :value="sort" class="rounded-md border border-line bg-surface px-2 py-1.5 text-sm" @change="setSort($event.target.value)">
                    <option v-for="(label, value) in sorts" :key="value" :value="value">{{ label }}</option>
                </select>
            </label>
            <label class="flex w-full items-center gap-2 sm:w-80">
                <span class="sr-only">Search by key or summary</span>
                <input
                    ref="searchBox"
                    v-model="search"
                    type="search"
                    placeholder="Search key or summary"
                    class="w-full rounded-md border border-line bg-surface px-3 py-1.5 text-sm placeholder:text-muted"
                />
                <span class="kbd hidden sm:inline-block" aria-hidden="true">/</span>
            </label>
        </div>

        <!-- One status at a time: click it again, or "Every status", to see them all. -->
        <div class="mt-3 flex flex-wrap items-center gap-1.5" role="group" aria-label="Show only one status">
            <button
                type="button"
                class="rounded-[3px] border px-1.5 font-mono text-[0.66rem] leading-5 font-medium tracking-wide uppercase transition-colors"
                :class="statusFilter === null ? 'border-ink bg-ink text-page' : 'border-line text-muted hover:text-ink'"
                :aria-pressed="statusFilter === null"
                @click="statusFilter = null"
            >
                Every status
            </button>
            <button
                v-for="entry in statuses"
                :key="entry.status"
                type="button"
                class="rounded-[3px]"
                :aria-pressed="statusFilter === entry.status"
                @click="pickStatus(entry.status)"
            >
                <StatusLabel :status="entry.status" :tone="entry.tone" :filled="statusFilter === entry.status">
                    <span class="ml-1.5 opacity-75">{{ entry.count }}</span>
                </StatusLabel>
            </button>
        </div>

        <FoldingSection v-if="dueSoon.next.length" title="Next to breach" remember="overview.due-soon" class="mt-10" flush>
            <template #hint>
                SLAs still in time, closest first<template v-if="dueSoon.overdue"> · {{ dueSoon.overdue }} only overdue, left out</template>
            </template>
            <ol class="divide-y divide-line">
                <li v-for="ticket in dueSoon.next" :key="ticket.id" class="flex flex-wrap items-baseline gap-x-4 gap-y-1 px-5 py-2.5">
                    <Link :href="ticket.page" class="shrink-0"><TicketKey :value="ticket.key" /></Link>
                    <Link :href="ticket.page" class="min-w-0 flex-1 truncate hover:underline">{{ ticket.summary }}</Link>
                    <span class="text-sm">
                        <span class="text-muted">{{ shortName(firstRunning(ticket).name) }}</span>
                        <span class="ml-1.5 font-medium" :style="{ color: `var(--tone-${tone(firstRunning(ticket))})` }">{{ phrase(firstRunning(ticket)) }}</span>
                        <span v-if="firstRunning(ticket).due_at" class="ml-1.5 text-xs text-muted">due {{ short(firstRunning(ticket).due_at) }}</span>
                    </span>
                </li>
            </ol>
        </FoldingSection>

        <div v-if="toScan.length || scanBatch.waiting || scanBatch.running" class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm">
            <button v-if="toScan.length" type="button" class="btn px-3 py-1.5" @click="scanAll">
                Quick scan {{ toScan.length === shown.length ? 'all' : '' }} {{ toScan.length }} without one
            </button>
            <span v-if="scanBatch.waiting || scanBatch.running" class="text-working">
                Quick scans: {{ scanBatch.running ? 'one running' : 'none running' }}<template v-if="scanBatch.waiting">, {{ scanBatch.waiting }} waiting</template>
            </span>
            <button v-if="scanBatch.waiting" type="button" class="text-muted hover:text-ink hover:underline" @click="stopScans">Stop the rest</button>
        </div>

        <p v-if="shown.length === 0" class="mt-10 text-muted">Nothing matches.</p>

        <div class="mt-10 space-y-6">
            <template v-for="group in awake" :key="group.key">
                <FoldingSection v-if="inGroup[group.key].length" :title="`${group.label} · ${inGroup[group.key].length}`" :remember="`overview.${group.key}`" :force-open="searching" flush>
                    <ul class="divide-y divide-line">
                        <li v-for="ticket in inGroup[group.key].slice(0, shownOf(group.key))" :key="ticket.id">
                            <TicketRow :ticket="ticket" :space="spacesById[ticket.space_id]" />
                        </li>
                    </ul>
                    <button v-if="inGroup[group.key].length > shownOf(group.key)" type="button" class="w-full border-t border-line py-3 text-sm text-muted hover:text-ink" @click="showMore(group.key)">
                        Show {{ Math.min(BATCH, inGroup[group.key].length - shownOf(group.key)) }} more of {{ inGroup[group.key].length - shownOf(group.key) }}
                    </button>
                </FoldingSection>
            </template>

            <!-- Waiting on the requester, or hidden by you: folded unless you open them. -->
            <template v-for="fold in folds" :key="fold.key">
                <FoldingSection
                    v-if="inGroup[fold.key].length"
                    :title="`${fold.label} · ${inGroup[fold.key].length}`"
                    :remember="`overview.${fold.key}`"
                    :open="false"
                    :force-open="searching"
                    flush
                >
                    <template #hint>{{ fold.hint }}</template>
                    <ul class="divide-y divide-line">
                        <li v-for="ticket in inGroup[fold.key].slice(0, shownOf(fold.key))" :key="ticket.id">
                            <TicketRow :ticket="ticket" :space="spacesById[ticket.space_id]" />
                        </li>
                    </ul>
                    <button v-if="inGroup[fold.key].length > shownOf(fold.key)" type="button" class="w-full border-t border-line py-3 text-sm text-muted hover:text-ink" @click="showMore(fold.key)">
                        Show {{ Math.min(BATCH, inGroup[fold.key].length - shownOf(fold.key)) }} more of {{ inGroup[fold.key].length - shownOf(fold.key) }}
                    </button>
                </FoldingSection>
            </template>
        </div>
    </template>
</template>
