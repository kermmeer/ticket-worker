<script setup>
import { ref } from 'vue';
import { copyText } from '../copy.js';

// A command or a piece of code, to copy and run as it is.
const props = defineProps({
    code: { type: String, required: true },
    language: { type: String, default: '' },
});

const copied = ref(false);
async function copy() {
    await copyText(props.code);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
}
</script>

<template>
    <div class="overflow-hidden rounded-md border border-line bg-sunken">
        <div class="flex items-center justify-between gap-2 border-b border-line px-3 py-1">
            <span class="font-mono text-[0.7rem] tracking-wide text-muted uppercase">{{ language || 'code' }}</span>
            <button type="button" class="text-xs text-muted hover:text-ink" @click="copy">{{ copied ? 'Copied' : 'Copy' }}</button>
        </div>
        <pre class="overflow-x-auto px-3 py-2 font-mono text-xs leading-relaxed"><code>{{ code }}</code></pre>
    </div>
</template>
