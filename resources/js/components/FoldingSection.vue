<script setup>
import { ref, useId } from 'vue';

const props = defineProps({
    title: { type: String, required: true },
    // Where the open or folded state is remembered, per browser; the same key on every ticket.
    remember: { type: String, required: true },
    open: { type: Boolean, default: true },
});

function saved() {
    try {
        const value = localStorage.getItem(`fold.${props.remember}`);
        return value === null ? props.open : value === 'open';
    } catch {
        return props.open;
    }
}

const isOpen = ref(saved());
const bodyId = useId();

function toggle() {
    isOpen.value = !isOpen.value;
    try {
        localStorage.setItem(`fold.${props.remember}`, isOpen.value ? 'open' : 'folded');
    } catch {
        // Storage refused: it still works for this visit.
    }
}
</script>

<template>
    <section class="card min-w-0">
        <div class="flex items-center gap-3 px-5 pt-4" :class="isOpen ? 'pb-0' : 'pb-4'">
            <button type="button" class="flex flex-1 items-center gap-2 text-left" :aria-expanded="isOpen" :aria-controls="bodyId" @click="toggle">
                <svg aria-hidden="true" viewBox="0 0 12 12" class="size-3 shrink-0 text-muted transition-transform" :class="{ '-rotate-90': !isOpen }" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 4.5 6 7.5l3-3" />
                </svg>
                <span class="eyebrow">{{ title }}</span>
                <span v-if="!isOpen" class="text-xs text-muted">folded</span>
            </button>
            <slot name="aside" />
        </div>
        <div v-show="isOpen" :id="bodyId" class="p-5 pt-3">
            <slot />
        </div>
    </section>
</template>
