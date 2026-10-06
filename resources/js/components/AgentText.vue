<script setup>
import { computed } from 'vue';
import CodeBlock from './CodeBlock.vue';

// What the agent writes: prose as it is, and every fenced code block as a block to copy.
const props = defineProps({ text: { type: String, default: '' } });

const parts = computed(() => {
    const parts = [];
    const fence = /```([\w+-]*)[^\n]*\n([\s\S]*?)```/g;
    let last = 0;
    for (const match of props.text.matchAll(fence)) {
        const prose = props.text.slice(last, match.index).trim();
        if (prose) parts.push({ prose });
        parts.push({ code: match[2].replace(/\n$/, ''), language: match[1] });
        last = match.index + match[0].length;
    }
    const rest = props.text.slice(last).trim();
    if (rest) parts.push({ prose: rest });
    return parts;
});
</script>

<template>
    <div class="space-y-2">
        <template v-for="(part, index) in parts" :key="index">
            <CodeBlock v-if="part.code !== undefined" :code="part.code" :language="part.language" />
            <p v-else class="text-sm break-words whitespace-pre-wrap">{{ part.prose }}</p>
        </template>
    </div>
</template>
