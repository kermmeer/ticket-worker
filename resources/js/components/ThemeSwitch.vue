<script setup>
import { setTheme, theme, themes } from '../theme.js';
</script>

<template>
    <!-- All three choices are written out: there is no hover on a phone to explain an icon. -->
    <div role="group" aria-label="Theme" class="inline-flex rounded-md border border-line bg-surface p-0.5 text-xs">
        <button
            v-for="option in themes"
            :key="option.value"
            type="button"
            class="rounded px-2.5 py-1 font-medium transition-colors"
            :class="theme === option.value ? 'bg-ink text-page' : 'text-muted hover:text-ink'"
            :aria-pressed="theme === option.value"
            @click="setTheme(option.value)"
        >
            {{ option.label }}
        </button>
    </div>
</template>
