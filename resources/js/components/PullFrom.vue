<script setup>
import { router, useForm } from '@inertiajs/vue3';
import CodeBlock from './CodeBlock.vue';
import { ago } from '../time.js';

// Pulling, for a project whose Git host this server can reach: an https address with a
// read-only token, or SSH with a deploy key made here. The rest keep arriving by push.
const props = defineProps({
    system: { type: Object, required: true },
    sshAvailable: { type: Boolean, default: false },
});

const form = useForm({ url: props.system.pull.url ?? '', auth: props.system.pull.auth ?? 'none', username: props.system.pull.username ?? '', secret: '' });
const field = 'mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5 text-sm';
</script>

<template>
    <details class="mt-5 border-t border-line pt-4" :open="!!system.pull.url">
        <summary class="cursor-pointer text-sm font-medium">
            Pull from its Git host <span class="font-normal text-muted">(when this server can reach it; otherwise keep pushing)</span>
        </summary>
        <p v-if="system.pull.url" class="mt-2 text-sm">
            <span class="text-muted">From</span> <code class="font-mono text-xs break-all">{{ system.pull.url }}</code>
            <span v-if="system.pull.state === 'queued' || system.pull.state === 'running'" class="text-working"> · pulling…</span>
            <span v-else-if="system.pull.state === 'failed'" class="text-signal"> · the last pull failed</span>
            <span v-else-if="system.pull.at" class="text-muted"> · pulled {{ ago(system.pull.at) }}, and every night</span>
            <button v-if="!['queued', 'running'].includes(system.pull.state)" type="button" class="btn ml-2 px-3 py-1 text-xs" @click="router.post(`/systems/${system.id}/pull/now`, {}, { preserveScroll: true })">Pull now</button>
        </p>
        <p v-if="system.pull.error" class="mt-1 text-sm break-words text-signal">{{ system.pull.error }}</p>

        <form class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="form.put(`/systems/${system.id}/pull`, { preserveScroll: true, onSuccess: () => form.reset('secret') })">
            <label class="text-sm sm:col-span-2">Address
                <input v-model="form.url" placeholder="https://gitlab.example.com/group/project.git, or git@github.com:org/project.git" :class="field" class="font-mono" />
                <span v-if="form.errors.url" class="block text-signal">{{ form.errors.url }}</span>
            </label>
            <label class="text-sm">Sign-in
                <select v-model="form.auth" :class="field">
                    <option value="none">None (a public repository)</option>
                    <option value="token">A read-only token over https</option>
                    <option value="ssh" :disabled="!sshAvailable">A deploy key over SSH{{ sshAvailable ? '' : ' (this server has no ssh)' }}</option>
                </select>
            </label>
            <template v-if="form.auth === 'token'">
                <label class="text-sm">Username <span class="text-muted">(GitLab: the deploy token's; GitHub: any)</span>
                    <input v-model="form.username" autocomplete="off" :class="field" />
                </label>
                <label class="text-sm sm:col-span-2">Token
                    <input v-model="form.secret" type="password" autocomplete="new-password" :placeholder="system.pull.has_secret && system.pull.auth === 'token' ? 'set; leave empty to keep it' : 'read_repository only'" :class="field" class="max-w-md font-mono" />
                    <span class="block text-xs text-muted">Stored encrypted; given to git as a header, never in an address.</span>
                </label>
            </template>
            <div class="flex gap-2 sm:col-span-2"><button type="submit" class="btn" :disabled="form.processing">Save</button></div>
        </form>

        <div v-if="form.auth === 'ssh' && system.pull.auth === 'ssh'" class="mt-3 text-sm">
            <template v-if="system.pull.public_key">
                <p>Add this as a <strong class="font-medium">read-only deploy key</strong> on the project at its Git host:</p>
                <CodeBlock :code="system.pull.public_key" language="public key" class="mt-2" />
            </template>
            <button type="button" class="btn mt-2 px-3 py-1.5" @click="router.post(`/systems/${system.id}/pull/key`, {}, { preserveScroll: true })">
                {{ system.pull.public_key ? 'Make a new key' : 'Make a deploy key' }}
            </button>
        </div>
    </details>
</template>
