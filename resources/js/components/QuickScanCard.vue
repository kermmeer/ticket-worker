<script setup>
import { router } from '@inertiajs/vue3';
import AgentText from './AgentText.vue';
import { short, tokens } from '../time.js';

// The quick scan on the ticket page: the whole first look, and a button for another.
const props = defineProps({
    ticketId: { type: Number, required: true },
    scan: { type: Object, default: null },
    agentReady: { type: Boolean, default: false },
});

const busy = () => ['queued', 'running'].includes(props.scan?.state);
const still = { yes: 'Still there', no: 'Already gone', unknown: 'Not checked' };
const tone = { yes: 'text-signal', no: 'text-done', unknown: 'text-muted' };

function scanNow() {
    router.post(`/tickets/${props.ticketId}/quick-scan`, {}, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-3 text-sm">
        <template v-if="scan?.result && !busy()">
            <p class="font-medium">{{ scan.result.summary }}</p>
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5">
                <dt class="text-muted">Kind</dt><dd>{{ scan.result.kind }}<span class="text-muted"> · {{ scan.result.confidence }} confidence<template v-if="scan.result.urgency"> · {{ scan.result.urgency }} urgency</template></span></dd>
                <dt class="text-muted">System</dt><dd class="font-mono">{{ scan.result.system }}</dd>
                <dt class="text-muted">Likely cause</dt><dd class="whitespace-pre-wrap">{{ scan.result.likely_cause }}</dd>
                <template v-if="scan.result.data_check">
                    <dt class="text-muted">The data now</dt>
                    <dd>
                        <span class="font-medium" :class="tone[scan.result.data_check.still_present]">{{ still[scan.result.data_check.still_present] }}</span>
                        <span class="block whitespace-pre-wrap text-muted">{{ scan.result.data_check.evidence }}</span>
                    </dd>
                </template>
            </dl>
            <div><p class="text-muted">Next step</p><AgentText :text="scan.result.next_step" class="mt-1" /></div>
        </template>
        <p v-else-if="busy()" class="text-working">Scanning… {{ scan.steps.at(-1) ?? '' }}</p>
        <p v-else-if="scan?.state === 'pending'" class="text-muted">Waiting its turn in a batch of quick scans.</p>
        <p v-else-if="scan?.state === 'failed'" class="text-signal">The quick scan failed: {{ scan.error }}</p>
        <p v-else class="text-muted">No quick scan yet: a first look in a minute or two, for cents rather than dollars.</p>

        <details v-if="scan?.steps?.length && !busy()" class="text-xs">
            <summary class="cursor-pointer text-muted hover:text-ink">What it looked at ({{ scan.steps.length }})</summary>
            <ul class="mt-1 space-y-0.5 font-mono break-all text-muted"><li v-for="(step, index) in scan.steps" :key="index">{{ step }}</li></ul>
        </details>

        <div class="flex flex-wrap items-center gap-3">
            <button v-if="agentReady && !busy()" type="button" class="btn px-3 py-1.5" @click="scanNow">{{ scan?.result ? 'Scan again' : 'Quick scan' }}</button>
            <span v-if="scan?.at && !busy()" class="font-mono text-xs text-muted">
                {{ short(scan.at) }} · {{ scan.by ? `started by ${scan.by}` : 'by itself, when the ticket arrived' }}<template v-if="scan.cost_usd != null"> · ${{ scan.cost_usd.toFixed(2) }}</template><template v-if="scan.tokens"> · {{ tokens(scan.tokens) }} tokens</template>
            </span>
        </div>
    </div>
</template>
