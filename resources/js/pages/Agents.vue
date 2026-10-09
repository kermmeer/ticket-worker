<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import SpaceLabel from '../components/SpaceLabel.vue';
import { reachable } from '../connection.js';
import { ago, short } from '../time.js';

// The agents at a glance: what works now and for whom, what your work signs in with, and
// the settings in force per space.
const props = defineProps({
    working: { type: Array, required: true },
    quickScans: { type: Object, required: true },
    systems: { type: Array, required: true },
    you: { type: Object, required: true },
    signIns: { type: Array, required: true },
    autoScanSignIn: { type: Boolean, default: false },
    spaces: { type: Array, required: true },
    recent: { type: Array, required: true },
});

// The first sign-in that is set is the one in use.
const active = computed(() => props.signIns.findIndex((signIn) => signIn.set));
const busy = computed(() => props.working.length + props.quickScans.running.length + props.systems.length);

// While something works, look again every five seconds; otherwise every thirty.
let timer = null;
onMounted(() => {
    timer = setInterval(() => reachable() && router.reload({ preserveScroll: true, preserveState: true, showProgress: false }), busy.value ? 5000 : 30000);
});
onBeforeUnmount(() => clearInterval(timer));

const settingLabels = {
    model: 'Model', effort: 'Effort', turn_budget_usd: 'Per turn', quick_model: 'Quick scan model', quick_effort: 'Quick effort',
    quick_budget_usd: 'Per quick scan', monthly_budget_usd: 'Monthly budget', auto_patch: 'Automatic patches',
};
const show = (key, value) => (value === null || value === undefined ? 'none' : key === 'auto_patch' ? (value ? 'on' : 'off') : key.endsWith('_usd') ? `$${Number(value).toFixed(2)}` : value);
</script>

<template>
    <Head title="Agents" />
    <p class="eyebrow">Agents</p>
    <h1 class="display mt-2 text-3xl">{{ busy ? `${busy} at work` : 'Nothing at work right now' }}</h1>

    <!-- What your work signs in with -->
    <section class="mt-8 grid gap-6 lg:grid-cols-2">
        <div class="card p-5">
            <h2 class="text-lg font-medium">Your Claude sign-in</h2>
            <p class="mt-1 text-sm text-muted">The first one that is set is used, in this order. Tokens are never shown, only whether one is set.</p>
            <ol class="mt-3 space-y-1.5 text-sm">
                <li v-for="(signIn, index) in signIns" :key="signIn.what" class="flex flex-wrap items-baseline gap-x-2">
                    <span class="w-5 font-mono text-xs text-muted">{{ index + 1 }}.</span>
                    <span :class="index === active ? 'font-medium' : signIn.set ? '' : 'text-muted'">{{ signIn.what }}</span>
                    <span class="text-xs" :class="signIn.set ? 'text-muted' : 'text-muted'">{{ signIn.set ? signIn.kind : 'not set' }}</span>
                    <span v-if="index === active" class="rounded-[3px] border border-done px-1.5 font-mono text-[0.66rem] leading-5 font-medium tracking-wide text-done uppercase">in use</span>
                </li>
            </ol>
            <p v-if="active === -1" class="mt-3 text-sm text-signal">None is set: your agents cannot run. Add your own on <Link href="/account" class="underline">Your account</Link>.</p>
            <p v-else-if="active > 0" class="mt-3 text-xs text-muted">Add your own on <Link href="/account" class="underline">Your account</Link> to run on your own subscription or bill.</p>
            <p class="mt-3 text-xs text-muted">
                Automatic quick scans of new tickets run on the shared one or .env:
                <span :class="autoScanSignIn ? 'text-done' : 'text-signal'">{{ autoScanSignIn ? 'one is set' : 'neither is set, so none run' }}</span>.
            </p>
        </div>
        <div class="card p-5">
            <h2 class="text-lg font-medium">This month</h2>
            <p class="mt-2 text-sm">
                <span class="font-mono text-lg">${{ you.usage.cost.toFixed(2) }}</span>
                <span class="text-muted"> of agent work you asked for<template v-if="you.budget"> · your budget ${{ you.budget }}</template><template v-else> · no budget</template></span>
            </p>
            <p class="mt-1 text-sm text-muted">{{ you.usage.turns }} turns, {{ you.usage.scans }} quick scans; priced as the API would.</p>
            <p class="mt-3 text-xs text-muted">Your role: {{ you.role }}.</p>
        </div>
    </section>

    <!-- At work -->
    <section class="mt-10">
        <h2 class="eyebrow">At work</h2>
        <p v-if="!busy && !quickScans.waiting" class="mt-3 text-sm text-muted">No agent is working, and nothing waits.</p>
        <ul v-if="working.length || quickScans.running.length || systems.length" class="card mt-3 divide-y divide-line">
            <li v-for="item in working" :key="item.id" class="flex flex-wrap items-baseline gap-x-3 gap-y-1 p-3 text-sm" :class="{ 'bg-sunken/40': item.mine }">
                <span class="font-medium" :class="item.state === 'running' ? 'text-working' : 'text-muted'">{{ item.what }}{{ item.state === 'queued' ? ', waiting' : '' }}</span>
                <Link :href="item.url" class="font-mono hover:underline">{{ item.ticket }}</Link>
                <span class="min-w-0 flex-1 truncate text-muted">{{ item.summary }}</span>
                <span class="text-xs">{{ item.who === 'you' ? 'yours' : `for ${item.who}` }}</span>
                <span class="font-mono text-xs text-muted">{{ item.steps }} steps · since {{ ago(item.since) }}</span>
            </li>
            <li v-for="scan in quickScans.running" :key="scan.id" class="flex flex-wrap items-baseline gap-x-3 gap-y-1 p-3 text-sm" :class="{ 'bg-sunken/40': scan.mine }">
                <span class="font-medium text-working">Quick scan{{ scan.state === 'queued' ? ', waiting' : '' }}</span>
                <Link :href="scan.url" class="font-mono hover:underline">{{ scan.ticket }}</Link>
                <span class="min-w-0 flex-1 truncate text-muted">{{ scan.summary }}</span>
                <span class="text-xs">{{ scan.who === 'you' ? 'yours' : `for ${scan.who}` }}</span>
            </li>
            <li v-for="system in systems" :key="`${system.what}-${system.name}`" class="flex flex-wrap items-baseline gap-x-3 p-3 text-sm">
                <span class="font-medium text-working">{{ system.what }}{{ system.state === 'queued' ? ', waiting' : '' }}</span>
                <span class="font-mono">{{ system.name }}</span>
            </li>
        </ul>
        <p v-if="quickScans.waiting" class="mt-2 text-sm text-muted">
            {{ quickScans.waiting }} quick scan{{ quickScans.waiting === 1 ? '' : 's' }} waiting their turn<template v-if="quickScans.waiting_mine">, {{ quickScans.waiting_mine }} of them yours</template>: one at a time, after any analysis.
        </p>
    </section>

    <!-- Settings in force -->
    <section class="mt-10">
        <h2 class="eyebrow">Settings in force, per space</h2>
        <p class="mt-1 text-sm text-muted">What a space sets itself is in ink; the rest comes from the defaults on Setup.</p>
        <div class="card mt-3 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted">
                    <tr class="border-b border-line">
                        <th class="p-3 font-normal">Space</th>
                        <th v-for="(label, key) in settingLabels" :key="key" class="p-3 font-normal whitespace-nowrap">{{ label }}</th>
                        <th class="p-3 font-normal">Spent this month</th>
                        <th class="p-3 font-normal">Also</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="space in spaces" :key="space.id">
                        <td class="p-3 whitespace-nowrap"><SpaceLabel :label="space.label" :colour="space.colour" /><span v-if="space.state !== 'active'" class="ml-1 text-xs text-muted">({{ space.state }})</span></td>
                        <td v-for="(label, key) in settingLabels" :key="key" class="p-3 font-mono text-xs whitespace-nowrap" :class="space.own.includes(key) ? 'text-ink' : 'text-muted'">
                            {{ show(key, space.settings[key]) }}
                        </td>
                        <td class="p-3 font-mono text-xs whitespace-nowrap" :class="space.settings.monthly_budget_usd && space.spent >= space.settings.monthly_budget_usd * 0.8 ? 'text-signal' : 'text-muted'">
                            ${{ space.spent.toFixed(2) }}
                        </td>
                        <td class="p-3 text-xs whitespace-nowrap text-muted">
                            {{ [space.quick_scan ? 'quick scans on' : null, space.has_webhook ? 'Slack/Teams' : null, space.own_rules.length ? 'own rules' : null].filter(Boolean).join(' · ') || '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Yours, lately -->
    <section v-if="recent.length" class="mt-10">
        <h2 class="eyebrow">Yours, lately</h2>
        <ul class="card mt-3 divide-y divide-line">
            <li v-for="turn in recent" :key="turn.id" class="flex flex-wrap items-baseline gap-x-3 p-3 text-sm">
                <span :class="turn.state === 'done' ? '' : 'text-signal'">{{ turn.what }}<template v-if="turn.state !== 'done'"> ({{ turn.state }})</template></span>
                <Link :href="turn.url" class="font-mono hover:underline">{{ turn.ticket }}</Link>
                <span class="ml-auto font-mono text-xs text-muted">{{ turn.at ? short(turn.at) : '' }}<template v-if="turn.cost != null"> · ${{ turn.cost.toFixed(2) }}</template></span>
            </li>
        </ul>
    </section>
</template>
