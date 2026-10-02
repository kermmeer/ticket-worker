<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    title: { type: String, required: true },
    html: { type: String, required: true },
});
</script>

<template>
    <Head :title="title" />

    <!-- Rendered on the server from the repository's own Markdown, raw HTML escaped. -->
    <article class="doc max-w-3xl" v-html="html" />
</template>
