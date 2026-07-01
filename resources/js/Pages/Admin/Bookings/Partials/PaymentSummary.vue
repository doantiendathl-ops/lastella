<script setup lang="ts">
defineProps<{
    paymentSummary: {
        total_charges: number
        paid_total: number
        balance_due: number
        total_deposit: number
        total_payment: number
        total_refund: number
        total_adjustment: number
    }
}>()

const formatCurrency = (value: number) =>
    `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(Number(value) || 0)} đ`
</script>

<template>
    <div class="space-y-3">
        <div class="grid gap-3 md:grid-cols-3">
            <div class="border border-gray-100 p-4">
                <div class="text-xs uppercase tracking-wide text-steel">Tổng phí phát sinh</div>
                <div class="mt-2 text-lg font-semibold">{{ formatCurrency(paymentSummary.total_charges) }}</div>
            </div>
            <div class="border border-gray-100 p-4">
                <div class="text-xs uppercase tracking-wide text-steel">Đã thu</div>
                <div class="mt-2 text-lg font-semibold">{{ formatCurrency(paymentSummary.paid_total) }}</div>
            </div>
            <div class="border border-gray-100 p-4">
                <div class="text-xs uppercase tracking-wide text-steel">Còn lại</div>
                <div
                    class="mt-2 text-lg font-semibold"
                    :class="paymentSummary.balance_due > 0 ? 'text-coral' : 'text-pine'"
                >
                    {{ formatCurrency(paymentSummary.balance_due) }}
                </div>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-2 border border-gray-100 p-3 text-sm md:grid-cols-4">
            <div>
                <div class="text-xs text-steel">Đặt cọc</div>
                <div class="mt-1 font-semibold">{{ formatCurrency(paymentSummary.total_deposit) }}</div>
            </div>
            <div>
                <div class="text-xs text-steel">Thanh toán</div>
                <div class="mt-1 font-semibold">{{ formatCurrency(paymentSummary.total_payment) }}</div>
            </div>
            <div>
                <div class="text-xs text-steel">Hoàn tiền</div>
                <div class="mt-1 font-semibold" :class="paymentSummary.total_refund > 0 ? 'text-coral' : ''">
                    {{ paymentSummary.total_refund > 0 ? '−' : '' }}{{ formatCurrency(paymentSummary.total_refund) }}
                </div>
            </div>
            <div>
                <div class="text-xs text-steel">Điều chỉnh</div>
                <div class="mt-1 font-semibold">{{ formatCurrency(paymentSummary.total_adjustment) }}</div>
            </div>
        </div>
    </div>
</template>
