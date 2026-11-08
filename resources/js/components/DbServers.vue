<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { copyText } from '../copy.js';

// Database servers: one read-only login for every project database on that server. The
// password goes in and never comes back out.
const props = defineProps({
    servers: { type: Array, required: true },
    drivers: { type: Object, required: true },
});

const blank = { name: '', driver: 'mysql', host: '', port: '', username: '', password: '', notes: '', ssh_host: '', ssh_port: '', ssh_user: '' };
const tunnel = ref(false);
const editing = ref(null); // a server id, 'new', or null
const form = useForm({ ...blank });

function start(server = null) {
    form.clearErrors();
    form.defaults(server ? { ...blank, ...server, port: server.port ?? '', notes: server.notes ?? '', password: '', ssh_host: server.ssh_host ?? '', ssh_port: server.ssh_port ?? '', ssh_user: server.ssh_user ?? '' } : { ...blank });
    tunnel.value = !!server?.ssh_host;
    form.reset();
    editing.value = server ? server.id : 'new';
}

function save() {
    if (!tunnel.value) {
        form.ssh_host = '';
        form.ssh_port = '';
        form.ssh_user = '';
    }
    const options = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    editing.value === 'new' ? form.post('/systems/db-servers', options) : form.put(`/systems/db-servers/${editing.value}`, options);
}

function remove(server) {
    if (confirm(`Remove ${server.name}?`)) {
        router.delete(`/systems/db-servers/${server.id}`, { preserveScroll: true });
    }
}

const field = 'mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5';

// Its SSH tunnel's key: made here, the public half shown to put on the jump host.
const copiedKey = ref(null);
async function copyKey(server) {
    await copyText(server.ssh_public_key);
    copiedKey.value = server.id;
    setTimeout(() => (copiedKey.value = null), 2000);
}
function makeKey(server) {
    if (server.ssh_public_key && !confirm('Make a new key? The old one stops working: put the new one on the jump host.')) return;
    router.post(`/systems/db-servers/${server.id}/ssh-key`, {}, { preserveScroll: true });
}
function forget(server) {
    if (confirm('Forget the jump host\'s key? Only when it was rebuilt: the next connection trusts whatever key it shows then.')) {
        router.post(`/systems/db-servers/${server.id}/ssh-forget`, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <section class="card mt-10 p-5">
        <div class="flex flex-wrap items-center gap-3">
            <h2 class="text-lg font-medium">Database servers</h2>
            <span class="text-sm text-muted">one read-only login for all the databases on a server; projects then only name their database</span>
            <button v-if="editing === null" type="button" class="btn ml-auto px-3 py-1.5" @click="start()">Add a server</button>
        </div>

        <ul v-if="servers.length" class="mt-3 divide-y divide-line rounded-md border border-line">
            <li v-for="server in servers" :key="server.id" class="flex flex-wrap items-baseline gap-x-3 gap-y-1 p-3 text-sm">
                <span class="font-mono">{{ server.name }}</span>
                <span class="font-mono text-xs break-all text-muted">{{ server.driver }} {{ server.username }}@{{ server.host }}{{ server.port ? `:${server.port}` : '' }}</span>
                <span class="text-xs" :class="server.has_password ? 'text-muted' : 'text-signal'">{{ server.has_password ? 'password set' : 'no password' }}</span>
                <span class="text-xs text-muted">used by {{ server.used }} database{{ server.used === 1 ? '' : 's' }}</span>
                <span v-if="server.ssh_host" class="font-mono text-xs break-all text-working">through {{ server.ssh_user }}@{{ server.ssh_host }}{{ server.ssh_port ? `:${server.ssh_port}` : '' }}</span>
                <span class="ml-auto flex gap-3 text-xs">
                    <button type="button" class="text-muted hover:text-ink" @click="router.post(`/systems/db-servers/${server.id}/try`, {}, { preserveScroll: true })">Try</button>
                    <button type="button" class="text-muted hover:text-ink" @click="start(server)">Edit</button>
                    <button v-if="!server.used" type="button" class="text-muted hover:text-signal" @click="remove(server)">Remove</button>
                </span>
                <!-- The tunnel's key: what to put on the jump host. -->
                <div v-if="server.ssh_host" class="w-full space-y-1.5 pt-1">
                    <template v-if="server.ssh_public_key">
                        <p class="text-xs text-muted">
                            Put this in <code class="font-mono">~/.ssh/authorized_keys</code> of <code class="font-mono">{{ server.ssh_user }}</code> on {{ server.ssh_host }}
                            (one line). For a tunnel only, start the line with <code class="font-mono">restrict,port-forwarding </code>.
                        </p>
                        <pre class="overflow-x-auto rounded-md border border-line bg-sunken p-2 font-mono text-xs">{{ server.ssh_public_key }}</pre>
                    </template>
                    <p class="flex flex-wrap items-center gap-3 text-xs">
                        <button type="button" class="btn px-2.5 py-1 text-xs" :class="{ 'btn-signal': !server.ssh_public_key }" @click="makeKey(server)">{{ server.ssh_public_key ? 'Make a new key' : 'Make a key' }}</button>
                        <button v-if="server.ssh_public_key" type="button" class="text-muted hover:text-ink" @click="copyKey(server)">{{ copiedKey === server.id ? 'Copied' : 'Copy the public key' }}</button>
                        <span v-if="server.ssh_pinned" class="text-muted">jump host's key pinned · <button type="button" class="underline hover:text-ink" @click="forget(server)">forget it</button></span>
                        <span v-else-if="server.ssh_public_key" class="text-muted">the jump host's key is pinned at the first Try</span>
                    </p>
                </div>
            </li>
        </ul>
        <p v-else-if="editing === null" class="mt-3 text-sm text-muted">None yet. With several projects' databases on one server, add it here once.</p>

        <form v-if="editing !== null" class="mt-3 grid gap-3 rounded-md border border-line p-4 text-sm sm:grid-cols-3" @submit.prevent="save">
            <label>Name<input v-model="form.name" required placeholder="main-mysql" :class="field" class="font-mono" /><span v-if="form.errors.name" class="block text-signal">{{ form.errors.name }}</span></label>
            <label>Kind<select v-model="form.driver" :class="field"><option v-for="(label, value) in drivers" :key="value" :value="value">{{ label }}</option></select></label>
            <label>Host<input v-model="form.host" required placeholder="db.internal" :class="field" class="font-mono" /><span v-if="form.errors.host" class="block text-signal">{{ form.errors.host }}</span></label>
            <label>Port <span class="text-muted">(empty: the usual)</span><input v-model="form.port" type="number" :class="field" /></label>
            <label>Read-only user<input v-model="form.username" required autocomplete="off" :class="field" /></label>
            <label>Password
                <input v-model="form.password" type="password" autocomplete="new-password" :placeholder="editing !== 'new' && servers.find((s) => s.id === editing)?.has_password ? 'set; leave empty to keep it' : ''" :class="field" class="font-mono" />
            </label>
            <label class="flex items-center gap-2 sm:col-span-3"><input v-model="tunnel" type="checkbox" /> Through an SSH tunnel <span class="text-muted">(a jump host that can reach it: then Host above is the database as the jump host sees it)</span></label>
            <template v-if="tunnel">
                <label>Jump host<input v-model="form.ssh_host" required placeholder="bastion.example.com" :class="field" class="font-mono" /><span v-if="form.errors.ssh_host" class="block text-signal">{{ form.errors.ssh_host }}</span></label>
                <label>SSH port <span class="text-muted">(empty: 22)</span><input v-model="form.ssh_port" type="number" :class="field" /></label>
                <label>User on it<input v-model="form.ssh_user" required placeholder="tunnel" :class="field" class="font-mono" /><span v-if="form.errors.ssh_user" class="block text-signal">{{ form.errors.ssh_user }}</span></label>
                <p class="text-xs text-muted sm:col-span-3">After saving: Make a key, put its public half on the jump host, then Try.</p>
            </template>
            <p class="text-xs text-muted sm:col-span-3">Give it an account that can only read (GRANT SELECT): the tool refuses anything else too, but that is the first line. Stored encrypted; never shown again, never given to the agent.</p>
            <div class="flex gap-2 sm:col-span-3">
                <button type="submit" class="btn btn-signal" :disabled="form.processing">{{ editing === 'new' ? 'Add' : 'Save' }}</button>
                <button type="button" class="btn" @click="editing = null">Cancel</button>
            </div>
        </form>
    </section>
</template>
