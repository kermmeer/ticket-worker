<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

// One API's docs: every endpoint, searchable, grouped by its first path segment.
const props = defineProps({
    api: { type: Object, required: true },
    endpoints: { type: Array, default: () => [] },
});
const page = usePage();
const search = ref('');
const shown = computed(() => {
    const words = search.value.toLowerCase().split(/\s+/).filter(Boolean);
    return props.endpoints.filter((e) => {
        const text = `${e.method} ${e.path} ${e.summary ?? ''} ${(e.params ?? []).map((p) => p.name).join(' ')}`.toLowerCase();
        return words.every((word) => text.includes(word));
    });
});
const groups = computed(() => Object.entries(shown.value.reduce((all, e) => {
    const key = e.path.split('/').filter(Boolean)[0] ?? '/';
    return { ...all, [key]: [...(all[key] ?? []), e] };
}, {})));
const tone = { GET: 'text-done', POST: 'text-working', PUT: 'text-waiting', PATCH: 'text-waiting', DELETE: 'text-signal' };
const writing = computed(() => ['queued', 'writing'].includes(props.api.state));
const mayWrite = computed(() => page.props.me?.is_editor);
function write() {
    router.post(`/systems/${props.api.system_id}/apis/${props.api.id}/docs`, {}, { preserveScroll: true });
}
// A GET without path parameters can be tried as it is, by editors: the same Try as on the Systems page.
function tryGet(e) {
    router.post(`/systems/${props.api.system_id}/apis/${props.api.id}/try`, { path: e.path }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`${api.system}/${api.name}`" />
    <p class="eyebrow"><Link href="/apis" class="hover:text-ink">APIs</Link> / {{ api.system }}</p>
    <h1 class="display mt-2 font-mono text-3xl">{{ api.name }}</h1>
    <p class="mt-1 font-mono text-sm break-all text-muted">{{ api.base_url }}</p>
    <p class="mt-2 text-sm text-muted">
        <template v-if="api.source">{{ endpoints.length }} endpoints, {{ api.source }}<template v-if="api.written_at">, {{ new Date(api.written_at).toLocaleDateString() }}</template>.</template>
        <template v-else>No docs yet.</template>
        <button v-if="mayWrite && !writing" type="button" class="ml-2 underline hover:text-ink" @click="write">{{ endpoints.length ? 'Write them again' : 'Write them' }}</button>
        <span v-if="writing" class="ml-2 text-working">being written…</span>
    </p>
    <p v-if="api.error" class="mt-2 text-sm text-signal">{{ api.error }}</p>
    <p v-if="api.notes" class="mt-2 max-w-3xl text-sm whitespace-pre-wrap">{{ api.notes }}</p>

    <template v-if="endpoints.length">
        <label class="mt-6 block max-w-md">
            <span class="sr-only">Search the endpoints</span>
            <input v-model="search" type="search" placeholder="Search: invoice lines, phone number, POST…" class="w-full rounded-md border border-line bg-surface px-3 py-2 text-sm" />
        </label>
        <p v-if="search" class="mt-1 text-xs text-muted">{{ shown.length }} of {{ endpoints.length }}</p>

        <section v-for="[group, list] in groups" :key="group" class="mt-6">
            <h2 class="eyebrow">/{{ group }}</h2>
            <ul class="card mt-2 divide-y divide-line">
                <li v-for="(e, index) in list" :key="`${e.method} ${e.path} ${index}`" class="p-3 text-sm">
                    <details>
                        <summary class="flex cursor-pointer flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span class="w-14 shrink-0 font-mono text-xs font-semibold" :class="tone[e.method]">{{ e.method }}</span>
                            <span class="font-mono break-all">{{ e.path }}</span>
                            <span class="text-muted">{{ e.summary }}</span>
                        </summary>
                        <div class="mt-3 space-y-2 pl-0 sm:pl-[4.25rem]">
                            <table v-if="e.params?.length" class="w-full text-xs">
                                <thead><tr class="text-left text-muted"><th class="pr-3 font-normal">Parameter</th><th class="pr-3 font-normal">In</th><th class="pr-3 font-normal">Type</th><th class="font-normal">About</th></tr></thead>
                                <tbody>
                                    <tr v-for="p in e.params" :key="p.name + p.in" class="align-top">
                                        <td class="pr-3 font-mono">{{ p.name }}<span v-if="p.required" class="text-signal"> *</span></td>
                                        <td class="pr-3 text-muted">{{ p.in }}</td>
                                        <td class="pr-3 font-mono text-muted">{{ p.type }}</td>
                                        <td>{{ p.description }}</td>
                                    </tr>
                                </tbody>
                            </table>
                            <p v-if="e.params?.some((p) => p.required)" class="text-xs text-muted"><span class="text-signal">*</span> required</p>
                            <p v-if="e.body"><span class="text-muted">Body:</span> <code class="font-mono text-xs break-all">{{ e.body }}</code></p>
                            <p v-if="e.returns"><span class="text-muted">Returns:</span> {{ e.returns }}</p>
                            <p v-if="e.source" class="font-mono text-xs text-muted">{{ e.source }}</p>
                            <button v-if="mayWrite && e.method === 'GET' && !e.path.includes('{')" type="button" class="btn px-2.5 py-1 text-xs" @click="tryGet(e)">Try it</button>
                        </div>
                    </details>
                </li>
            </ul>
        </section>
    </template>
</template>
