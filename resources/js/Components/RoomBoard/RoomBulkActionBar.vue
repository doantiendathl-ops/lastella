<script setup>
defineProps({
    selectedCount: { type: Number, required: true },
});

defineEmits(['clear']);
</script>

<template>
    <div
        v-if="selectedCount > 0"
        class="sticky top-16 z-10 flex flex-wrap items-center gap-3 border border-pine bg-linen px-4 py-3 text-sm shadow-sm"
    >
        <span class="font-semibold text-ink">Đã chọn {{ selectedCount }} phòng</span>
        <div class="flex flex-wrap items-center gap-2">
            <slot />
        </div>
        <button type="button" class="ml-auto text-xs font-semibold text-steel underline hover:text-coral" @click="$emit('clear')">
            Bỏ chọn tất cả
        </button>
    </div>
</template>
