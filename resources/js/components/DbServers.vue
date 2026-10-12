<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';

// Database servers: one read-only login for every project database on that server. The
// password goes in and never comes back out.
const props = defineProps({
    servers: { type: Array, required: true },
    drivers: { type: Object, required: true },
});

const blank = { name: '', driver: 'mysql', host: '', port: '', username: '', password: '', notes: '' };
const editing = ref(null); // a server id, 'new', or null
const form = useForm({ ...blank });

function start(server = null) {
    form.clearErrors();
    form.defaults(server ? { ...blank, ...server, port: server.port ?? '', notes: server.notes ?? '', password: '' } : { ...blank });
    form.reset();
    editing.value = server ? server.id : 'new';
}

function save() {
    const options = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    editing.value === 'new' ? form.post('/systems/db-servers', options) : form.put(`/systems/db-servers/${editing.value}`, options);
}

function remove(server) {
    if (confirm(`Remove ${server.name}?`)) {
        router.delete(`/systems/db-servers/${server.id}`, { preserveScroll: true });
    }
}

const field = 'mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5';
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
                <span class="ml-auto flex gap-3 text-xs">
                    <button type="button" class="text-muted hover:text-ink" @click="router.post(`/systems/db-servers/${server.id}/try`, {}, { preserveScroll: true })">Try</button>
                    <button type="button" class="text-muted hover:text-ink" @click="start(server)">Edit</button>
                    <button v-if="!server.used" type="button" class="text-muted hover:text-signal" @click="remove(server)">Remove</button>
                </span>
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
            <p class="text-xs text-muted sm:col-span-3">Give it an account that can only read (GRANT SELECT): the tool refuses anything else too, but that is the first line. Stored encrypted; never shown again, never given to the agent.</p>
            <div class="flex gap-2 sm:col-span-3">
                <button type="submit" class="btn btn-signal" :disabled="form.processing">{{ editing === 'new' ? 'Add' : 'Save' }}</button>
                <button type="button" class="btn" @click="editing = null">Cancel</button>
            </div>
        </form>
    </section>
</template>
