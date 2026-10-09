<script setup>
import { Head, useForm } from '@inertiajs/vue3';

// The link an admin sent: set your own password, and you are in.
defineOptions({ layout: null });

const props = defineProps({ name: String, username: String, action: String });
const form = useForm({ password: '', password_confirmation: '' });
</script>

<template>
    <Head title="Welcome" />
    <div class="flex min-h-dvh items-center justify-center px-4 py-10">
        <form class="card w-full max-w-sm space-y-4 p-6" @submit.prevent="form.post(action)">
            <div class="flex items-center gap-2">
                <img src="/favicon.svg" alt="" class="size-7" />
                <h1 class="display text-2xl leading-none">Welcome, {{ name }}</h1>
            </div>
            <p class="text-sm text-muted">Your username is <strong class="font-mono text-ink">{{ username }}</strong>. Choose a password to go with it.</p>
            <label class="block text-sm"><span class="font-medium">Password</span> <span class="text-muted">(10 characters or more)</span>
                <input v-model="form.password" type="password" required autocomplete="new-password" class="mt-1 w-full rounded-md border border-line bg-page px-3 py-2" />
                <span v-if="form.errors.password" class="mt-1 block text-signal">{{ form.errors.password }}</span>
            </label>
            <label class="block text-sm"><span class="font-medium">The password again</span>
                <input v-model="form.password_confirmation" type="password" required autocomplete="new-password" class="mt-1 w-full rounded-md border border-line bg-page px-3 py-2" />
            </label>
            <button type="submit" class="btn btn-signal w-full justify-center" :disabled="form.processing">Set it and go in</button>
        </form>
    </div>
</template>
