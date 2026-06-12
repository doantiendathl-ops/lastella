<script setup>
import Pagination from '@/Components/Pagination.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowUpDown, Pencil, Plus, Search, Trash2, X } from 'lucide-vue-next';
import { reactive } from 'vue';

const props = defineProps({
    title: { type: String, required: true },
    baseUrl: { type: String, required: true },
    items: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    columns: { type: Array, required: true },
    filterFields: { type: Array, default: () => [] },
    canCreate: { type: Boolean, default: true },
    readOnly: { type: Boolean, default: false },
});

const query = reactive({
    search: props.filters.search ?? '',
    sort: props.filters.sort ?? '',
    direction: props.filters.direction ?? 'asc',
    per_page: props.filters.per_page ?? 15,
});

props.filterFields.forEach((field) => {
    query[field.name] = props.filters[field.name] ?? '';
});

const runSearch = () => {
    router.get(props.baseUrl, cleanupQuery(query), {
        preserveState: true,
        replace: true,
    });
};

const resetFilters = () => {
    Object.keys(query).forEach((key) => {
        query[key] = key === 'per_page' ? 15 : '';
    });
    query.direction = 'asc';
    runSearch();
};

const sortBy = (column) => {
    if (!column.sortable) {
        return;
    }

    if (query.sort === column.key) {
        query.direction = query.direction === 'asc' ? 'desc' : 'asc';
    } else {
        query.sort = column.key;
        query.direction = 'asc';
    }

    runSearch();
};

const destroy = (id) => {
    if (!window.confirm('Delete this record?')) {
        return;
    }

    router.delete(`${props.baseUrl}/${id}`);
};

const cleanupQuery = (params) => Object.fromEntries(
    Object.entries(params).filter(([, value]) => value !== '' && value !== null && value !== undefined),
);
</script>

<template>
    <Head :title="title" />

    <AppLayout>
        <template #header>
            <div class="flex min-w-0 items-center justify-between gap-4">
                <h1 class="truncate text-lg font-semibold">{{ title }}</h1>
                <Link
                    v-if="canCreate && !readOnly"
                    :href="`${baseUrl}/create`"
                    class="inline-flex items-center gap-2 bg-pine px-3 py-2 text-sm font-semibold text-white transition hover:bg-ink"
                >
                    <Plus class="h-4 w-4" />
                    New
                </Link>
            </div>
        </template>

        <section class="border border-gray-200 bg-white shadow-sm">
            <form class="flex flex-col gap-3 border-b border-gray-200 p-4 xl:flex-row xl:items-end" @submit.prevent="runSearch">
                <div class="min-w-0 flex-1">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-steel" for="search">Search</label>
                    <div class="mt-1 flex">
                        <input
                            id="search"
                            v-model="query.search"
                            type="search"
                            class="min-w-0 flex-1 border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine"
                        >
                        <button class="inline-flex w-10 items-center justify-center bg-pine text-white" type="submit" title="Search">
                            <Search class="h-4 w-4" />
                        </button>
                    </div>
                </div>

                <div v-for="field in filterFields" :key="field.name" class="min-w-40">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-steel" :for="field.name">{{ field.label }}</label>
                    <select
                        v-if="field.type === 'select'"
                        :id="field.name"
                        v-model="query[field.name]"
                        class="mt-1 block w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine"
                    >
                        <option value="">All</option>
                        <option v-for="option in field.options" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                    <input
                        v-else
                        :id="field.name"
                        v-model="query[field.name]"
                        type="text"
                        class="mt-1 block w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine"
                    >
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="inline-flex h-10 items-center gap-2 bg-pine px-3 text-sm font-semibold text-white">
                        <Search class="h-4 w-4" />
                        Apply
                    </button>
                    <button type="button" class="inline-flex h-10 items-center gap-2 border border-gray-300 px-3 text-sm text-steel hover:text-ink" @click="resetFilters">
                        <X class="h-4 w-4" />
                        Reset
                    </button>
                </div>
            </form>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-steel">
                        <tr>
                            <th v-for="column in columns" :key="column.key" class="whitespace-nowrap px-4 py-3 font-semibold">
                                <button
                                    v-if="column.sortable"
                                    type="button"
                                    class="inline-flex items-center gap-1 font-semibold"
                                    @click="sortBy(column)"
                                >
                                    {{ column.label }}
                                    <ArrowUpDown class="h-3.5 w-3.5" />
                                </button>
                                <span v-else>{{ column.label }}</span>
                            </th>
                            <th v-if="!readOnly" class="w-24 px-4 py-3 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <tr v-for="row in items.data" :key="row.id" class="hover:bg-gray-50">
                            <td v-for="column in columns" :key="column.key" class="whitespace-nowrap px-4 py-3 text-ink">
                                {{ row[column.key] ?? '' }}
                            </td>
                            <td v-if="!readOnly" class="whitespace-nowrap px-4 py-3 text-right">
                                <Link
                                    :href="`${baseUrl}/${row.id}/edit`"
                                    class="mr-2 inline-flex h-8 w-8 items-center justify-center border border-gray-200 text-steel transition hover:border-pine hover:text-pine"
                                    title="Edit"
                                >
                                    <Pencil class="h-4 w-4" />
                                </Link>
                                <button
                                    type="button"
                                    class="inline-flex h-8 w-8 items-center justify-center border border-gray-200 text-steel transition hover:border-coral hover:text-coral"
                                    title="Delete"
                                    @click="destroy(row.id)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                        <tr v-if="items.data.length === 0">
                            <td :colspan="columns.length + (readOnly ? 0 : 1)" class="px-4 py-12 text-center text-sm text-steel">
                                No records found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col gap-3 border-t border-gray-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-steel">
                    Showing {{ items.from ?? 0 }} to {{ items.to ?? 0 }} of {{ items.total ?? 0 }}
                </p>
                <Pagination :links="items.links" />
            </div>
        </section>
    </AppLayout>
</template>
