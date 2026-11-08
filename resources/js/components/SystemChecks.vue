<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';

// What a build can check before it is handed in: a checks workspace with the dependencies,
// made once by the setup command, and commands that pass or fail. Each check can be off:
// one that cannot work here yet (behind the VPN, say) waits until it can.
const props = defineProps({ system: { type: Object, required: true } });

const checks = computed(() => props.system.checks);
const form = useForm({
    node: checks.value.node ?? '',
    setup: checks.value.setup ?? '',
    redirects: checks.value.redirects ?? '',
    list: (checks.value.list ?? []).map((check) => ({ ...check })),
});

const open = ref(false);
const field = 'w-full rounded-md border border-line bg-page px-2 py-1.5 text-sm';

function add(check = { name: '', command: '', enabled: true, note: '' }) {
    form.list.push({ ...check });
}
function save() {
    form.put(`/systems/${props.system.id}/checks`, { preserveScroll: true });
}
function prepare() {
    router.post(`/systems/${props.system.id}/checks/prepare`, {}, { preserveScroll: true });
}

// Starting points; every line can be changed before saving.
const presets = {
    angular: {
        label: 'An Angular app',
        node: '20',
        setup: 'npm ci --no-audit --no-fund',
        list: [
            { name: 'lint', command: 'npx ng lint', enabled: true, note: '' },
            { name: 'build', command: 'npx ng build', enabled: true, note: 'Type errors fail it' },
            { name: 'unit tests', command: 'npx ng test --watch=false --browsers=ChromeHeadless', enabled: false, note: 'Needs a browser in the agent container' },
        ],
    },
    php: {
        label: 'PHP with make check',
        node: '20',
        setup: 'composer install --no-interaction --no-scripts --ignore-platform-reqs && npm ci --no-audit --no-fund',
        list: [
            { name: 'php lint', command: 'make check-php', enabled: true, note: '' },
            { name: 'phpcs', command: 'make check-phpcs', enabled: true, note: '' },
            { name: 'eslint', command: 'make check-ts', enabled: true, note: '' },
        ],
    },
};
function usePreset(key) {
    const preset = presets[key];
    form.node = preset.node;
    form.setup = preset.setup;
    form.list = preset.list.map((check) => ({ ...check }));
}

const states = {
    queued: { label: 'waiting for a free agent', tone: 'text-muted' },
    preparing: { label: 'preparing…', tone: 'text-working' },
    ready: { label: 'ready', tone: 'text-done' },
    failed: { label: 'setup failed', tone: 'text-signal' },
};
const on = computed(() => (checks.value.list ?? []).filter((check) => check.enabled));
</script>

<template>
    <div class="mt-5 border-t border-line pt-4">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <p class="text-sm font-medium">Checks</p>
            <span class="text-sm text-muted">
                <template v-if="checks.list?.length">{{ on.map((check) => check.name).join(' · ') || 'all off' }}</template>
                <template v-else>none: builds are handed in unchecked</template>
            </span>
            <span v-if="checks.state" class="text-sm" :class="states[checks.state]?.tone">{{ states[checks.state]?.label ?? checks.state }}</span>
            <span class="ml-auto flex gap-2">
                <button v-if="checks.setup && !['queued', 'preparing'].includes(checks.state)" type="button" class="btn px-3 py-1.5" @click="prepare">Prepare again</button>
                <button type="button" class="btn px-3 py-1.5" :aria-expanded="open" @click="open = !open">{{ open ? 'Close' : 'Set up' }}</button>
            </span>
        </div>
        <p v-if="!open && !checks.list?.length" class="mt-1 text-sm text-muted">
            Lint, a type check, unit tests: a build runs them and fixes what fails before it hands in. Your CI stays the full test.
        </p>
        <details v-if="checks.log" class="mt-2 text-sm">
            <summary class="cursor-pointer text-muted hover:text-ink">What the setup said</summary>
            <pre class="mt-2 max-h-64 overflow-auto rounded-md border border-line bg-sunken p-3 font-mono text-xs leading-relaxed whitespace-pre-wrap">{{ checks.log }}</pre>
        </details>

        <form v-if="open" class="mt-3 space-y-4" @submit.prevent="save">
            <p class="flex flex-wrap items-center gap-2 text-sm">
                <span class="text-muted">Start from</span>
                <button v-for="(preset, key) in presets" :key="key" type="button" class="rounded-md border border-line px-2 py-0.5 text-xs hover:text-ink" @click="usePreset(key)">{{ preset.label }}</button>
            </p>
            <div class="grid gap-3 sm:grid-cols-[8rem_minmax(0,1fr)]">
                <label class="text-sm">Node <span class="text-muted">(major)</span>
                    <input v-model="form.node" placeholder="none" :class="field" class="mt-1 font-mono" />
                </label>
                <label class="text-sm">Setup <span class="text-muted">(once, in the checks workspace: installs the dependencies)</span>
                    <input v-model="form.setup" placeholder="npm ci" :class="field" class="mt-1 font-mono" />
                </label>
            </div>
            <p v-if="form.errors.node" class="text-sm text-signal">{{ form.errors.node }}</p>
            <label class="block text-sm">Private repositories <span class="text-muted">(one per line: the address the setup asks for = a system pushed here)</span>
                <textarea v-model="form.redirects" rows="2" placeholder="git@code.internal.destiny.be:development/oidc-aad-php-library.git = oidc-aad-php-library" :class="field" class="mt-1 font-mono text-xs"></textarea>
            </label>

            <div class="space-y-2">
                <p class="text-sm font-medium">The checks <span class="font-normal text-muted">(each a command: exit 0 passes)</span></p>
                <div v-for="(check, index) in form.list" :key="index" class="grid gap-2 rounded-md border border-line p-2 sm:grid-cols-[auto_8rem_minmax(0,1fr)_auto]">
                    <label class="flex items-center gap-1.5 text-sm" :title="check.enabled ? 'On' : 'Off'">
                        <input v-model="check.enabled" type="checkbox" /> <span class="sm:sr-only">On</span>
                    </label>
                    <input v-model="check.name" placeholder="lint" aria-label="Name" :class="field" />
                    <input v-model="check.command" placeholder="npx ng lint" aria-label="Command" :class="field" class="font-mono" />
                    <button type="button" class="text-sm text-muted hover:text-signal" @click="form.list.splice(index, 1)">Remove</button>
                    <input v-model="check.note" placeholder="A note: why it is off, say" aria-label="Note" :class="field" class="text-xs sm:col-span-4" />
                </div>
                <button type="button" class="btn px-3 py-1.5" @click="add()">Add a check</button>
            </div>
            <p v-if="Object.keys(form.errors).length" class="text-sm text-signal">{{ Object.values(form.errors)[0] }}</p>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-signal" :disabled="form.processing || !form.isDirty">Save</button>
                <span class="self-center text-xs text-muted">A changed Node version, setup or private repository prepares the workspace again.</span>
            </div>
        </form>
    </div>
</template>
