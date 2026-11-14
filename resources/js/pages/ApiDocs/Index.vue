<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';

// Every API the tool knows, with its docs: what you and the agent can call.
const props = defineProps({ apis: { type: Array, required: true } });
const bySystem = computed(() => Object.entries(props.apis.reduce((groups, api) => ({ ...groups, [api.system]: [...(groups[api.system] ?? []), api] }), {})));
const access = { read: 'read only', query: 'read and query', write: 'changes after approval' };
</script>

<template>
    <Head title="APIs" />
    <p class="eyebrow">APIs</p>
    <h1 class="display mt-2 text-3xl">What the systems answer to</h1>
    <p class="mt-2 max-w-2xl text-muted">Each API's endpoints, read from its OpenAPI spec or written from its code. The agent looks them up here too, instead of guessing paths.</p>

    <p v-if="!apis.length" class="mt-8 text-muted">No APIs yet: add them to a system on the Systems page.</p>
    <section v-for="[system, list] in bySystem" :key="system" class="mt-8">
        <h2 class="font-mono text-lg">{{ system }}</h2>
        <ul class="card mt-2 divide-y divide-line">
            <li v-for="api in list" :key="api.id">
                <Link :href="`/apis/${api.id}`" class="flex flex-wrap items-baseline gap-x-3 gap-y-1 p-3 hover:bg-sunken">
                    <span class="font-mono">{{ api.name }}</span>
                    <span class="font-mono text-xs break-all text-muted">{{ api.base_url }}</span>
                    <span class="ml-auto text-sm" :class="api.endpoints ? 'text-ink' : 'text-muted'">
                        {{ api.endpoints ? `${api.endpoints} endpoints` : ['queued', 'writing'].includes(api.state) ? 'being written…' : 'no docs yet' }}
                    </span>
                    <span class="w-full text-xs text-muted">{{ access[api.access] ?? api.access }}<template v-if="api.source"> · {{ api.source }}</template></span>
                </Link>
            </li>
        </ul>
    </section>
</template>
