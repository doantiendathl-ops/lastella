<script setup lang="ts">
import { labelFor } from '@/Support/vietnameseLabels';
import { router } from '@inertiajs/vue3';
import { Trash2 } from 'lucide-vue-next';

const props = defineProps<{
    payments: {
        id: number
        payment_type: string
        amount: number
        payment_method: string | null
        payment_at: string
        confirmed_by: string | null
        note: string | null
        can_delete: boolean
    }[]
    bookingId: number
    canDeletePayment: boolean
}>()

const formatCurrency = (value: number) =>
    `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(Number(value) || 0)} đ`

const deletePayment = (payment: { id: number }) => {
    if (!window.confirm('Bạn có chắc muốn xóa giao dịch này? Hành động không thể hoàn tác.')) return
    router.delete(`/admin/bookings/${props.bookingId}/payments/${payment.id}`, { preserveScroll: true })
}
</script>

<template>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-steel">
                <tr>
                    <th class="px-4 py-3">Loại</th>
                    <th class="px-4 py-3">Số tiền</th>
                    <th class="px-4 py-3">Phương thức</th>
                    <th class="px-4 py-3">Thời gian thanh toán</th>
                    <th class="px-4 py-3">Xác nhận bởi</th>
                    <th class="px-4 py-3">Ghi chú</th>
                    <th v-if="canDeletePayment" class="px-4 py-3 text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr v-for="payment in payments" :key="payment.id">
                    <td class="px-4 py-3">{{ labelFor('paymentType', payment.payment_type) }}</td>
                    <td class="px-4 py-3" :class="payment.payment_type === 'REFUND' ? 'text-coral' : ''">
                        {{ payment.payment_type === 'REFUND' ? '−' : '' }}{{ formatCurrency(payment.amount) }}
                    </td>
                    <td class="px-4 py-3">{{ labelFor('paymentMethod', payment.payment_method ?? '') }}</td>
                    <td class="px-4 py-3">{{ payment.payment_at }}</td>
                    <td class="px-4 py-3">{{ payment.confirmed_by }}</td>
                    <td class="px-4 py-3">{{ payment.note }}</td>
                    <td v-if="canDeletePayment" class="px-4 py-3 text-right">
                        <button
                            v-if="payment.can_delete"
                            type="button"
                            class="inline-flex h-8 w-8 items-center justify-center border border-gray-200 text-steel hover:border-coral hover:text-coral"
                            title="Xóa giao dịch"
                            @click="deletePayment(payment)"
                        >
                            <Trash2 class="h-4 w-4" />
                        </button>
                    </td>
                </tr>
                <tr v-if="!payments.length">
                    <td :colspan="canDeletePayment ? 7 : 6" class="px-4 py-10 text-center text-sm text-steel">Chưa có thanh toán.</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
