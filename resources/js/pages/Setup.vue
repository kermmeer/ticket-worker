<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import CodeBlock from '../components/CodeBlock.vue';
import SettingsFields from '../components/SettingsFields.vue';

const props = defineProps({
    checks: { type: Array, required: true },
    replyRules: { type: String, required: true },
    defaultReplyRules: { type: String, required: true },
    firstName: { type: String, default: null },
    autoPatch: { type: Boolean, default: true },
    answerRules: { type: String, required: true },
    defaultAnswerRules: { type: String, required: true },
    sharedClaude: { type: String, default: null },
    defaults: { type: Object, required: true },
    jiraSites: { type: Array, default: () => [] },
    savedDefaults: { type: Object, default: () => ({}) },
    envClaude: { type: String, default: null },
});

// The defaults as saved here; an empty field is what .env gives, shown as its placeholder.
const defaultsValues = ref({ ...props.savedDefaults });
function saveDefaults() {
    router.put('/setup/settings', defaultsValues.value, { preserveScroll: true });
}

const shared = useForm({ kind: 'key', secret: '' });

// More Jira sites, for spaces whose projects live elsewhere.
const site = useForm({ name: '', base_url: '', email: '', token: '', open_url: '', open_label: '' });
function addSite() {
    site.post('/setup/jira-sites', { preserveScroll: true, onSuccess: () => site.reset() });
}
function removeSite(other) {
    if (confirm(`Remove ${other.name}?`)) {
        router.delete(`/setup/jira-sites/${other.id}`, { preserveScroll: true });
    }
}
function saveShared() {
    shared.put('/setup/claude', { preserveScroll: true, onSuccess: () => shared.reset('secret') });
}

const rules = useForm({ rules: props.replyRules });

const answers = useForm({ rules: props.answerRules });

function saveAnswers() {
    answers.put('/setup/answer-rules', { preserveScroll: true });
}

function setAutoPatch(on) {
    router.put('/setup/auto-patch', { on }, { preserveScroll: true });
}

function saveRules() {
    rules.put('/setup/reply-rules', { preserveScroll: true });
}

const groups = computed(() => {
    const byName = new Map();
    for (const check of props.checks) {
        if (!byName.has(check.group)) {
            byName.set(check.group, []);
        }
        byName.get(check.group).push(check);
    }
    return [...byName].map(([name, checks]) => ({ name, checks }));
});

const done = computed(() => props.checks.filter((check) => check.state === 'ok').length);

const marks = {
    ok: { label: 'Done', tone: 'text-done' },
    todo: { label: 'To do', tone: 'text-signal' },
    note: { label: 'Note', tone: 'text-waiting' },
};
</script>

<template>
    <Head title="Setup" />

    <p class="eyebrow">Setup</p>
    <h1 class="display mt-3 text-5xl leading-[1.05] sm:text-6xl">{{ done }} of {{ checks.length }} in place.</h1>
    <p class="mt-5 max-w-2xl text-lg text-muted">
        What Ticket Worker needs before it can read tickets and run agents. Secrets show as set or not set, never as
        their value.
    </p>

    <section v-for="group in groups" :key="group.name" class="mt-10">
        <h2 class="eyebrow">{{ group.name }}</h2>
        <ul class="mt-3 divide-y divide-line rounded-md border border-line bg-surface">
            <li v-for="check in group.checks" :key="check.key" class="grid grid-cols-[4.5rem_1fr] gap-x-4 p-4">
                <span class="pt-0.5 font-mono text-xs font-medium tracking-wide uppercase" :class="marks[check.state].tone">
                    {{ marks[check.state].label }}
                </span>
                <div class="min-w-0">
                    <p class="font-medium">{{ check.title }}</p>
                    <p class="mt-1 text-sm break-words text-muted">{{ check.detail }}</p>
                </div>
            </li>
        </ul>
    </section>

    <section class="mt-10">
        <h2 class="eyebrow">More Jira sites</h2>
        <div class="card mt-3 p-5">
            <p class="max-w-3xl text-sm text-muted">
                The site in .env is the default. Add another for spaces whose projects live there (a customer's Jira, say);
                a new space then picks its site. Its token is stored encrypted and never shown again.
            </p>
            <ul v-if="jiraSites.length" class="mt-3 divide-y divide-line rounded-md border border-line text-sm">
                <li v-for="other in jiraSites" :key="other.id" class="flex flex-wrap items-baseline gap-x-3 p-3">
                    <span class="font-medium">{{ other.name }}</span>
                    <span class="font-mono text-xs text-muted">{{ other.base_url }} · {{ other.email }}</span>
                    <span class="text-xs text-muted">{{ other.spaces }} space{{ other.spaces === 1 ? '' : 's' }}</span>
                    <button v-if="!other.spaces" type="button" class="ml-auto text-xs text-muted hover:text-signal" @click="removeSite(other)">Remove</button>
                </li>
            </ul>
            <form class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="addSite">
                <label class="text-sm">Name<input v-model="site.name" required placeholder="Customer X" class="mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5" /></label>
                <label class="text-sm">Address<input v-model="site.base_url" required type="url" placeholder="https://customer-x.atlassian.net" class="mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5 font-mono" /><span v-if="site.errors.base_url" class="block text-signal">{{ site.errors.base_url }}</span></label>
                <label class="text-sm">Account e-mail<input v-model="site.email" required type="email" class="mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5" /></label>
                <label class="text-sm">API token<input v-model="site.token" required type="password" autocomplete="new-password" class="mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5 font-mono" /></label>
                <label class="text-sm">Open tickets in <span class="text-muted">(optional, with {key})</span><input v-model="site.open_url" placeholder="https://outbox.example.com/{key}" class="mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5 font-mono" /><span v-if="site.errors.open_url" class="block text-signal">{{ site.errors.open_url }}</span></label>
                <label class="text-sm">Called <span class="text-muted">(optional)</span><input v-model="site.open_label" placeholder="Jira" class="mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5" /></label>
                <div class="sm:col-span-2"><button type="submit" class="btn" :disabled="site.processing">Add the site</button></div>
            </form>
        </div>
    </section>

    <section class="mt-10">
        <h2 class="eyebrow">Defaults for every space</h2>
        <form class="card mt-3 p-5" @submit.prevent="saveDefaults">
            <p class="mb-4 max-w-3xl text-sm text-muted">What a space uses unless it sets its own (Spaces → Set up). Empty here is what .env says, shown in grey.</p>
            <SettingsFields v-model="defaultsValues" :defaults="defaults" />
            <button type="submit" class="btn btn-signal mt-4">Save the defaults</button>
        </form>
    </section>

    <section class="mt-10">
        <h2 class="eyebrow">Claude, shared</h2>
        <form class="card mt-3 space-y-3 p-5" @submit.prevent="saveShared">
            <p class="max-w-3xl text-sm text-muted">
                One sign-in for everyone who has none of their own: the agents of anyone without one run on it, and so do the
                automatic quick scans of new tickets. Someone's own sign-in (Your account) always goes first. When the server's
                .env has one, that is the shared one; otherwise set it here. Give it a budget
                where you make it (a workspace limit in the Claude Console), since everyone draws on it.
            </p>
            <p class="text-sm">
                Now:
                <strong v-if="envClaude" class="font-medium text-done">the server's .env has a {{ envClaude }}: that is the shared sign-in</strong>
                <strong v-else-if="sharedClaude" class="font-medium text-done">a shared {{ sharedClaude === 'key' ? 'API key' : 'subscription token' }} is set here</strong>
                <strong v-else class="font-medium text-signal">none: only people with their own sign-in can run agents, and no automatic quick scans</strong>
                <button v-if="sharedClaude && !envClaude" type="button" class="ml-2 text-muted underline hover:text-ink" @click="router.put('/setup/claude', { kind: 'none' }, { preserveScroll: true })">remove it</button>
            </p>
            <p v-if="envClaude" class="text-sm text-muted">
                To set one here instead, remove ANTHROPIC_API_KEY and CLAUDE_CODE_OAUTH_TOKEN from .env (and restart the workers).
                <template v-if="sharedClaude"> One is saved here too; it is used only once .env has none.</template>
            </p>
            <template v-if="!envClaude">
            <div class="flex flex-wrap gap-3 text-sm">
                <label class="flex items-center gap-1.5"><input v-model="shared.kind" type="radio" value="key" /> An API key <span class="text-muted">(best for sharing)</span></label>
                <label class="flex items-center gap-1.5"><input v-model="shared.kind" type="radio" value="token" /> A subscription token</label>
            </div>

            <!-- How to get one: the steps for the kind picked above. -->
            <div v-if="shared.kind === 'key'" class="rounded-md border border-line p-4 text-sm">
                <p class="font-medium">Getting an API key</p>
                <ol class="mt-2 list-decimal space-y-1.5 pl-5">
                    <li>Sign in at <a href="https://platform.claude.com" target="_blank" rel="noopener" class="underline">platform.claude.com</a> (the Claude Console), or make an account there. The API is billed on its own, not through a Claude subscription: add credits or a payment method under Billing first.</li>
                    <li>Under <a href="https://platform.claude.com/settings/workspaces" target="_blank" rel="noopener" class="underline">Settings → Workspaces</a>, make a workspace for Ticket Worker and give it a <strong class="font-medium">spend limit</strong>. Everyone without their own sign-in draws on this key, so the limit is what keeps the month in hand; its usage shows on its own there.</li>
                    <li>Under <a href="https://platform.claude.com/settings/keys" target="_blank" rel="noopener" class="underline">Settings → API keys</a>, press <strong class="font-medium">Create key</strong>. Name it after this instance, put it in the workspace from step 2, and link it to a service account rather than yourself, since others rely on it.</li>
                    <li>Copy it: it starts with <code class="font-mono">sk-ant-api</code> and is shown <strong class="font-medium">only once</strong>. Paste it below.</li>
                </ol>
                <p class="mt-2 text-xs text-muted">The Commercial Terms apply: Anthropic does not train on what the agents send, and keeps it 30 days.</p>
            </div>
            <div v-else class="rounded-md border border-line p-4 text-sm">
                <p class="font-medium">Getting a subscription token</p>
                <p class="mt-1 text-signal">A Pro or Max subscription is one person's plan, with that person's usage limits. To share a sign-in among several people, use an API key instead; a token here is for when it really is one person's, say the person who runs this instance.</p>
                <ol class="mt-2 list-decimal space-y-1.5 pl-5">
                    <li>On a computer where Claude Code is installed and signed in with that subscription, run this, and follow the link it opens to approve it:
                        <CodeBlock code="claude setup-token" language="shell" class="mt-2 max-w-sm" />
                    </li>
                    <li>Copy the token it prints (it starts with <code class="font-mono">sk-ant-oat</code>) and paste it below. It is made for unattended use; never copy someone's <code class="font-mono">~/.claude</code> folder instead, which would hand the agents their settings and other projects.</li>
                    <li>In <a href="https://claude.ai/settings" target="_blank" rel="noopener" class="underline">claude.ai → Settings → Privacy</a>, turn <strong class="font-medium">off</strong> <em>Help improve Claude</em>. With it on, the Consumer Terms let Anthropic train on what the agents read, ticket contents included.</li>
                </ol>
                <p class="mt-2 text-xs text-muted">Its use counts against that subscription's limits, the same ones its owner's own Claude use draws on. Costs shown here are what the work would cost on the API.</p>
            </div>
            <input v-model="shared.secret" type="password" autocomplete="off" :placeholder="shared.kind === 'key' ? 'sk-ant-api…' : 'sk-ant-oat…'" class="w-full max-w-xl rounded-md border border-line bg-page px-3 py-2 font-mono text-sm" />
            <p v-if="shared.errors.secret" class="text-sm text-signal">{{ shared.errors.secret }}</p>
            <p class="text-xs text-muted">Stored encrypted; never shown again.</p>
            <button type="submit" class="btn btn-signal" :disabled="shared.processing || !shared.secret">Save</button>
            </template>
        </form>
    </section>

    <section class="mt-10">
        <h2 class="eyebrow">Answers</h2>
        <form class="card mt-3 p-5" @submit.prevent="saveAnswers">
            <label for="answers" class="font-medium">How the agent answers you</label>
            <p class="mt-1 max-w-3xl text-sm text-muted">
                Sent with every question and analysis, so a change applies from the agent's next turn, also in sessions already
                open. For replies to reporters, see below.
            </p>
            <textarea id="answers" v-model="answers.rules" rows="7" class="mt-3 w-full rounded-md border border-line bg-page px-3 py-2 text-sm"></textarea>
            <p v-if="answers.errors.rules" class="mt-1 text-sm text-signal">{{ answers.errors.rules }}</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="submit" class="btn btn-signal" :disabled="answers.processing || !answers.isDirty">Save</button>
                <button type="button" class="btn" @click="answers.rules = defaultAnswerRules">Back to the default</button>
            </div>
        </form>
    </section>

    <section class="mt-10">
        <h2 class="eyebrow">Replies</h2>
        <form class="card mt-3 p-5" @submit.prevent="saveRules">
            <label for="rules" class="font-medium">How replies to reporters are written</label>
            <p class="mt-1 max-w-3xl text-sm text-muted">
                Every reply draft the agent writes follows these rules, in the reply's own language. A change applies to the
                next draft, also in a conversation already going.
                <code>{first_name}</code> becomes the first name of the Jira account this app reads with:
                <strong v-if="firstName" class="font-medium text-ink">{{ firstName }}</strong>
                <template v-else>not known until Jira answers, and until then "the support team"</template>.
            </p>
            <textarea id="rules" v-model="rules.rules" rows="8" class="mt-3 w-full rounded-md border border-line bg-page px-3 py-2 text-sm"></textarea>
            <p v-if="rules.errors.rules" class="mt-1 text-sm text-signal">{{ rules.errors.rules }}</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="submit" class="btn btn-signal" :disabled="rules.processing || !rules.isDirty">Save</button>
                <button type="button" class="btn" @click="rules.rules = defaultReplyRules">Back to the default</button>
            </div>
        </form>
    </section>

    <section class="mt-10">
        <h2 class="eyebrow">Patches</h2>
        <div class="card mt-3 p-5">
            <label class="flex items-start gap-3">
                <input type="checkbox" class="mt-1" :checked="autoPatch" @change="setAutoPatch($event.target.checked)" />
                <span>
                    <span class="font-medium">Prepare a patch by itself when the fix is a code change</span>
                    <span class="mt-1 block max-w-3xl text-sm text-muted">
                        After an analysis whose proposal changes code, the agent writes the change in a copy of the system and the
                        ticket offers it as a file for <code>git am</code>. That is another turn, so it costs about as much as a
                        question. Off: only when you press <em>Prepare a patch</em>.
                    </span>
                </span>
            </label>
        </div>
    </section>
</template>
