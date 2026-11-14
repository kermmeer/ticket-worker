<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';

// A system's APIs: what the agent may check a ticket against. The secret goes in and never
// comes back out: the page only knows whether one is set.
const props = defineProps({
    system: { type: Object, required: true },
    authKinds: { type: Object, required: true },
    accessKinds: { type: Object, default: () => ({}) },
});

// One sign-in for all of this system's APIs; an API set to "as the system" uses it.
const shared = props.system.api_sign_in ?? { auth: 'none' };
const signIn = useForm({ auth: shared.auth ?? 'none', username: shared.username ?? '', field: shared.field ?? '', secret: '' });
const signInOpen = ref(false);
function saveSignIn() {
    signIn.put(`/systems/${props.system.id}/api-sign-in`, { preserveScroll: true, onSuccess: () => { signIn.secret = ''; signInOpen.value = false; } });
}
const ownKinds = Object.fromEntries(Object.entries(props.authKinds).filter(([key]) => key !== 'system'));
const signInLine = () => (shared.auth && shared.auth !== 'none'
    ? `${ownKinds[shared.auth] ?? shared.auth}${shared.username ? ` as ${shared.username}` : ''}${shared.field ? ` in ${shared.field}` : ''} · ${shared.has_secret ? 'secret set' : 'secret missing'}`
    : 'none set');

const blank = { name: '', base_url: '', auth: props.system.api_sign_in?.auth && props.system.api_sign_in.auth !== 'none' ? 'system' : 'basic', username: '', field: '', secret: '', access: 'read', notes: '', clear_secret: false };
const editing = ref(null); // an api id, 'new', or null
const form = useForm({ ...blank });
const tryPath = ref({});

function start(api = null) {
    form.clearErrors();
    form.defaults(api ? { ...blank, ...api, username: api.username ?? '', field: api.field ?? '', notes: api.notes ?? '', secret: '', access: api.access ?? (api.allow_post ? 'query' : 'read') } : { ...blank });
    form.reset();
    editing.value = api ? api.id : 'new';
}

function save() {
    const base = `/systems/${props.system.id}/apis`;
    const options = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    editing.value === 'new' ? form.post(base, options) : form.put(`${base}/${editing.value}`, options);
}

function remove(api) {
    if (confirm(`Remove ${api.name}? The agent can no longer call it.`)) {
        router.delete(`/systems/${props.system.id}/apis/${api.id}`, { preserveScroll: true });
    }
}

function tryIt(api) {
    router.post(`/systems/${props.system.id}/apis/${api.id}/try`, { path: tryPath.value[api.id] ?? '' }, { preserveScroll: true });
}

const needsUser = () => form.auth === 'basic';
const needsField = () => form.auth === 'header' || form.auth === 'query';
const secretLabel = { basic: 'Password or token', bearer: 'Token', header: 'Value', query: 'Value' };
</script>

<template>
    <div class="mt-5 border-t border-line pt-4">
        <div class="flex flex-wrap items-center gap-3">
            <p class="text-sm font-medium">APIs</p>
            <span class="text-sm text-muted">for the agent to check tickets against real data, and to propose changes where you allow it; it never sees a secret</span>
            <button v-if="editing === null" type="button" class="btn ml-auto px-3 py-1.5" @click="start()">Add an API</button>
        </div>

        <!-- The sign-in its APIs share. -->
        <div class="mt-3 rounded-md border border-line p-3 text-sm">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span class="font-medium">Sign-in for all its APIs</span>
                <span class="text-xs text-muted">{{ signInLine() }}</span>
                <button type="button" class="ml-auto text-xs text-muted underline hover:text-ink" :aria-expanded="signInOpen" @click="signInOpen = !signInOpen">{{ signInOpen ? 'Close' : 'Set' }}</button>
            </div>
            <form v-if="signInOpen" class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="saveSignIn">
                <label class="block">Kind
                    <select v-model="signIn.auth" class="mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5">
                        <option v-for="(label, value) in ownKinds" :key="value" :value="value">{{ label }}</option>
                    </select>
                </label>
                <label v-if="signIn.auth === 'basic'" class="block">Username
                    <input v-model="signIn.username" autocomplete="off" class="mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5" />
                </label>
                <label v-if="signIn.auth === 'header' || signIn.auth === 'query'" class="block">{{ signIn.auth === 'header' ? 'Header name' : 'Parameter name' }}
                    <input v-model="signIn.field" class="mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5 font-mono" />
                </label>
                <label v-if="signIn.auth !== 'none'" class="block">{{ secretLabel[signIn.auth] }}
                    <input v-model="signIn.secret" type="password" autocomplete="new-password" :placeholder="shared.has_secret ? 'set; leave empty to keep it' : ''" class="mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5 font-mono" />
                </label>
                <p v-if="Object.keys(signIn.errors).length" class="text-signal sm:col-span-2">{{ Object.values(signIn.errors)[0] }}</p>
                <p class="text-xs text-muted sm:col-span-2">Each API set to "As the system" uses this; an API with a sign-in of its own keeps that. Stored encrypted, never shown again.</p>
                <div class="sm:col-span-2"><button type="submit" class="btn btn-signal" :disabled="signIn.processing">Save</button></div>
            </form>
        </div>

        <ul v-if="system.apis.length" class="mt-3 divide-y divide-line rounded-md border border-line">
            <li v-for="api in system.apis" :key="api.id" class="p-3 text-sm">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span class="font-mono">{{ system.name }}/{{ api.name }}</span>
                    <span class="font-mono text-xs break-all text-muted">{{ api.base_url }}</span>
                    <span class="text-xs text-muted">
                        <template v-if="api.auth === 'system'">as the system</template>
                        <template v-else>
                            {{ authKinds[api.auth] }}<template v-if="api.username"> as {{ api.username }}</template><template v-if="api.field"> in {{ api.field }}</template>
                            · <span :class="api.auth === 'none' || api.has_secret ? '' : 'text-signal'">{{ api.auth === 'none' ? 'no secret' : api.has_secret ? 'secret set' : 'secret missing' }}</span>
                        </template>
                        · <span :class="{ 'font-medium text-waiting': api.access === 'write' }">{{ { read: 'read only', query: 'read and query', write: 'changes after approval' }[api.access ?? (api.allow_post ? 'query' : 'read')] }}</span>
                    </span>
                    <span class="ml-auto flex gap-2">
                        <button type="button" class="text-xs text-muted hover:text-ink" @click="start(api)">Edit</button>
                        <button type="button" class="text-xs text-muted hover:text-signal" @click="remove(api)">Remove</button>
                    </span>
                </div>
                <p v-if="api.notes" class="mt-1 text-xs whitespace-pre-wrap text-muted">{{ api.notes }}</p>
                <form class="mt-2 flex flex-wrap items-center gap-2" @submit.prevent="tryIt(api)">
                    <label :for="`try-${api.id}`" class="text-xs text-muted">Try a GET of</label>
                    <input :id="`try-${api.id}`" v-model="tryPath[api.id]" placeholder="/ or a path" class="min-w-0 flex-1 rounded-md border border-line bg-page px-2 py-1 font-mono text-xs" />
                    <button type="submit" class="btn px-2.5 py-1 text-xs">Try</button>
                </form>
            </li>
        </ul>

        <form v-if="editing !== null" class="mt-3 grid gap-3 rounded-md border border-line p-4 text-sm sm:grid-cols-2" @submit.prevent="save">
            <label class="block">
                <span class="font-medium">Name</span>
                <input v-model="form.name" required placeholder="boss-api" class="mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5 font-mono" />
                <span v-if="form.errors.name" class="mt-1 block text-signal">{{ form.errors.name }}</span>
            </label>
            <label class="block">
                <span class="font-medium">Base address</span>
                <input v-model="form.base_url" required placeholder="https://boss.example.com/api" class="mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5 font-mono" />
                <span class="mt-1 block text-xs text-muted">The agent can only reach paths under it.</span>
                <span v-if="form.errors.base_url" class="mt-1 block text-signal">{{ form.errors.base_url }}</span>
            </label>
            <label class="block">
                <span class="font-medium">Sign-in</span>
                <select v-model="form.auth" class="mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5">
                    <option v-for="(label, value) in authKinds" :key="value" :value="value">{{ label }}</option>
                </select>
            </label>
            <label v-if="needsUser()" class="block">
                <span class="font-medium">Username</span>
                <input v-model="form.username" autocomplete="off" class="mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5" />
                <span v-if="form.errors.username" class="mt-1 block text-signal">{{ form.errors.username }}</span>
            </label>
            <label v-if="needsField()" class="block">
                <span class="font-medium">{{ form.auth === 'header' ? 'Header name' : 'Parameter name' }}</span>
                <input v-model="form.field" :placeholder="form.auth === 'header' ? 'X-Api-Key' : 'api_key'" class="mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5 font-mono" />
                <span v-if="form.errors.field" class="mt-1 block text-signal">{{ form.errors.field }}</span>
            </label>
            <label v-if="form.auth !== 'none' && form.auth !== 'system'" class="block">
                <span class="font-medium">{{ secretLabel[form.auth] }}</span>
                <input
                    v-model="form.secret"
                    type="password"
                    autocomplete="new-password"
                    :placeholder="editing !== 'new' && system.apis.find((a) => a.id === editing)?.has_secret ? 'set; leave empty to keep it' : ''"
                    class="mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5 font-mono"
                />
                <span class="mt-1 block text-xs text-muted">Stored encrypted. Never shown again, never given to the agent.</span>
            </label>
            <label class="block sm:col-span-2">
                <span class="font-medium">Notes for the agent</span>
                <textarea v-model="form.notes" rows="3" placeholder="What it offers, e.g. GET /finance/view/service/{id} is a service with its recurring price. The API definition is in docs/api.yaml of this system." class="mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5"></textarea>
            </label>
            <fieldset class="sm:col-span-2">
                <legend class="font-medium">What the agent may do with it</legend>
                <label v-for="(label, value) in accessKinds" :key="value" class="mt-1.5 flex items-start gap-2">
                    <input v-model="form.access" type="radio" :value="value" class="mt-1" />
                    <span>{{ label }}</span>
                </label>
                <span class="mt-1 block text-xs text-muted">
                    Read and query: only for APIs whose POSTs search. With changes after approval, nothing is sent until an editor presses Run it on the ticket;
                    the sign-in above must be allowed to make those changes.
                </span>
            </fieldset>
            <div class="flex gap-2 sm:col-span-2">
                <button type="submit" class="btn btn-signal" :disabled="form.processing">{{ editing === 'new' ? 'Add' : 'Save' }}</button>
                <button type="button" class="btn" @click="editing = null">Cancel</button>
            </div>
        </form>
    </div>
</template>
