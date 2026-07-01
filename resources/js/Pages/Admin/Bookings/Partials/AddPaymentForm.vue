<script setup lang="ts">
import { labelFor } from '@/Support/vietnameseLabels';
import { useForm } from '@inertiajs/vue3';
import { Banknote } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    bookingId: number
    paymentTypes: { value: string; label: string }[]
    paymentMethods: { value: string; label: string }[]
    paymentSummary: {
        paid_total: number
        total_charges: number
    }
}>()

const nowLocal = () => {
    const date = new Date()
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset())
    return date.toISOString().slice(0, 16)
}

const form = useForm({
    payment_type: 'DEPOSIT',
    amount: '' as string | number,
    payment_method: 'CASH',
    payment_at: nowLocal(),
    note: '',
})

const isRefund = computed(() => form.payment_type === 'REFUND')
const isAdjustment = computed(() => form.payment_type === 'ADJUSTMENT')

// ADR-51: reads directly from paymentSummary prop — never recomputed from payment list
const refundableMax = computed(() =>
    Math.max(0, Number(props.paymentSummary.paid_total) - Number(props.paymentSummary.total_charges))
)

const formatCurrency = (value: number) =>
    `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(Number(value) || 0)} đ`

const submit = () => {
    form.post(`/admin/bookings/${props.bookingId}/payments`, {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults({
                payment_type: 'DEPOSIT',
                amount: '',
                payment_method: 'CASH',
                payment_at: nowLocal(),
                note: '',
            }).reset()
        },
    })
}
</script>

<template>
    <form class="grid gap-3 border border-gray-100 p-4 md:grid-cols-5" @submit.prevent="submit">
        <div>
            <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Loại thanh toán</label>
            <select v-model="form.payment_type" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                <option v-for="type in paymentTypes" :key="type.value" :value="type.value">{{ labelFor('paymentType', type.value) }}</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Số tiền</label>
            <input v-model="form.amount" type="number" min="0.01" step="0.01" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
            <div v-if="isRefund && refundableMax >= 0" class="mt-1 text-xs text-steel">
                Tối đa có thể hoàn: {{ formatCurrency(refundableMax) }}
            </div>
        </div>
        <div v-if="!isAdjustment">
            <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Phương thức</label>
            <select v-model="form.payment_method" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                <option v-for="method in paymentMethods" :key="method.value" :value="method.value">{{ labelFor('paymentMethod', method.value) }}</option>
            </select>
        </div>
        <div :class="isAdjustment ? 'md:col-start-3' : ''">
            <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Thời gian thanh toán</label>
            <input v-model="form.payment_at" type="datetime-local" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Ghi chú</label>
            <input v-model="form.note" type="text" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
        </div>
        <button type="submit" class="inline-flex items-center justify-center gap-2 bg-pine px-3 py-2 text-sm font-semibold text-white disabled:bg-gray-300" :disabled="form.processing">
            <Banknote class="h-4 w-4" />
            Thêm thanh toán
        </button>
        <p v-if="Object.keys(form.errors).length" class="md:col-span-5 text-sm text-coral">{{ Object.values(form.errors)[0] }}</p>
    </form>
</template>
