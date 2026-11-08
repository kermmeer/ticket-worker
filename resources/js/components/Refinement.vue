<script setup>
import { ref } from 'vue';
import { copyText } from '../copy.js';

// A development ticket refined: what is asked, the questions, when it is done, where, how big.
const props = defineProps({ refinement: { type: Object, required: true } });

// As Jira wiki markup, to paste into the ticket as a comment or into its description.
function asJira(r) {
    const list = (items, mark) => (items ?? []).map((item) => `${mark} ${item}`).join('\n');
    return [
        `h3. Summary\n${r.summary}`,
        r.questions?.length ? `h3. Questions\n${list(r.questions, '#')}` : null,
        `h3. Acceptance criteria\n${list(r.acceptance_criteria, '*')}`,
        r.code?.length ? `h3. Where in the code\n${list(r.code, '*')}` : null,
        `h3. Size\n${r.size}: ${r.size_reason}`,
        r.risks?.length ? `h3. Risks\n${list(r.risks, '*')}` : null,
    ].filter(Boolean).join('\n\n');
}

const copied = ref(false);
async function copy() {
    await copyText(asJira(props.refinement));
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}
const sizes = { S: 'S: hours', M: 'M: a day or two', L: 'L: more, or split it' };
</script>

<template>
    <div class="space-y-3 text-sm">
        <p>{{ refinement.summary }}</p>
        <p><span class="font-medium">Size</span> <span class="ml-1 font-mono">{{ sizes[refinement.size] ?? refinement.size }}</span><span class="text-muted">. {{ refinement.size_reason }}</span></p>
        <div v-if="refinement.questions?.length">
            <p class="font-medium">Questions for the product owner</p>
            <ol class="mt-1 list-decimal space-y-0.5 pl-5"><li v-for="(item, i) in refinement.questions" :key="i">{{ item }}</li></ol>
        </div>
        <div v-if="refinement.acceptance_criteria?.length">
            <p class="font-medium">Acceptance criteria</p>
            <ul class="mt-1 list-disc space-y-0.5 pl-5"><li v-for="(item, i) in refinement.acceptance_criteria" :key="i">{{ item }}</li></ul>
        </div>
        <div v-if="refinement.code?.length">
            <p class="font-medium">Where in the code</p>
            <ul class="mt-1 space-y-0.5 font-mono text-xs break-all"><li v-for="(item, i) in refinement.code" :key="i">{{ item }}</li></ul>
        </div>
        <div v-if="refinement.risks?.length">
            <p class="font-medium">Risks</p>
            <ul class="mt-1 list-disc space-y-0.5 pl-5"><li v-for="(item, i) in refinement.risks" :key="i">{{ item }}</li></ul>
        </div>
        <button type="button" class="btn" @click="copy">{{ copied ? 'Copied' : 'Copy for Jira' }}</button>
    </div>
</template>
