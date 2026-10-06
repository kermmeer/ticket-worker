<script setup>
import { computed } from 'vue';
import { jiraParts } from '../jiraText.js';

// A ticket's description or comment with its links and attachments clickable. Every part
// is rendered as text or a link, never as HTML.
const props = defineProps({
    text: { type: String, default: '' },
    attachments: { type: Array, default: () => [] },
    // Where an attachment opens: a function of the attachment.
    fileUrl: { type: Function, required: true },
});

const byName = computed(() => new Map(props.attachments.map((file) => [file.filename, file])));
const parts = computed(() => jiraParts(props.text, (name) => byName.value.get(name) ?? null));
</script>

<template>
    <div class="break-words whitespace-pre-wrap"><template v-for="(part, index) in parts" :key="index"><template v-if="part.type === 'text'">{{ part.text }}</template><a
        v-else-if="part.type === 'link'"
        :href="part.href"
        target="_blank"
        rel="noopener noreferrer"
        class="text-signal underline decoration-signal/40 underline-offset-2 hover:decoration-signal"
    >{{ part.text }}</a><a
        v-else
        :href="fileUrl(part.file)"
        target="_blank"
        rel="noopener"
        class="rounded-sm bg-sunken px-1 font-mono text-[0.85em] underline decoration-muted/50 underline-offset-2 hover:decoration-ink"
    >{{ part.text }}</a></template></div>
</template>
