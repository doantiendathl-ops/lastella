<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    links: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <nav v-if="links.length > 3" class="flex flex-wrap items-center gap-1">
        <template v-for="(link, index) in links" :key="index">
            <span
                v-if="!link.url"
                class="inline-flex h-9 min-w-9 items-center justify-center border border-gray-200 px-3 text-sm text-gray-400"
                v-html="link.label"
            />
            <Link
                v-else
                :href="link.url"
                preserve-scroll
                class="inline-flex h-9 min-w-9 items-center justify-center border px-3 text-sm transition"
                :class="link.active ? 'border-pine bg-pine text-white' : 'border-gray-200 bg-white text-ink hover:border-pine'"
                v-html="link.label"
            />
        </template>
    </nav>
</template>
