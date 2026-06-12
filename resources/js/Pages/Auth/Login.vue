<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { LogIn } from 'lucide-vue-next';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Login" />

    <main class="grid min-h-screen place-items-center bg-gray-50 px-4">
        <form class="w-full max-w-sm border border-gray-200 bg-white p-6 shadow-sm" @submit.prevent="submit">
            <div class="mb-6">
                <h1 class="text-xl font-semibold text-ink">Lastella PMS</h1>
                <p class="mt-1 text-sm text-steel">Sign in to continue</p>
            </div>

            <label class="block text-sm font-medium text-ink" for="email">Email</label>
            <input
                id="email"
                v-model="form.email"
                type="email"
                autocomplete="username"
                class="mt-2 block w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine"
                autofocus
            >
            <p v-if="form.errors.email" class="mt-1 text-sm text-coral">{{ form.errors.email }}</p>

            <label class="mt-4 block text-sm font-medium text-ink" for="password">Password</label>
            <input
                id="password"
                v-model="form.password"
                type="password"
                autocomplete="current-password"
                class="mt-2 block w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine"
            >
            <p v-if="form.errors.password" class="mt-1 text-sm text-coral">{{ form.errors.password }}</p>

            <label class="mt-4 flex items-center gap-2 text-sm text-steel">
                <input v-model="form.remember" type="checkbox" class="h-4 w-4 border-gray-300 text-pine focus:ring-pine">
                Remember me
            </label>

            <button
                type="submit"
                class="mt-6 inline-flex w-full items-center justify-center gap-2 bg-pine px-4 py-2 text-sm font-semibold text-white transition hover:bg-ink disabled:opacity-60"
                :disabled="form.processing"
            >
                <LogIn class="h-4 w-4" />
                Sign In
            </button>
        </form>
    </main>
</template>
