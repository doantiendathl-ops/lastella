<script setup lang="ts">
import AddChargeForm from './AddChargeForm.vue';
import AddPaymentForm from './AddPaymentForm.vue';
import CheckoutReadinessBanner from './CheckoutReadinessBanner.vue';
import FolioEntryTable from './FolioEntryTable.vue';
import FolioStatusBadge from './FolioStatusBadge.vue';
import PaymentProjectionSummary from './PaymentProjectionSummary.vue';
import PaymentSummary from './PaymentSummary.vue';
import PaymentTable from './PaymentTable.vue';
import { router } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
    booking: {
        id: number
        status: string
        folio: {
            folio_number: string
            status: string
            can_close: boolean
            can_reopen: boolean
            entries: {
                id: number
                charge_type: string
                charge_type_label: string
                description: string
                quantity: number
                unit_price: number
                amount: number
                entry_date: string
                posted_by: string | null
                is_voided: boolean
                voided_at: string | null
                voided_by: string | null
                void_reason: string | null
                is_system_entry: boolean
                can_void: boolean
                stay_id: number | null
                posting_source: string | null
            }[]
        } | null
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
        room_assignment_mismatch?: {
            has_mismatch: boolean
            items: { room_type_id: number; room_type_name: string; required_quantity: number; assigned_quantity: number; difference: number; status: string }[]
        }
    }
    paymentSummary: {
        total_charges: number
        paid_total: number
        balance_due: number
        total_deposit: number
        total_payment: number
        total_refund: number
        total_adjustment: number
    }
    paymentProjection?: {
        projected_room_total: number
        posted_non_room_total: number
        expected_total: number
        expected_deposit: number
        recognized_paid_total: number
        expected_balance: number
    }
    options: {
        chargeTypes: { value: string; label: string }[]
        paymentTypes: { value: string; label: string }[]
        paymentMethods: { value: string; label: string }[]
    }
    can: {
        createCharge: boolean
        voidCharge: boolean
        addPayment: boolean
        deletePayment: boolean
        overrideProductPrice: boolean
    }
    hasActiveStays: boolean
    serviceRates?: { id: number; name: string; charge_type: string; unit_price: number; unit_label: string }[]
    productServices?: { id: number; name: string; category_name: string | null; charge_type: string; unit_price: number; unit_label: string }[]
    checkableStays?: { id: number; room_number: string }[]
}>()

const showAddChargeForm = ref(false)

const closeFolio = () => {
    if (!window.confirm('Đóng folio này? Sau khi đóng, không thể thêm phí mới.')) return
    router.patch(`/admin/bookings/${props.booking.id}/folio/close`, {}, { preserveScroll: true })
}

const reopenFolio = () => {
    if (!window.confirm('Mở lại folio này?')) return
    router.patch(`/admin/bookings/${props.booking.id}/folio/reopen`, {}, { preserveScroll: true })
}
</script>

<template>
    <div class="space-y-5 p-5">
        <!-- Room assignment mismatch warning -->
        <div v-if="booking.room_assignment_mismatch?.has_mismatch" class="border border-amber-300 bg-amber-50 p-4">
            <div class="flex items-start gap-3">
                <span class="shrink-0 text-lg leading-none">⚠️</span>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-amber-800">Cảnh báo: Nhu cầu phòng và phòng đã gán chưa khớp. Vui lòng kiểm tra trước khi thu tiền.</p>
                    <ul class="mt-2 space-y-1">
                        <li v-for="item in booking.room_assignment_mismatch.items" :key="item.room_type_id" class="text-xs text-amber-700">
                            <span class="font-semibold">{{ item.room_type_name }}:</span>
                            Cần {{ item.required_quantity }}, đã gán {{ item.assigned_quantity }} —
                            <span v-if="item.status === 'missing'">còn thiếu {{ Math.abs(item.difference) }}</span>
                            <span v-else>gán vượt {{ item.difference }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Checkout readiness -->
        <CheckoutReadinessBanner
            :payment-summary="paymentSummary"
            :booking-status="booking.status"
            :has-active-stays="hasActiveStays"
        />

        <!-- Financial summary (posted ledger) -->
        <PaymentSummary :payment-summary="paymentSummary" />

        <!-- Payment projection (forecast — never the posted Folio balance) -->
        <PaymentProjectionSummary v-if="paymentProjection" :payment-projection="paymentProjection" />

        <!-- Folio Ledger Section -->
        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-steel">Phí phát sinh</h3>
                    <FolioStatusBadge v-if="booking.folio" :status="booking.folio.status" />
                    <!-- ADR-56: charge lock badge — derived from booking terminal status, no new DB column -->
                    <span
                        v-if="booking.status === 'CHECKED_OUT'"
                        class="inline-flex items-center gap-1 border border-gray-300 bg-gray-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500"
                        title="Tất cả phòng đã trả. Phí phát sinh đã được khóa."
                    >
                        Charges Locked
                    </span>
                </div>
                <button
                    v-if="can.createCharge && booking.folio?.status === 'OPEN'"
                    type="button"
                    class="inline-flex items-center gap-1 border border-gray-300 px-3 py-1.5 text-xs font-semibold text-steel hover:border-pine hover:text-pine"
                    @click="showAddChargeForm = !showAddChargeForm"
                >
                    <Plus class="h-3.5 w-3.5" />
                    Thêm phí
                </button>
            </div>

            <AddChargeForm
                v-if="showAddChargeForm && can.createCharge"
                :booking-id="booking.id"
                :charge-types="options.chargeTypes"
                :service-rates="serviceRates ?? []"
                :product-services="productServices ?? []"
                :can-override-product-price="can.overrideProductPrice"
                :checkable-stays="checkableStays ?? []"
                @cancel="showAddChargeForm = false"
            />

            <FolioEntryTable
                :entries="booking.folio?.entries ?? []"
                :booking-id="booking.id"
                :can-void-charge="can.voidCharge"
                :checkable-stays="checkableStays ?? []"
            />

            <!-- Folio metadata + status controls -->
            <div v-if="booking.folio" class="flex flex-wrap items-center justify-between gap-2 border border-gray-100 px-3 py-2 text-sm">
                <div class="text-steel">
                    Folio <span class="font-mono font-medium text-ink">{{ booking.folio.folio_number }}</span>
                </div>
                <div class="flex gap-2">
                    <button
                        v-if="booking.folio.can_close"
                        type="button"
                        class="border border-gray-300 px-3 py-1.5 text-xs font-semibold text-steel hover:border-ink hover:text-ink"
                        @click="closeFolio"
                    >
                        Đóng folio
                    </button>
                    <button
                        v-if="booking.folio.can_reopen"
                        type="button"
                        class="border border-pine px-3 py-1.5 text-xs font-semibold text-pine hover:bg-pine hover:text-white"
                        @click="reopenFolio"
                    >
                        Mở lại
                    </button>
                </div>
            </div>
        </div>

        <!-- Payments Section -->
        <div class="space-y-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-steel">Thanh toán</h3>

            <AddPaymentForm
                v-if="can.addPayment"
                :booking-id="booking.id"
                :payment-types="options.paymentTypes"
                :payment-methods="options.paymentMethods"
                :payment-summary="paymentSummary"
            />

            <PaymentTable
                :payments="booking.payments"
                :booking-id="booking.id"
                :can-delete-payment="can.deletePayment"
            />
        </div>
    </div>
</template>
