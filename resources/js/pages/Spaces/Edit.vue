<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import SpaceLabel from '../../components/SpaceLabel.vue';
import StatusLabel from '../../components/StatusLabel.vue';
import { ago } from '../../time.js';

const props = defineProps({
    space: { type: Object, required: true },
    systems: { type: Array, required: true },
    colours: { type: Array, required: true },
    tones: { type: Array, required: true },
    vocabulary: { type: Object, required: true },
    permissions: { type: Object, required: true },
});

const form = useForm({
    label: props.space.label,
    colour: props.space.colour,
    type: props.space.type,
    rules: props.space.rules ?? '',
    done_rule: props.space.done_rule,
    system_ids: [...props.space.system_ids],
    status_colours: { ...props.space.status_colours },
    // null: automatic, until a box is ticked or unticked.
    sleep_statuses: props.space.sleep_statuses,
});

const statuses = computed(() => props.vocabulary.data?.statuses ?? []);

function toneOf(status) {
    return form.status_colours[status.name] || status.default_tone;
}

function setTone(status, tone) {
    if (tone === '') {
        delete form.status_colours[status.name];
    } else {
        form.status_colours[status.name] = tone;
    }
}

const sleeping = computed(() => form.sleep_statuses ?? statuses.value.filter((status) => status.asleep_by_default).map((status) => status.name));

function setSleeps(status, sleeps) {
    const others = sleeping.value.filter((name) => name !== status.name);
    form.sleep_statuses = sleeps ? [...others, status.name] : others;
}

function save() {
    form.put(`/spaces/${props.space.id}`, { preserveScroll: true });
}

function post(action) {
    router.post(`/spaces/${props.space.id}/${action}`, {}, { preserveScroll: true });
}

function remove() {
    if (window.confirm(`Remove ${props.space.label}, with the tickets it synced? Jira is not touched.`)) {
        router.delete(`/spaces/${props.space.id}`);
    }
}

const jiraSays = computed(() => (props.space.jira_type === 'service_desk' ? 'a service space' : `a plain space (${props.space.jira_type})`));

const permissionNames = {
    BROWSE_PROJECTS: 'See the space',
    ADD_COMMENTS: 'Comment',
    SERVICEDESK_AGENT: 'Be an agent (for replies and internal notes)',
};

// Clicking one of the space's own words adds it to the rules, so nobody has to remember
// how Jira spells a status.
function add(field, value) {
    const clause = `${field} = "${value.replaceAll('"', '\\"')}"`;
    form.rules = form.rules.trim() === '' ? clause : `${form.rules.trim()} AND ${clause}`;
}

const preview = ref(null);
const previewing = ref(false);

async function runPreview() {
    previewing.value = true;
    const query = new URLSearchParams({ rules: form.rules ?? '', done_rule: form.done_rule ?? '' });
    try {
        const response = await fetch(`/spaces/${props.space.id}/preview?${query}`, {
            headers: { Accept: 'application/json' },
        });
        const body = await response.json();
        preview.value = response.status === 422 ? { error: Object.values(body.errors ?? {}).flat().join(' ') } : body;
    } catch {
        preview.value = { error: 'The preview did not come back. Try again.' };
    } finally {
        previewing.value = false;
    }
}
</script>

<template>
    <Head :title="space.label" />

    <p class="eyebrow"><Link href="/spaces" class="hover:text-ink">Spaces</Link> / {{ space.project_key }}</p>
    <div class="mt-3 flex flex-wrap items-end gap-x-6 gap-y-4">
        <h1 class="display text-5xl leading-[1.05] sm:text-6xl">{{ space.name }}</h1>
        <div class="ml-auto flex flex-wrap gap-2 pb-1">
            <button v-if="space.state === 'active'" type="button" class="btn" @click="post('sync')">Sync now</button>
            <button v-if="space.state === 'active'" type="button" class="btn" @click="post('pause')">Pause</button>
            <button
                v-else
                type="button"
                class="btn btn-signal"
                :disabled="form.isDirty"
                @click="post('activate')"
            >
                Activate
            </button>
        </div>
    </div>
    <p class="mt-3 text-sm text-muted">
        <span class="font-mono">{{ space.project_key }}</span> ·
        {{ space.state === 'active' ? 'Active' : space.state === 'paused' ? 'Paused' : 'Draft, not syncing yet' }}
        <template v-if="space.sync_error"> · <span class="text-signal">sync failed: {{ space.sync_error }}</span></template>
        <template v-else-if="space.synced_at"> · synced {{ ago(space.synced_at) }}</template>
        <template v-if="form.isDirty && space.state !== 'active'"> · save first, then activate</template>
    </p>

    <form class="mt-10 space-y-10" @submit.prevent="save">
        <!-- Name and kind -->
        <section class="card grid gap-6 p-5 md:grid-cols-2">
            <div>
                <label for="label" class="text-sm font-medium">Label in the overview</label>
                <input id="label" v-model="form.label" maxlength="24" class="mt-1 w-full rounded-md border border-line bg-page px-3 py-2 text-sm" />
                <p v-if="form.errors.label" class="mt-1 text-sm text-signal">{{ form.errors.label }}</p>

                <p class="mt-4 text-sm font-medium">Colour</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <label
                        v-for="colour in colours"
                        :key="colour"
                        class="flex cursor-pointer items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                        :class="form.colour === colour ? 'border-ink' : 'border-line text-muted'"
                    >
                        <input v-model="form.colour" type="radio" :value="colour" class="sr-only" />
                        <span class="size-2.5 rounded-full" :style="{ background: `var(--space-${colour})` }" aria-hidden="true"></span>
                        {{ colour }}
                    </label>
                </div>
                <p class="mt-3 text-sm text-muted">Looks like: <SpaceLabel :label="form.label || '…'" :colour="form.colour" class="text-ink" /></p>
            </div>

            <div>
                <p class="text-sm font-medium">Kind of space</p>
                <div class="mt-2 space-y-2 text-sm">
                    <label class="flex items-start gap-2">
                        <input v-model="form.type" type="radio" value="service" class="mt-1" />
                        <span><span class="font-medium">Service space</span><span class="text-muted">: replies to the customer and internal notes</span></span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input v-model="form.type" type="radio" value="plain" class="mt-1" />
                        <span><span class="font-medium">Plain space</span><span class="text-muted">: one kind of comment, visible to all</span></span>
                    </label>
                </div>
                <p class="mt-2 text-sm text-muted">Jira says this is {{ jiraSays }}.</p>

                <p class="mt-5 text-sm font-medium">What the Jira account may do here</p>
                <p v-if="permissions.error" class="mt-1 text-sm text-signal">Could not check: {{ permissions.error }}</p>
                <ul v-else class="mt-1 space-y-1 text-sm">
                    <li v-for="(allowed, key) in permissions.data" :key="key">
                        <span class="font-mono text-xs font-medium tracking-wide uppercase" :class="allowed ? 'text-done' : 'text-signal'">
                            {{ allowed ? 'Yes' : 'No' }}
                        </span>
                        {{ permissionNames[key] ?? key }}
                    </li>
                </ul>
            </div>
        </section>

        <!-- Which tickets count -->
        <section class="card p-5">
            <h2 class="text-lg font-medium">Which tickets count</h2>
            <p class="mt-1 text-sm text-muted">
                JQL for this space's last-line tickets. The tool adds <code class="font-mono text-xs">project = "{{ space.project_key }}"</code>
                itself, and orders by last update.
            </p>
            <label for="rules" class="sr-only">Ticket rules</label>
            <textarea
                id="rules"
                v-model="form.rules"
                rows="3"
                placeholder='labels = "last-line" AND issuetype = Bug'
                class="mt-3 w-full rounded-md border border-line bg-page px-3 py-2 font-mono text-sm placeholder:text-muted"
            ></textarea>
            <p v-if="form.errors.rules" class="mt-1 text-sm text-signal">{{ form.errors.rules }}</p>

            <div v-if="vocabulary.data" class="mt-3 space-y-2 text-sm">
                <p v-for="(values, field) in { issuetype: vocabulary.data.issueTypes, status: statuses.map((status) => status.name), component: vocabulary.data.components }" :key="field" class="flex flex-wrap items-center gap-1.5">
                    <span class="w-24 shrink-0 text-muted">{{ field === 'issuetype' ? 'Issue type' : field === 'status' ? 'Status' : 'Component' }}</span>
                    <span v-if="values.length === 0" class="text-muted">none</span>
                    <button
                        v-for="value in values"
                        :key="value"
                        type="button"
                        class="rounded-sm border border-line bg-sunken px-1.5 py-0.5 font-mono text-xs hover:border-ink"
                        @click="add(field, value)"
                    >
                        + {{ value }}
                    </button>
                </p>
            </div>
            <p v-else-if="vocabulary.error" class="mt-3 text-sm text-signal">The space's own words did not load: {{ vocabulary.error }}</p>

            <label for="done" class="mt-6 block text-sm font-medium">Done when</label>
            <input id="done" v-model="form.done_rule" class="mt-1 w-full rounded-md border border-line bg-page px-3 py-2 font-mono text-sm" />
            <p v-if="form.errors.done_rule" class="mt-1 text-sm text-signal">{{ form.errors.done_rule }}</p>
            <p v-else class="mt-1 text-sm text-muted">A ticket matching this leaves the overview. Usually Jira's own Done category.</p>

            <div class="mt-5 flex flex-wrap items-center gap-3">
                <button type="button" class="btn" :disabled="previewing" @click="runPreview">
                    {{ previewing ? 'Asking Jira…' : 'Preview' }}
                </button>
                <span class="text-sm text-muted">What these rules would find right now.</span>
            </div>

            <div v-if="preview" class="mt-4 rounded-md border border-line bg-sunken/50 p-4 text-sm" aria-live="polite">
                <p v-if="preview.error" class="text-signal">{{ preview.error }}</p>
                <template v-else>
                    <p class="font-medium">
                        <template v-if="preview.count !== null && preview.count !== undefined">{{ preview.count }} {{ preview.count === 1 ? 'ticket' : 'tickets' }}</template>
                        <template v-else>{{ preview.tickets.length }}{{ preview.more ? ' or more' : '' }} tickets</template>
                        <span class="font-normal text-muted"> match now<template v-if="preview.tickets.length">; the latest:</template></span>
                    </p>
                    <ul class="mt-2 space-y-1">
                        <li v-for="ticket in preview.tickets" :key="ticket.key" class="flex gap-3">
                            <span class="w-24 shrink-0 font-mono text-xs leading-5">{{ ticket.key }}</span>
                            <span class="min-w-0 flex-1 break-words">{{ ticket.summary }}</span>
                            <span class="shrink-0 text-muted">{{ ticket.status }}</span>
                        </li>
                    </ul>
                </template>
                <p v-if="preview.jql" class="mt-3 font-mono text-xs break-words text-muted">{{ preview.jql }}</p>
            </div>
        </section>

        <!-- Statuses -->
        <section class="card p-5">
            <h2 class="text-lg font-medium">Statuses</h2>
            <p class="mt-1 max-w-3xl text-sm text-muted">
                The colour of each status's label in the overview, and which statuses put a ticket to sleep: waiting on the
                requester, so it moves to the sleeping segment below the rest.
            </p>
            <p v-if="vocabulary.error" class="mt-3 text-sm text-signal">The statuses did not load: {{ vocabulary.error }}</p>
            <p v-else-if="form.sleep_statuses === null" class="mt-2 text-sm text-muted">
                Sleep is automatic for now: statuses that say they wait for the customer. Ticking or unticking a box makes the list yours.
            </p>
            <table v-if="statuses.length" class="mt-4 w-full text-sm">
                <thead>
                    <tr class="text-left text-muted">
                        <th class="pb-2 font-normal">Status</th>
                        <th class="pb-2 font-normal">Colour</th>
                        <th class="pb-2 font-normal">Sleeps</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="status in statuses" :key="status.name">
                        <td class="py-2 pr-4"><StatusLabel :status="status.name" :tone="toneOf(status)" /></td>
                        <td class="py-2 pr-4">
                            <label class="sr-only" :for="`tone-${status.name}`">Colour for {{ status.name }}</label>
                            <select
                                :id="`tone-${status.name}`"
                                :value="form.status_colours[status.name] ?? ''"
                                class="rounded-md border border-line bg-page px-2 py-1 text-sm"
                                @change="setTone(status, $event.target.value)"
                            >
                                <option value="">Automatic ({{ status.default_tone }})</option>
                                <option v-for="tone in tones" :key="tone" :value="tone">{{ tone }}</option>
                            </select>
                        </td>
                        <td class="py-2">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" :checked="sleeping.includes(status.name)" @change="setSleeps(status, $event.target.checked)" />
                                <span class="text-muted">{{ sleeping.includes(status.name) ? 'sleeps' : 'awake' }}</span>
                            </label>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- Systems -->
        <section class="card p-5">
            <h2 class="text-lg font-medium">Systems</h2>
            <p class="mt-1 text-sm text-muted">The code this space's tickets can be about. The agent picks among these.</p>
            <p v-if="systems.length === 0" class="mt-3 text-sm text-muted">
                No systems yet. <Link href="/systems" class="underline">Add them on the Systems page.</Link>
            </p>
            <div v-else class="mt-3 flex flex-wrap gap-2">
                <label
                    v-for="system in systems"
                    :key="system.id"
                    class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-1.5 font-mono text-sm"
                    :class="form.system_ids.includes(system.id) ? 'border-ink' : 'border-line text-muted'"
                >
                    <input v-model="form.system_ids" type="checkbox" :value="system.id" />
                    {{ system.name }}
                </label>
            </div>
        </section>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="btn btn-signal" :disabled="form.processing || !form.isDirty">Save</button>
            <span v-if="!form.isDirty" class="text-sm text-muted">Nothing changed.</span>
            <button type="button" class="btn ml-auto" @click="remove">Remove this space</button>
        </div>
    </form>
</template>
