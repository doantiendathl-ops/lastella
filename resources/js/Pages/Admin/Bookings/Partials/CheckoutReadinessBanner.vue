<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    paymentSummary: {
        balance_due: number
    }
    bookingStatus: string
    hasActiveStays: boolean
}>()

const formatCurrency = (value: number) =>
    `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(Number(value) || 0)} đ`

const isTerminal = computed(() =>
    ['CHECKED_OUT', 'CANCELLED', 'NO_SHOW'].includes(props.bookingStatus)
)
</script>

<template>
    <div
        v-if="isTerminal"
        class="border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-steel"
    >
        Booking đã kết thúc.
    </div>
    <div
        v-else-if="paymentSummary.balance_due > 0"
        class="border border-coral/40 bg-coral/5 px-4 py-3 text-sm font-semibold text-coral"
    >
        Còn số dư: {{ formatCurrency(paymentSummary.balance_due) }} — Vui lòng thanh toán trước khi trả phòng.
    </div>
    <div
        v-else-if="hasActiveStays"
        class="border border-pine/40 bg-pine/5 px-4 py-3 text-sm font-semibold text-pine"
    >
        Đã thanh toán đủ — Sẵn sàng trả phòng.
    </div>
</template>
