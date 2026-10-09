<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';

// A project's databases: queried read-only for the agent. The password goes in and never
// comes back out; the page only knows whether one is set.
const props = defineProps({
    system: { type: Object, required: true },
    drivers: { type: Object, required: true },
});

const blank = { name: '', driver: 'mysql', host: '', port: '', database: '', username: '', password: '', notes: '' };
const editing = ref(null); // a database id, 'new', or null
const form = useForm({ ...blank });

function start(db = null) {
    form.clearErrors();
    form.defaults(db ? { ...blank, ...db, port: db.port ?? '', notes: db.notes ?? '', password: '' } : { ...blank });
    form.reset();
    editing.value = db ? db.id : 'new';
}

function save() {
    const base = `/systems/${props.system.id}/databases`;
    const options = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    editing.value === 'new' ? form.post(base, options) : form.put(`${base}/${editing.value}`, options);
}

function remove(db) {
    if (confirm(`Remove ${db.name}? The agent can no longer query it.`)) {
        router.delete(`/systems/${props.system.id}/databases/${db.id}`, { preserveScroll: true });
    }
}

const field = 'mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5';
</script>

<template>
    <div class="mt-5 border-t border-line pt-4">
        <div class="flex flex-wrap items-center gap-3">
            <p class="text-sm font-medium">Databases</p>
            <span class="text-sm text-muted">read-only queries for the agent; give it an account that can only read</span>
            <button v-if="editing === null" type="button" class="btn ml-auto px-3 py-1.5" @click="start()">Add a database</button>
        </div>

        <ul v-if="system.databases?.length" class="mt-3 divide-y divide-line rounded-md border border-line">
            <li v-for="db in system.databases" :key="db.id" class="p-3 text-sm">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span class="font-mono">{{ system.name }}/{{ db.name }}</span>
                    <span class="font-mono text-xs break-all text-muted">{{ db.driver }} {{ db.username }}@{{ db.host }}{{ db.port ? `:${db.port}` : '' }}/{{ db.database }}</span>
                    <span class="text-xs" :class="db.has_password ? 'text-muted' : 'text-signal'">{{ db.has_password ? 'password set' : 'no password' }}</span>
                    <span v-if="!db.available" class="text-xs text-signal">this server has no {{ db.driver }} driver</span>
                    <span class="ml-auto flex gap-3 text-xs">
                        <button type="button" class="text-muted hover:text-ink" @click="router.post(`/systems/${system.id}/databases/${db.id}/try`, {}, { preserveScroll: true })">Try</button>
                        <button type="button" class="text-muted hover:text-ink" @click="start(db)">Edit</button>
                        <button type="button" class="text-muted hover:text-signal" @click="remove(db)">Remove</button>
                    </span>
                </div>
                <p v-if="db.notes" class="mt-1 text-xs whitespace-pre-wrap text-muted">{{ db.notes }}</p>
            </li>
        </ul>

        <form v-if="editing !== null" class="mt-3 grid gap-3 rounded-md border border-line p-4 text-sm sm:grid-cols-3" @submit.prevent="save">
            <label>Name<input v-model="form.name" required placeholder="crm-db" :class="field" class="font-mono" /><span v-if="form.errors.name" class="block text-signal">{{ form.errors.name }}</span></label>
            <label>Kind<select v-model="form.driver" :class="field"><option v-for="(label, value) in drivers" :key="value" :value="value">{{ label }}</option></select></label>
            <label>Database<input v-model="form.database" required :class="field" class="font-mono" /></label>
            <label>Host<input v-model="form.host" required placeholder="db.internal" :class="field" class="font-mono" /><span v-if="form.errors.host" class="block text-signal">{{ form.errors.host }}</span></label>
            <label>Port <span class="text-muted">(empty: the usual)</span><input v-model="form.port" type="number" :class="field" /></label>
            <label>User<input v-model="form.username" required autocomplete="off" :class="field" /></label>
            <label class="sm:col-span-3">Password
                <input v-model="form.password" type="password" autocomplete="new-password" :placeholder="editing !== 'new' && system.databases.find((d) => d.id === editing)?.has_password ? 'set; leave empty to keep it' : ''" :class="field" class="max-w-sm font-mono" />
                <span class="block text-xs text-muted">Stored encrypted; never shown again, never given to the agent.</span>
            </label>
            <label class="sm:col-span-3">Notes for the agent
                <textarea v-model="form.notes" rows="2" placeholder="What it holds: invoices (invoice, invoice_line), services (service.recurring_price)…" :class="field"></textarea>
            </label>
            <div class="flex gap-2 sm:col-span-3">
                <button type="submit" class="btn btn-signal" :disabled="form.processing">{{ editing === 'new' ? 'Add' : 'Save' }}</button>
                <button type="button" class="btn" @click="editing = null">Cancel</button>
            </div>
        </form>
    </div>
</template>
