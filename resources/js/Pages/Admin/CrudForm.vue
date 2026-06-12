<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps({
    title: { type: String, required: true },
    action: { type: String, required: true },
    method: { type: String, default: 'post' },
    cancelUrl: { type: String, required: true },
    fields: { type: Array, required: true },
    values: { type: Object, default: () => ({}) },
});

const jsonError = ref('');

const initial = {};

props.fields.forEach((field) => {
    const value = props.values[field.name];

    if (field.type === 'multiselect') {
        initial[field.name] = Array.isArray(value) ? value : [];
        return;
    }

    if (field.type === 'checkbox') {
        initial[field.name] = Boolean(value);
        return;
    }

    if (field.type === 'json') {
        initial[field.name] = value ? JSON.stringify(value, null, 2) : '';
        return;
    }

    initial[field.name] = value ?? '';
});

const form = useForm(initial);
const jsonFields = computed(() => props.fields.filter((field) => field.type === 'json').map((field) => field.name));

const submit = () => {
    jsonError.value = '';
    const payload = {};

    for (const field of props.fields) {
        payload[field.name] = form[field.name];
    }

    for (const field of jsonFields.value) {
        if (!payload[field]) {
            payload[field] = null;
            continue;
        }

        try {
            payload[field] = JSON.parse(payload[field]);
        } catch (error) {
            jsonError.value = `Invalid JSON in ${field}.`;
            return;
        }
    }

    form.transform(() => payload);

    form[props.method](props.action, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="title" />

    <AppLayout>
        <template #header>
            <div class="flex min-w-0 items-center justify-between gap-4">
                <h1 class="truncate text-lg font-semibold">{{ title }}</h1>
                <Link :href="cancelUrl" class="inline-flex items-center gap-2 border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-steel hover:text-ink">
                    <ArrowLeft class="h-4 w-4" />
                    Back
                </Link>
            </div>
        </template>

        <form class="max-w-4xl border border-gray-200 bg-white p-5 shadow-sm" @submit.prevent="submit">
            <div class="grid gap-5 md:grid-cols-2">
                <div v-for="field in fields" :key="field.name" :class="field.type === 'textarea' || field.type === 'json' || field.type === 'multiselect' ? 'md:col-span-2' : ''">
                    <label class="block text-sm font-medium text-ink" :for="field.name">
                        {{ field.label }}
                        <span v-if="field.required" class="text-coral">*</span>
                    </label>

                    <textarea
                        v-if="field.type === 'textarea' || field.type === 'json'"
                        :id="field.name"
                        v-model="form[field.name]"
                        rows="5"
                        class="mt-2 block w-full border border-gray-300 px-3 py-2 text-sm font-mono focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine"
                    />

                    <select
                        v-else-if="field.type === 'select'"
                        :id="field.name"
                        v-model="form[field.name]"
                        class="mt-2 block w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine"
                    >
                        <option value="">Select</option>
                        <option v-for="option in field.options" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>

                    <select
                        v-else-if="field.type === 'multiselect'"
                        :id="field.name"
                        v-model="form[field.name]"
                        multiple
                        class="mt-2 block min-h-36 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine"
                    >
                        <option v-for="option in field.options" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>

                    <label v-else-if="field.type === 'checkbox'" class="mt-3 inline-flex items-center gap-2 text-sm text-steel">
                        <input v-model="form[field.name]" type="checkbox" class="h-4 w-4 border-gray-300 text-pine focus:ring-pine">
                        Enabled
                    </label>

                    <input
                        v-else
                        :id="field.name"
                        v-model="form[field.name]"
                        :type="field.type"
                        class="mt-2 block w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine"
                    >

                    <p v-if="form.errors[field.name]" class="mt-1 text-sm text-coral">{{ form.errors[field.name] }}</p>
                </div>
            </div>

            <p v-if="jsonError" class="mt-4 text-sm text-coral">{{ jsonError }}</p>

            <div class="mt-6 flex justify-end gap-3 border-t border-gray-200 pt-5">
                <Link :href="cancelUrl" class="inline-flex items-center border border-gray-300 px-4 py-2 text-sm font-semibold text-steel hover:text-ink">
                    Cancel
                </Link>
                <button
                    type="submit"
                    class="inline-flex items-center gap-2 bg-pine px-4 py-2 text-sm font-semibold text-white transition hover:bg-ink disabled:opacity-60"
                    :disabled="form.processing"
                >
                    <Save class="h-4 w-4" />
                    Save
                </button>
            </div>
        </form>
    </AppLayout>
</template>
