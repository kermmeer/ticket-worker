<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import ThemeSwitch from '../components/ThemeSwitch.vue';

// The optional gate (APP_PASSWORD): no header, no navigation, nothing behind it shown.
defineOptions({ layout: null });

const form = useForm({ password: '' });

function submit() {
    form.post('/login', { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head title="Sign in" />
    <div class="flex min-h-dvh flex-col items-center justify-center px-4">
        <div class="absolute top-4 right-4"><ThemeSwitch /></div>
        <form class="card w-full max-w-sm p-6" @submit.prevent="submit">
            <div class="flex items-center gap-2">
                <img src="/favicon.svg" alt="" class="size-7" />
                <h1 class="display text-2xl leading-none">Ticket Worker</h1>
            </div>
            <label for="password" class="mt-6 block font-medium">Password</label>
            <input
                id="password"
                v-model="form.password"
                type="password"
                autocomplete="current-password"
                autofocus
                required
                class="mt-2 w-full rounded-md border border-line bg-page px-3 py-2"
                :aria-invalid="form.errors.password ? 'true' : undefined"
                aria-describedby="password-error"
            />
            <p v-if="form.errors.password" id="password-error" role="alert" class="mt-2 text-sm text-signal">{{ form.errors.password }}</p>
            <button type="submit" class="btn btn-signal mt-5 w-full justify-center" :disabled="form.processing || !form.password">Sign in</button>
        </form>
    </div>
</template>
