<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import ThemeSwitch from '../components/ThemeSwitch.vue';

// Signing in with your own account; or, on a fresh instance, making the first one.
defineOptions({ layout: null });

const props = defineProps({
    firstAccount: { type: Boolean, default: false },
    viaHeader: { type: Boolean, default: false },
});

const signIn = useForm({ username: '', password: '' });
const first = useForm({ username: '', name: '', password: '', password_confirmation: '' });

function submit() {
    props.firstAccount ? first.post('/first-account') : signIn.post('/sign-in', { onFinish: () => signIn.reset('password') });
}
</script>

<template>
    <Head :title="firstAccount ? 'The first account' : 'Sign in'" />
    <div class="flex min-h-dvh flex-col items-center justify-center px-4 py-10">
        <div class="absolute top-4 right-4"><ThemeSwitch /></div>
        <form class="card w-full max-w-sm space-y-4 p-6" @submit.prevent="submit">
            <div class="flex items-center gap-2">
                <img src="/favicon.svg" alt="" class="size-7" />
                <h1 class="display text-2xl leading-none">Ticket Worker</h1>
            </div>

            <template v-if="firstAccount">
                <p class="text-sm text-muted">
                    No accounts yet. This one is the first: it runs the place (adds the people you work with) and keeps what was
                    done here before there were accounts.
                </p>
                <label class="block text-sm"><span class="font-medium">Your name</span>
                    <input v-model="first.name" required autocomplete="name" class="mt-1 w-full rounded-md border border-line bg-page px-3 py-2" />
                </label>
                <label class="block text-sm"><span class="font-medium">Username</span>
                    <input v-model="first.username" required autocomplete="username" class="mt-1 w-full rounded-md border border-line bg-page px-3 py-2" />
                    <span v-if="first.errors.username" class="mt-1 block text-signal">{{ first.errors.username }}</span>
                </label>
                <label class="block text-sm"><span class="font-medium">Password</span> <span class="text-muted">(10 characters or more)</span>
                    <input v-model="first.password" type="password" required autocomplete="new-password" class="mt-1 w-full rounded-md border border-line bg-page px-3 py-2" />
                    <span v-if="first.errors.password" class="mt-1 block text-signal">{{ first.errors.password }}</span>
                </label>
                <label class="block text-sm"><span class="font-medium">The password again</span>
                    <input v-model="first.password_confirmation" type="password" required autocomplete="new-password" class="mt-1 w-full rounded-md border border-line bg-page px-3 py-2" />
                </label>
                <button type="submit" class="btn btn-signal w-full justify-center" :disabled="first.processing">Make the account</button>
            </template>

            <template v-else>
                <label class="block text-sm"><span class="font-medium">Username</span>
                    <input v-model="signIn.username" required autofocus autocomplete="username" class="mt-1 w-full rounded-md border border-line bg-page px-3 py-2" />
                </label>
                <label class="block text-sm"><span class="font-medium">Password</span>
                    <input v-model="signIn.password" type="password" required autocomplete="current-password" class="mt-1 w-full rounded-md border border-line bg-page px-3 py-2" />
                </label>
                <p v-if="signIn.errors.username" role="alert" class="text-sm text-signal">{{ signIn.errors.username }}</p>
                <button type="submit" class="btn btn-signal w-full justify-center" :disabled="signIn.processing">Sign in</button>
                <p class="text-xs text-muted">No account? Whoever runs this instance adds you and sends you a link.</p>
            </template>
        </form>
    </div>
</template>
