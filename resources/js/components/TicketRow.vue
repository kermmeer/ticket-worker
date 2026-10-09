<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import SpaceLabel from './SpaceLabel.vue';
import QuickScanLine from './QuickScanLine.vue';
import StateBadge from './StateBadge.vue';
import StatusLabel from './StatusLabel.vue';
import TicketKey from './TicketKey.vue';
import { ago, short } from '../time.js';
import { phrase, shortName, tone } from '../sla.js';

const props = defineProps({
    ticket: { type: Object, required: true },
    space: { type: Object, default: null },
});

const openLabel = usePage().props.openLabel ?? 'Jira';

// "Sun 5 Oct, 08:00": a send moment, close enough to need the weekday.
function when(iso) {
    return iso ? new Date(iso).toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
}

// A first look from the overview itself; the row says "Quick scan…" until it is done.
function quickScan() {
    router.post(`/tickets/${props.ticket.id}/quick-scan`, {}, { preserveScroll: true, preserveState: true });
}

function toggleHidden() {
    const url = `/tickets/${props.ticket.id}/hide`;
    const options = { preserveScroll: true, preserveState: true };
    props.ticket.group === 'hidden' ? router.delete(url, options) : router.post(url, {}, options);
}
</script>

<template>
    <div class="grid gap-x-6 gap-y-2 p-4 transition-colors hover:bg-sunken/50 lg:grid-cols-[12rem_minmax(0,1fr)_11rem_17rem_11rem] lg:items-start">
        <div class="flex flex-wrap items-start gap-1.5 lg:flex-col">
            <span class="flex items-center gap-1">
                <Link :href="ticket.page"><TicketKey :value="ticket.key" /></Link>
                <!-- Out to the outbox or Jira. The label says where, for screen readers and on hover;
                     the arrow-out-of-a-box shape says "elsewhere" without it. -->
                <a
                    :href="ticket.url"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex size-6 items-center justify-center rounded text-muted hover:bg-sunken hover:text-ink"
                    :aria-label="`Open ${ticket.key} in ${openLabel}`"
                    :title="`Open in ${openLabel}`"
                >
                    <svg aria-hidden="true" viewBox="0 0 16 16" class="size-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 2.5h4.5V7M13.5 2.5 7.5 8.5M12 9.5v3a1 1 0 0 1-1 1H3.5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h3" />
                    </svg>
                </a>
            </span>
            <StatusLabel :status="ticket.status" :tone="ticket.status_tone" />
        </div>

        <div class="min-w-0">
            <Link :href="ticket.page" class="font-medium break-words hover:underline">{{ ticket.summary }}</Link>
            <p class="mt-0.5 text-sm text-muted">
                <SpaceLabel v-if="space" :label="space.label" :colour="space.colour" />
                <template v-if="ticket.priority"> · {{ ticket.priority }}</template>
                <template v-if="ticket.reporter"> · {{ ticket.reporter }}</template>
                <template v-if="ticket.created_at"> · created {{ short(ticket.created_at) }}</template>
                · updated {{ ago(ticket.updated_at) }}
            </p>
            <!-- What is waiting in the outbox for this ticket, and what sending it will change. -->
            <p v-for="(message, index) in ticket.outbox ?? []" :key="index" class="mt-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-sm">
                <a :href="message.url" target="_blank" rel="noopener" class="hover:underline" :class="message.state === 'failed' ? 'font-medium text-signal' : 'text-ink'">
                    <template v-if="message.state === 'failed'">Failed to send from the outbox</template>
                    <template v-else-if="message.state === 'draft'">Draft waiting in the outbox</template>
                    <template v-else>Scheduled {{ when(message.send_at) }}</template>
                </a>
                <span v-if="message.visibility === 'internal'" class="text-muted">· internal note</span>
                <template v-if="message.to_status">
                    <span class="text-muted">· sets</span>
                    <StatusLabel :status="message.to_status" :tone="message.to_tone" />
                </template>
                <span v-if="message.assignee" class="text-muted">· assigns {{ message.assignee }}</span>
            </p>
            <QuickScanLine v-if="ticket.quick_scan" :scan="ticket.quick_scan" />
            <p v-if="ticket.casebook" class="mt-1.5 text-sm">
                <Link :href="`/casebook/${ticket.casebook.id}/edit`" class="text-working hover:underline">
                    Looks like a known case: {{ ticket.casebook.title }}
                </Link>
            </p>
        </div>

        <!-- Side by side under the summary on a narrow screen, columns of their own on a wide one. -->
        <div class="flex flex-wrap gap-x-8 gap-y-2 text-sm lg:contents">
            <div>
                <p class="text-xs text-muted">Assigned to</p>
                <p :class="ticket.assignee ? 'text-ink' : 'text-muted italic'">{{ ticket.assignee ?? 'nobody' }}</p>
            </div>
            <div v-if="ticket.slas?.length" class="space-y-0.5">
                <p class="text-xs text-muted">SLA</p>
                <p v-for="sla in ticket.slas.slice(0, 2)" :key="sla.name" class="flex flex-wrap gap-x-1.5">
                    <span class="text-muted">{{ shortName(sla.name) }}</span>
                    <span class="font-medium" :style="{ color: `var(--tone-${tone(sla)})` }">{{ phrase(sla) }}</span>
                    <span v-if="sla.due_at && sla.state === 'running'" class="text-xs leading-5 text-muted">due {{ short(sla.due_at) }}</span>
                </p>
            </div>
            <div v-else class="hidden lg:block"></div>
        </div>

        <!-- A fixed width, so the columns before it line up from row to row. -->
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 lg:flex-col lg:items-end lg:pt-0.5">
            <StateBadge :state="ticket.group" />
            <!-- One per line on a wide screen, under the badge; side by side on a narrow one. -->
            <p class="flex flex-wrap gap-x-3 gap-y-1 text-sm whitespace-nowrap text-muted lg:flex-col lg:items-end lg:gap-y-0.5">
                <button v-if="!['queued', 'running', 'pending'].includes(ticket.quick_scan?.state)" type="button" class="hover:text-ink hover:underline" @click="quickScan">
                    {{ ticket.quick_scan?.result ? 'Scan again' : 'Quick scan' }}
                </button>
                <button type="button" class="hover:text-ink hover:underline" @click="toggleHidden">{{ ticket.group === 'hidden' ? 'Unhide' : 'Hide' }}</button>
                <Link :href="`/casebook/create?ticket=${encodeURIComponent(ticket.key)}`" class="hover:text-ink hover:underline">Write it up</Link>
            </p>
        </div>
    </div>
</template>
