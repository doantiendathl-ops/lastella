<script setup lang="ts">
defineProps<{
    status: string
}>()
</script>

<template>
    <span
        class="inline-flex items-center px-2 py-0.5 text-xs font-semibold uppercase tracking-wide"
        :class="{
            'bg-pine/10 text-pine border border-pine/30': status === 'OPEN',
            'bg-gray-100 text-gray-500 border border-gray-200': status === 'CLOSED',
            'bg-coral/10 text-coral border border-coral/30': status === 'VOIDED',
        }"
    >
        {{ status === 'OPEN' ? 'Đang mở' : status === 'CLOSED' ? 'Đã đóng' : 'Đã huỷ' }}
    </span>
</template>
