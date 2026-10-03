<script setup>
import { Link } from '@inertiajs/vue3';
import SpaceLabel from './SpaceLabel.vue';
import StateBadge from './StateBadge.vue';
import StatusLabel from './StatusLabel.vue';
import TicketKey from './TicketKey.vue';
import { ago, short } from '../time.js';
import { phrase, shortName, tone } from '../sla.js';

defineProps({
    ticket: { type: Object, required: true },
    space: { type: Object, default: null },
});
</script>

<template>
    <div class="grid gap-x-6 gap-y-2 p-4 transition-colors hover:bg-sunken/50 lg:grid-cols-[12rem_minmax(0,1fr)_11rem_17rem_11rem] lg:items-start">
        <div class="flex flex-wrap items-start gap-1.5 lg:flex-col">
            <!-- Until a ticket has a page here, it opens in Jira. -->
            <a :href="ticket.url" target="_blank" rel="noopener"><TicketKey :value="ticket.key" /></a>
            <StatusLabel :status="ticket.status" :tone="ticket.status_tone" />
        </div>

        <div class="min-w-0">
            <a :href="ticket.url" target="_blank" rel="noopener" class="font-medium break-words hover:underline">{{ ticket.summary }}</a>
            <p class="mt-0.5 text-sm text-muted">
                <SpaceLabel v-if="space" :label="space.label" :colour="space.colour" />
                <template v-if="ticket.priority"> · {{ ticket.priority }}</template>
                <template v-if="ticket.reporter"> · {{ ticket.reporter }}</template>
                <template v-if="ticket.created_at"> · created {{ short(ticket.created_at) }}</template>
                · updated {{ ago(ticket.updated_at) }}
            </p>
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
            <p class="flex gap-3 text-sm whitespace-nowrap text-muted">
                <Link :href="`/casebook/create?ticket=${encodeURIComponent(ticket.key)}`" class="hover:text-ink hover:underline">Write it up</Link>
                <a :href="ticket.url" target="_blank" rel="noopener" class="hover:text-ink">Jira ↗</a>
            </p>
        </div>
    </div>
</template>
