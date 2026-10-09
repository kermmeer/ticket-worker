<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import CodeBlock from '../components/CodeBlock.vue';
import { ago } from '../time.js';

// Your account: your name, your own Claude sign-in, your password. For an admin, also the
// people who use this instance.
const props = defineProps({
    account: { type: Object, required: true },
    instanceClaude: { type: Boolean, default: false },
    viaHeader: { type: Boolean, default: false },
    people: { type: Array, default: null },
    invite: { type: Object, default: null },
    spaces: { type: Array, default: () => [] },
});

// Which spaces a person sees: every one, or those ticked. Open one person at a time.
const editingSpaces = ref(null);
const access = ref({ all_spaces: true, space_ids: [] });
function editSpaces(person) {
    editingSpaces.value = editingSpaces.value === person.id ? null : person.id;
    access.value = { all_spaces: person.all_spaces, space_ids: [...person.space_ids] };
}
function saveSpaces(person) {
    router.put(`/people/${person.id}/spaces`, access.value, { preserveScroll: true, onSuccess: () => (editingSpaces.value = null) });
}

const profile = useForm({ name: props.account.name });
const claude = useForm({ kind: 'token', secret: '' });
const password = useForm({ current: '', password: '', password_confirmation: '' });
const newPerson = useForm({ username: '', name: '' });

function saveClaude() {
    claude.put('/account/claude', { preserveScroll: true, onSuccess: () => claude.reset('secret') });
}
function removeClaude() {
    router.put('/account/claude', { kind: 'none' }, { preserveScroll: true });
}
function savePassword() {
    password.put('/account/password', { preserveScroll: true, onSuccess: () => password.reset() });
}
function addPerson() {
    newPerson.post('/people', { preserveScroll: true, onSuccess: () => newPerson.reset() });
}
function setRole(person, role) {
    router.put(`/people/${person.id}/role`, { role }, { preserveScroll: true });
}
const roles = { member: 'Member: works tickets', editor: 'Editor: also spaces, systems, casebook', admin: 'Admin: also people and setup' };
function remove(person) {
    if (confirm(`Remove ${person.name}? Their work stays, without their name, and their Claude sign-in is deleted.`)) {
        router.delete(`/people/${person.id}`, { preserveScroll: true });
    }
}
const field = 'mt-1 w-full rounded-md border border-line bg-page px-3 py-2 text-sm';
const open = ref(false);
</script>

<template>
    <Head title="Your account" />
    <p class="eyebrow">Your account</p>
    <h1 class="display mt-2 text-3xl">{{ account.name }} <span class="font-mono text-base text-muted">{{ account.username }}</span></h1>
    <p class="mt-2 text-sm text-muted">
        This month: <span class="font-mono text-ink">${{ account.usage.cost.toFixed(2) }}</span><template v-if="account.budget"> of your ${{ account.budget }} budget</template> of agent work you asked for
        ({{ account.usage.turns }} turns, {{ account.usage.scans }} quick scans), priced as the API would.
    </p>

    <div class="mt-8 grid gap-6 xl:grid-cols-2">
        <!-- Claude -->
        <section class="card p-5 xl:col-span-2">
            <h2 class="text-lg font-medium">Your Claude sign-in</h2>
            <p class="mt-1 max-w-3xl text-sm text-muted">
                Your analyses, questions, patches and quick scans run on it, so they count against your own Claude subscription
                or API account. Without one they run on this instance's sign-in<template v-if="!instanceClaude">, and this instance
                has none: then your agents cannot run</template>.
            </p>
            <p class="mt-3 text-sm">
                Now:
                <strong v-if="account.claude === 'token'" class="font-medium text-done">your subscription token is set</strong>
                <strong v-else-if="account.claude === 'key'" class="font-medium text-done">your API key is set</strong>
                <strong v-else class="font-medium" :class="instanceClaude ? 'text-muted' : 'text-signal'">none of your own</strong>
                <button v-if="account.claude" type="button" class="ml-2 text-muted underline hover:text-ink" @click="removeClaude">remove it</button>
            </p>
            <form class="mt-4 space-y-3" @submit.prevent="saveClaude">
                <div class="flex flex-wrap gap-2 text-sm">
                    <label class="flex items-center gap-1.5"><input v-model="claude.kind" type="radio" value="token" /> A subscription token (Pro or Max)</label>
                    <label class="flex items-center gap-1.5"><input v-model="claude.kind" type="radio" value="key" /> An API key</label>
                </div>
                <div v-if="claude.kind === 'token'" class="text-sm text-muted">
                    On your own computer, where Claude Code is signed in with your subscription, run this and paste what it prints.
                    Then turn off <em>Help improve Claude</em> in claude.ai's privacy settings, so tickets are never used for training.
                    <CodeBlock code="claude setup-token" language="shell" class="mt-2 max-w-sm" />
                </div>
                <p v-else class="text-sm text-muted">From platform.claude.com → Settings → API keys; it starts with <code>sk-ant-</code>.</p>
                <input v-model="claude.secret" type="password" autocomplete="off" :placeholder="claude.kind === 'token' ? 'sk-ant-oat…' : 'sk-ant-api…'" :class="field" class="max-w-xl font-mono" />
                <p v-if="claude.errors.secret" class="text-sm text-signal">{{ claude.errors.secret }}</p>
                <p class="text-xs text-muted">Stored encrypted. Never shown again, never sent anywhere but to Claude Code when your work runs.</p>
                <button type="submit" class="btn btn-signal" :disabled="claude.processing || !claude.secret">Save</button>
            </form>
        </section>

        <!-- Name -->
        <form class="card p-5" @submit.prevent="profile.put('/account', { preserveScroll: true })">
            <h2 class="text-lg font-medium">Your name</h2>
            <p class="mt-1 text-sm text-muted">Shown with your work; reply drafts you ask for are signed with the first word.</p>
            <input v-model="profile.name" required :class="field" class="max-w-sm" />
            <button type="submit" class="btn mt-3" :disabled="profile.processing || !profile.isDirty">Save</button>
        </form>

        <!-- Password -->
        <form v-if="!viaHeader" class="card p-5" @submit.prevent="savePassword">
            <h2 class="text-lg font-medium">Your password</h2>
            <label class="mt-2 block text-sm">Now<input v-model="password.current" type="password" autocomplete="current-password" :class="field" class="max-w-sm" /></label>
            <span v-if="password.errors.current" class="text-sm text-signal">{{ password.errors.current }}</span>
            <label class="mt-2 block text-sm">New <span class="text-muted">(10 or more)</span><input v-model="password.password" type="password" autocomplete="new-password" :class="field" class="max-w-sm" /></label>
            <span v-if="password.errors.password" class="text-sm text-signal">{{ password.errors.password }}</span>
            <label class="mt-2 block text-sm">New, again<input v-model="password.password_confirmation" type="password" autocomplete="new-password" :class="field" class="max-w-sm" /></label>
            <button type="submit" class="btn mt-3" :disabled="password.processing">Change it</button>
        </form>
    </div>

    <!-- People: admins only -->
    <section v-if="people" class="mt-10">
        <h2 class="eyebrow">People on this instance</h2>
        <div v-if="invite" role="status" class="card mt-3 border-done/40 p-4 text-sm">
            <p>Send {{ invite.name }} this link. It works once, for a week, to set their own password:</p>
            <CodeBlock :code="invite.url" language="link" class="mt-2" />
        </div>
        <ul class="card mt-3 divide-y divide-line">
            <li v-for="person in people" :key="person.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 p-4 text-sm">
                <span class="font-medium">{{ person.name }}</span>
                <span class="font-mono text-xs text-muted">{{ person.username }}</span>
                <span v-if="person.you" class="text-xs text-working">{{ person.role }}</span>
                <label v-else class="text-xs">
                    <span class="sr-only">Role of {{ person.name }}</span>
                    <select :value="person.role" class="rounded-md border border-line bg-page px-1.5 py-0.5 text-xs" @change="setRole(person, $event.target.value)">
                        <option v-for="(label, value) in roles" :key="value" :value="value">{{ label }}</option>
                    </select>
                </label>
                <span class="text-xs" :class="person.has_claude ? 'text-done' : 'text-muted'">{{ person.has_claude ? 'own Claude sign-in' : 'no Claude sign-in of their own' }}</span>
                <span class="text-xs text-muted">{{ person.last_seen_at ? `seen ${ago(person.last_seen_at)}` : 'not signed in yet' }}</span>
                <span class="font-mono text-xs" :class="person.usage.cost ? 'text-ink' : 'text-muted'">
                    ${{ person.usage.cost.toFixed(2) }}<template v-if="person.budget"> of ${{ person.budget }}</template> this month
                </span>
                <label class="text-xs text-muted">
                    <span class="sr-only">Monthly budget of {{ person.name }} in dollars</span>
                    <input
                        :value="person.budget ?? ''"
                        type="number"
                        min="1"
                        step="10"
                        placeholder="no budget"
                        class="w-24 rounded-md border border-line bg-page px-1.5 py-0.5 text-xs"
                        @change="router.put(`/people/${person.id}/budget`, { monthly_budget_usd: $event.target.value || null }, { preserveScroll: true })"
                    />
                </label>
                <button v-if="person.role !== 'admin'" type="button" class="text-xs text-muted hover:text-ink" :aria-expanded="editingSpaces === person.id" @click="editSpaces(person)">
                    {{ person.all_spaces ? 'every space' : `${person.space_ids.length} space${person.space_ids.length === 1 ? '' : 's'}` }}
                </button>
                <span v-else class="text-xs text-muted">every space</span>
                <span v-if="!person.you" class="ml-auto flex gap-3 text-xs">
                    <button v-if="!viaHeader" type="button" class="text-muted hover:text-ink" @click="router.post(`/people/${person.id}/link`, {}, { preserveScroll: true })">New link</button>
                    <button type="button" class="text-muted hover:text-signal" @click="remove(person)">Remove</button>
                </span>
                <span v-else class="ml-auto text-xs text-muted">you</span>
                <form v-if="editingSpaces === person.id" class="w-full space-y-2 rounded-md border border-line p-3" @submit.prevent="saveSpaces(person)">
                    <label class="flex items-center gap-2"><input v-model="access.all_spaces" type="checkbox" /> Every space, also new ones</label>
                    <div v-if="!access.all_spaces" class="flex flex-wrap gap-2">
                        <label v-for="space in spaces" :key="space.id" class="flex items-center gap-1.5 rounded-md border border-line px-2 py-1 text-xs">
                            <input v-model="access.space_ids" type="checkbox" :value="space.id" /> {{ space.label }}
                        </label>
                    </div>
                    <button type="submit" class="btn px-3 py-1 text-xs">Save</button>
                </form>
            </li>
        </ul>
        <p v-if="viaHeader" class="mt-3 text-sm text-muted">People are added by themselves: the sign-in in front of the app tells who they are, the first time they open it.</p>
        <form v-else class="card mt-3 flex flex-wrap items-end gap-3 p-4" @submit.prevent="addPerson">
            <label class="text-sm">Name<input v-model="newPerson.name" required :class="field" /></label>
            <label class="text-sm">Username<input v-model="newPerson.username" required autocomplete="off" :class="field" class="font-mono" /></label>
            <button type="submit" class="btn btn-signal" :disabled="newPerson.processing">Add, and get their link</button>
            <p v-if="newPerson.errors.username" class="w-full text-sm text-signal">{{ newPerson.errors.username }}</p>
        </form>
    </section>

    <form class="mt-10" @submit.prevent="router.post('/sign-out')">
        <button v-if="!viaHeader" type="submit" class="text-sm text-muted underline hover:text-ink">Sign out</button>
    </form>
</template>
