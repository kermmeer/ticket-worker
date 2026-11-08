<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { copyText } from '../copy.js';

// Own work: what you are after, your screenshots, and the Jira ticket it becomes.
const props = defineProps({
    ticket: { type: Object, required: true },
    types: { type: Array, required: true },
    jiraWrite: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
});
const page = usePage();

const about = useForm({ title: props.ticket.summary, description: props.ticket.local_description ?? '', type: props.ticket.issue_type ?? 'Improvement' });
function saveAbout() {
    about.put(`/work/${props.ticket.id}`, { preserveScroll: true });
}

// Screenshots: chosen, dropped, or pasted (Ctrl+V) anywhere on the page.
const uploading = ref(false);
function upload(files) {
    const list = [...files].filter(Boolean);
    if (!list.length) return;
    uploading.value = true;
    router.post(`/work/${props.ticket.id}/files`, { files: list }, { forceFormData: true, preserveScroll: true, onFinish: () => (uploading.value = false) });
}
function onPaste(event) {
    const files = [...(event.clipboardData?.items ?? [])].filter((item) => item.kind === 'file').map((item) => item.getAsFile());
    if (files.length) {
        event.preventDefault();
        upload(files);
    }
}
function remove(file) {
    router.delete(`/work/${props.ticket.id}/files/${encodeURIComponent(file.name)}`, { preserveScroll: true });
}
defineExpose({ onPaste });

// The Jira ticket: drafted by the agent, edited by you, then made or linked.
const draft = computed(() => props.ticket.ticket_draft);
const jira = useForm({ title: '', type: 'Improvement', description: '', key: '' });
watch(draft, (value) => {
    if (value) {
        jira.title = value.title ?? '';
        jira.type = value.type ?? 'Improvement';
        jira.description = value.description ?? '';
    }
}, { immediate: true });
const copied = ref(null);
async function copy(what) {
    await copyText(jira[what]);
    copied.value = what;
    setTimeout(() => (copied.value = null), 2000);
}
function makeDraft() {
    router.post(`/tickets/${props.ticket.id}/draft-ticket`, {}, { preserveScroll: true });
}
function link() {
    jira.transform((data) => ({ key: data.key })).post(`/tickets/${props.ticket.id}/link`);
}
function createInJira() {
    jira.transform((data) => ({ create: true, title: data.title, type: data.type, description: data.description })).post(`/tickets/${props.ticket.id}/link`);
}
const field = 'mt-1 w-full rounded-md border border-line bg-page px-3 py-2 text-sm';
const isImage = (file) => (file.mime ?? '').startsWith('image/');
</script>

<template>
    <div class="space-y-6 text-sm">
        <form class="space-y-3" @submit.prevent="saveAbout">
            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_10rem]">
                <label>Working title <input v-model="about.title" required maxlength="200" :class="field" /></label>
                <label>Kind
                    <select v-model="about.type" :class="field"><option v-for="type in types" :key="type" :value="type">{{ type }}</option></select>
                </label>
            </div>
            <label class="block">What you are after <textarea v-model="about.description" rows="4" :class="field"></textarea></label>
            <button type="submit" class="btn" :disabled="about.processing || !about.isDirty">Save</button>
        </form>

        <div>
            <p class="font-medium">Screenshots and files <span class="font-normal text-muted">(the agent sees them; they go to Jira with the ticket. Paste one with Ctrl+V.)</span></p>
            <ul v-if="ticket.files.length" class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <li v-for="file in ticket.files" :key="file.name" class="rounded-md border border-line p-1.5">
                    <a :href="file.url" target="_blank" rel="noopener">
                        <img v-if="isImage(file)" :src="file.url" :alt="file.name" class="aspect-video w-full rounded object-cover" />
                        <span v-else class="flex aspect-video items-center justify-center rounded bg-sunken font-mono text-xs">{{ file.name.split('.').pop() }}</span>
                    </a>
                    <p class="mt-1 flex items-center gap-1 text-xs"><span class="min-w-0 flex-1 truncate font-mono">{{ file.name }}</span>
                        <button type="button" class="text-muted hover:text-signal" :aria-label="`Remove ${file.name}`" @click="remove(file)">✕</button>
                    </p>
                </li>
            </ul>
            <label class="btn mt-2 inline-flex cursor-pointer">
                {{ uploading ? 'Adding…' : 'Add files' }}
                <input type="file" multiple accept="image/*,.pdf,.txt,.log,.csv,.json" class="sr-only" @change="upload($event.target.files)" />
            </label>
            <p v-if="page.props.errors?.files || page.props.errors?.['files.0']" class="mt-1 text-signal">{{ page.props.errors.files || page.props.errors['files.0'] }}</p>
        </div>

        <div class="border-t border-line pt-4">
            <p class="font-medium">Make it a ticket</p>
            <p class="mt-1 text-muted">
                When it is ready: the agent writes the Jira ticket from the chat and the code built, with your screenshots in it.
                The work, its chats and its branches then move onto that ticket, and the branches take its key.
            </p>
            <button type="button" class="btn mt-2" :class="{ 'btn-signal': !draft }" :disabled="busy" @click="makeDraft">{{ draft ? 'Draft it again' : 'Draft the ticket' }}</button>

            <div v-if="draft" class="mt-4 space-y-3">
                <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_10rem]">
                    <label>Title <input v-model="jira.title" :class="field" /></label>
                    <label>Type
                        <select v-model="jira.type" :class="field"><option v-for="type in types" :key="type" :value="type">{{ type }}</option></select>
                    </label>
                </div>
                <label class="block">Description <span class="text-muted">(Jira markup; !name|thumbnail! shows a screenshot once it is attached)</span>
                    <textarea v-model="jira.description" rows="14" :class="field" class="font-mono text-xs"></textarea>
                </label>
                <p class="flex flex-wrap gap-2">
                    <button type="button" class="btn" @click="copy('title')">{{ copied === 'title' ? 'Copied' : 'Copy the title' }}</button>
                    <button type="button" class="btn" @click="copy('description')">{{ copied === 'description' ? 'Copied' : 'Copy the description' }}</button>
                    <button v-if="jiraWrite" type="button" class="btn btn-signal" :disabled="jira.processing || busy" @click="createInJira">Create it in Jira and link</button>
                </p>
                <p v-if="!jiraWrite" class="text-muted">
                    Writing to Jira is off here (JIRA_WRITE=dummy): make the ticket in Jira with these, attach the screenshots
                    there, and link it below by its key.
                </p>
            </div>

            <form class="mt-4 flex flex-wrap items-end gap-2" @submit.prevent="link">
                <label>The Jira ticket it became
                    <input v-model="jira.key" placeholder="DEVBESUP-3300" class="mt-1 block w-44 rounded-md border border-line bg-page px-2 py-1.5 font-mono text-sm uppercase placeholder:normal-case" />
                </label>
                <button type="submit" class="btn" :disabled="jira.processing || busy || !jira.key.trim()">Link</button>
            </form>
            <p v-if="jira.errors.key || page.props.errors?.key" class="mt-1 text-signal">{{ jira.errors.key || page.props.errors.key }}</p>
        </div>
    </div>
</template>
