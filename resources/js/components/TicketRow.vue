<script setup>
import SpaceLabel from './SpaceLabel.vue';
import StateBadge from './StateBadge.vue';
import StatusLabel from './StatusLabel.vue';
import TicketKey from './TicketKey.vue';
import { ago } from '../time.js';

defineProps({
    ticket: { type: Object, required: true },
    space: { type: Object, default: null },
});
</script>

<template>
    <!-- Until a ticket has a page here, it opens in Jira. -->
    <a
        :href="ticket.url"
        target="_blank"
        rel="noopener"
        class="grid gap-x-5 gap-y-2 p-4 transition-colors hover:bg-sunken/50 sm:grid-cols-[12rem_1fr_auto] sm:items-start"
    >
        <div class="flex flex-wrap items-start gap-1.5 sm:flex-col">
            <TicketKey :value="ticket.key" />
            <StatusLabel :status="ticket.status" :tone="ticket.status_tone" />
        </div>
        <div class="min-w-0">
            <p class="font-medium break-words">{{ ticket.summary }}</p>
            <p class="mt-0.5 text-sm text-muted">
                <SpaceLabel v-if="space" :label="space.label" :colour="space.colour" />
                <template v-if="ticket.priority"> · {{ ticket.priority }}</template>
                <template v-if="ticket.reporter"> · {{ ticket.reporter }}</template>
                · updated {{ ago(ticket.updated_at) }}
            </p>
        </div>
        <div class="flex items-center gap-3 sm:pt-0.5">
            <StateBadge :state="ticket.group" />
            <span class="text-sm whitespace-nowrap text-muted">Jira ↗</span>
        </div>
    </a>
</template>
