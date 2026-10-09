<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
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
    savedDefaults: { type: Object, default: () => ({}) },
    envClaude: { type: Boolean, default: false },
});

// The defaults as saved here; an empty field is what .env gives, shown as its placeholder.
const defaultsValues = ref({ ...props.savedDefaults });
function saveDefaults() {
    router.put('/setup/settings', defaultsValues.value, { preserveScroll: true });
}

const shared = useForm({ kind: 'token', secret: '' });
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
                automatic quick scans of new tickets. Someone's own sign-in (Your account) always goes first. Give it a budget
                where you make it (a workspace limit in the Claude Console), since everyone draws on it.
            </p>
            <p class="text-sm">
                Now:
                <strong v-if="sharedClaude" class="font-medium text-done">a shared {{ sharedClaude === 'key' ? 'API key' : 'subscription token' }} is set</strong>
                <strong v-else-if="envClaude" class="font-medium text-muted">none here; the one in .env is used</strong>
                <strong v-else class="font-medium text-signal">none: only people with their own sign-in can run agents, and no automatic quick scans</strong>
                <button v-if="sharedClaude" type="button" class="ml-2 text-muted underline hover:text-ink" @click="router.put('/setup/claude', { kind: 'none' }, { preserveScroll: true })">remove it</button>
            </p>
            <div class="flex flex-wrap gap-3 text-sm">
                <label class="flex items-center gap-1.5"><input v-model="shared.kind" type="radio" value="key" /> An API key</label>
                <label class="flex items-center gap-1.5"><input v-model="shared.kind" type="radio" value="token" /> A subscription token</label>
            </div>
            <input v-model="shared.secret" type="password" autocomplete="off" :placeholder="shared.kind === 'key' ? 'sk-ant-api…' : 'sk-ant-oat…'" class="w-full max-w-xl rounded-md border border-line bg-page px-3 py-2 font-mono text-sm" />
            <p v-if="shared.errors.secret" class="text-sm text-signal">{{ shared.errors.secret }}</p>
            <p class="text-xs text-muted">Stored encrypted; never shown again.</p>
            <button type="submit" class="btn btn-signal" :disabled="shared.processing || !shared.secret">Save</button>
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
