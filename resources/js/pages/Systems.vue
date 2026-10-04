<script setup>
import { onBeforeUnmount, ref, watchEffect } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    systems: { type: Array, required: true },
    agentReady: { type: Boolean, default: false },
});

function scan(system) {
    router.post(`/systems/${system.id}/scan`, {}, { preserveScroll: true });
}

function stopScan(system) {
    router.post(`/systems/${system.id}/scan/stop`, {}, { preserveScroll: true });
}

// Each system's context, editable; what you save becomes a version of its own.
const contexts = ref({});

function contextOf(system) {
    if (contexts.value[system.id] === undefined) {
        contexts.value[system.id] = system.context ?? '';
    }
    return contexts.value[system.id];
}

function saveContext(system) {
    router.put(`/systems/${system.id}/context`, { context: contexts.value[system.id] }, { preserveScroll: true });
}

const scanning = (system) => ['queued', 'running'].includes(system.scan_state);

const form = useForm({ name: '', branch: 'main' });

function add() {
    form.post('/systems', {
        preserveScroll: true,
        onSuccess: () => form.reset('name'),
    });
}

// The worker prepares a new folder within seconds, and a scan runs for minutes: look again
// until they are done, quietly.
let timer = null;

watchEffect(() => {
    const waiting = props.systems.some((system) => system.state === 'preparing' || scanning(system));
    if (waiting && !timer) {
        timer = setInterval(() => router.reload({ only: ['systems'], showProgress: false, preserveScroll: true, preserveState: true }), 2000);
    } else if (!waiting && timer) {
        clearInterval(timer);
        timer = null;
    }
});

onBeforeUnmount(() => clearInterval(timer));

// One-time setup in your own clone; tools/push-systems.sh does the rest from then on.
function commands(system) {
    return [
        `git remote add ticket-worker ${system.remote ?? system.path}`,
        `git config ticket-worker.branch ${system.branch}`,
        // Full ref names: the first push lands in an empty folder, where "master" means nothing yet.
        `git push ticket-worker +refs/remotes/origin/${system.branch}:refs/heads/${system.branch}`,
    ].join('\n');
}

const copied = ref(null);

async function copy(system) {
    try {
        await navigator.clipboard.writeText(commands(system));
        copied.value = system.id;
        setTimeout(() => (copied.value = null), 2000);
    } catch {
        // No clipboard (plain http, or refused): the commands are on screen to select.
    }
}

function when(iso) {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

const states = {
    preparing: { label: 'Preparing', tone: 'text-working' },
    ready: { label: 'Ready', tone: 'text-done' },
    failed: { label: 'Failed', tone: 'text-signal' },
};
</script>

<template>
    <Head title="Systems" />

    <p class="eyebrow">Systems</p>
    <h1 class="display mt-3 text-5xl leading-[1.05] sm:text-6xl">The code the agents read.</h1>
    <p class="mt-5 max-w-2xl text-lg text-muted">
        Ticket Worker cannot reach your Git server, so you push each system here from your own machine. A push to the
        system's branch updates its folder straight away; nothing here ever pulls.
    </p>
    <p class="mt-3 max-w-2xl text-sm text-muted">
        To keep them all current, run <code class="font-mono text-xs text-ink">tools/push-systems.sh ~/code</code> from the
        Ticket Worker repository on that machine: it fetches every clone set up below from GitLab and pushes it here.
        If the VPN cuts you off from this server, run it with <code class="font-mono text-xs text-ink">fetch</code> while
        connected and <code class="font-mono text-xs text-ink">push</code> after.
    </p>

    <form class="card mt-10 grid gap-4 p-5 sm:grid-cols-[1fr_12rem_auto] sm:items-start" @submit.prevent="add">
        <div>
            <label for="system-name" class="text-sm font-medium">Name</label>
            <input
                id="system-name"
                v-model="form.name"
                type="text"
                placeholder="billing"
                autocomplete="off"
                class="mt-1 w-full rounded-md border border-line bg-page px-3 py-2 font-mono text-sm placeholder:text-muted"
                :aria-invalid="!!form.errors.name"
                aria-describedby="system-name-help"
            />
            <p v-if="form.errors.name" class="mt-1 text-sm text-signal">{{ form.errors.name }}</p>
            <p v-else id="system-name-help" class="mt-1 text-sm text-muted">Also the folder name: lower case, digits, dashes.</p>
        </div>
        <div>
            <label for="system-branch" class="text-sm font-medium">Branch</label>
            <input
                id="system-branch"
                v-model="form.branch"
                type="text"
                autocomplete="off"
                class="mt-1 w-full rounded-md border border-line bg-page px-3 py-2 font-mono text-sm"
                :aria-invalid="!!form.errors.branch"
            />
            <p v-if="form.errors.branch" class="mt-1 text-sm text-signal">{{ form.errors.branch }}</p>
            <p v-else class="mt-1 text-sm text-muted">What production runs.</p>
        </div>
        <button type="submit" class="btn btn-signal sm:mt-6" :disabled="form.processing">Add system</button>
    </form>

    <p v-if="systems.length === 0" class="mt-10 text-muted">No systems yet.</p>

    <ul class="mt-10 space-y-4">
        <li v-for="system in systems" :key="system.id" class="card p-5">
            <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                <h2 class="font-mono text-lg">{{ system.name }}</h2>
                <span class="font-mono text-xs text-muted">branch {{ system.branch }}</span>
                <span class="ml-auto font-mono text-xs font-medium tracking-wide uppercase" :class="states[system.state]?.tone">
                    {{ states[system.state]?.label ?? system.state }}
                </span>
            </div>

            <p v-if="system.state === 'failed'" class="mt-3 text-sm break-words text-signal">{{ system.error }}</p>

            <template v-if="system.state === 'ready'">
                <p v-if="system.head" class="mt-3 text-sm">
                    <span class="text-muted">Last push:</span>
                    <code class="mx-1 font-mono text-[0.85em]">{{ system.head.hash }}</code>
                    {{ system.head.subject }}
                    <span class="text-muted">· committed {{ when(system.head.committed_at) }}</span>
                </p>
                <p v-else class="mt-3 text-sm text-muted">Nothing pushed yet.</p>

                <div class="mt-4">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-medium">Once, in your clone of it</p>
                        <button type="button" class="btn px-2.5 py-1 text-xs" @click="copy(system)">
                            {{ copied === system.id ? 'Copied' : 'Copy' }}
                        </button>
                    </div>
                    <pre class="mt-2 overflow-x-auto rounded-md border border-line bg-sunken p-3 font-mono text-xs leading-relaxed">{{ commands(system) }}</pre>
                    <p v-if="!system.remote" class="mt-2 text-sm text-muted">
                        Set SYSTEMS_PUSH_BASE in shared/.env to show the address as your machine sees it; this is the
                        folder as the app sees it.
                    </p>
                </div>

                <!-- The context: the map every analysis of this system starts from (CONCEPT.md §5). -->
                <div class="mt-5 border-t border-line pt-4">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <p class="text-sm font-medium">Context</p>
                        <span v-if="system.context && !scanning(system)" class="text-sm text-muted">
                            written {{ system.context_written_at ? new Date(system.context_written_at).toLocaleDateString() : '' }}
                            <template v-if="system.scan_cost_usd"> · ${{ system.scan_cost_usd.toFixed(2) }}</template>
                        </span>
                        <span v-if="system.commits_behind" class="text-sm text-waiting">{{ system.commits_behind }} commits behind the code</span>
                        <span v-if="scanning(system)" class="text-sm text-working">{{ system.scan_state === 'queued' ? 'Waiting for a free agent…' : 'Scanning…' }}</span>
                        <span class="ml-auto flex gap-2">
                            <button v-if="scanning(system)" type="button" class="btn px-3 py-1.5" @click="stopScan(system)">Stop</button>
                            <button
                                v-else
                                type="button"
                                class="btn px-3 py-1.5"
                                :class="{ 'btn-signal': !system.context }"
                                :disabled="!system.head || !agentReady"
                                @click="scan(system)"
                            >
                                {{ system.context ? 'Rescan' : 'Scan it' }}
                            </button>
                        </span>
                    </div>
                    <p v-if="!system.head" class="mt-1 text-sm text-muted">Push the code first; then an agent can read it.</p>
                    <p v-else-if="!agentReady" class="mt-1 text-sm text-muted">Scanning needs an Anthropic sign-in: see Setup.</p>
                    <p v-else-if="!system.context && !scanning(system)" class="mt-1 text-sm text-muted">
                        An agent reads the system once and writes a short map of it: what it is, where things live, what users call
                        things. Every analysis starts from it. A rescan later only updates what changed.
                    </p>
                    <p v-if="system.scan_error" class="mt-2 text-sm text-signal">{{ system.scan_error }}</p>
                    <pre v-if="scanning(system) && system.scan_log" class="mt-3 max-h-48 overflow-auto rounded-md border border-line bg-sunken p-3 font-mono text-xs leading-relaxed">{{ system.scan_log }}</pre>
                    <details v-if="system.context" class="mt-3">
                        <summary class="cursor-pointer text-sm text-muted hover:text-ink">Read or edit it</summary>
                        <textarea
                            :value="contextOf(system)"
                            rows="18"
                            class="mt-2 w-full rounded-md border border-line bg-page px-3 py-2 font-mono text-xs leading-relaxed"
                            @input="contexts[system.id] = $event.target.value"
                        ></textarea>
                        <button type="button" class="btn mt-2 px-3 py-1.5" @click="saveContext(system)">Save my edit</button>
                    </details>
                </div>
            </template>
        </li>
    </ul>
</template>
