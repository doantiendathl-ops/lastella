<script setup>
import { labelFor } from '@/Support/vietnameseLabels';
import { router } from '@inertiajs/vue3';
import { CheckCircle, LogIn, LogOut } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps({
    booking: { type: Object, required: true },
    can: { type: Object, required: true },
    paymentSummary: { type: Object, required: true },
})

const activeStays = computed(() =>
    (props.booking.stays ?? []).filter((s) => s.status !== 'CANCELLED' && !s.is_released)
)

const checkedInStays = computed(() => activeStays.value.filter((s) => s.status === 'CHECKED_IN'))

const isLastCheckedIn = (stay) =>
    stay.status === 'CHECKED_IN' && checkedInStays.value.length === 1

// ADR-52: disabled state is UX only; OutstandingBalanceException is authoritative on server
const checkoutDisabled = (stay) =>
    isLastCheckedIn(stay) && (props.paymentSummary?.balance_due ?? 0) > 0

const lastStayConfirmTarget = ref(null)
const checkoutWarningStay = ref(null)

const checkIn = (stay) =>
    router.post(`/admin/bookings/${props.booking.id}/stays/${stay.id}/check-in`, {}, { preserveScroll: true })

const checkOut = (stay) => {
    const balance = props.paymentSummary?.balance_due ?? 0

    if (isLastCheckedIn(stay)) {
        // last stay + zero balance → confirmation modal
        if (balance === 0) {
            lastStayConfirmTarget.value = stay
        }
        // last stay + balance > 0 → button is disabled; cannot reach here via normal UI
        return
    }

    // non-last stay: if balance, warn but don't block
    if (balance > 0) {
        checkoutWarningStay.value = stay
        return
    }

    router.post(`/admin/bookings/${props.booking.id}/stays/${stay.id}/check-out`, {}, { preserveScroll: true })
}

const confirmLastStayCheckout = () => {
    if (!lastStayConfirmTarget.value) return
    const stayId = lastStayConfirmTarget.value.id
    lastStayConfirmTarget.value = null
    router.post(`/admin/bookings/${props.booking.id}/stays/${stayId}/check-out`, {}, { preserveScroll: true })
}

const confirmCheckoutWithWarning = () => {
    if (!checkoutWarningStay.value) return
    const stayId = checkoutWarningStay.value.id
    checkoutWarningStay.value = null
    router.post(`/admin/bookings/${props.booking.id}/stays/${stayId}/check-out`, {}, { preserveScroll: true })
}

const checkInAll = () => {
    const stays = activeStays.value.filter((s) => s.can_check_in)
    if (!stays.length) return
    const doNext = (i) => {
        if (i >= stays.length) return
        router.post(
            `/admin/bookings/${props.booking.id}/stays/${stays[i].id}/check-in`,
            {},
            { preserveScroll: true, onSuccess: () => doNext(i + 1) },
        )
    }
    doNext(0)
}

// ADR-52 compatible: balance_due > 0 disables the button in the template;
// this function is only reached when balance is clear.
// Never attempt checkOutAll when balance > 0 — the last stay's checkout
// will be blocked by OutstandingBalanceException on the server, producing
// a partial checkout state (N-1 rooms checked out, last room still active).
const checkOutAll = () => {
    const stays = activeStays.value.filter((s) => s.can_check_out)
    if (!stays.length) return
    const doNext = (i) => {
        if (i >= stays.length) return
        router.post(
            `/admin/bookings/${props.booking.id}/stays/${stays[i].id}/check-out`,
            {},
            { preserveScroll: true, onSuccess: () => doNext(i + 1) },
        )
    }
    doNext(0)
}

const checkOutAllDisabled = computed(() => (props.paymentSummary?.balance_due ?? 0) > 0)
</script>

<template>
    <div class="p-5">
        <div class="flex items-center justify-between gap-2 border-b-2 border-pine/30 pb-2">
            <div class="flex items-center gap-2">
                <div class="h-5 w-1 bg-pine"></div>
                <span class="text-sm font-bold uppercase tracking-wide">Nhận phòng - Trả phòng</span>
            </div>
            <div class="flex items-center gap-2">
                <button
                    v-if="can.checkIn && activeStays.some(s => s.can_check_in)"
                    type="button"
                    class="inline-flex items-center gap-1.5 border border-gray-300 px-3 py-1 text-xs font-semibold text-steel hover:border-pine hover:text-pine"
                    @click="checkInAll"
                >
                    <LogIn class="h-3.5 w-3.5" /> Nhận tất cả phòng
                </button>
                <!-- ADR-52: disabled when balance > 0 — last-stay checkout would throw OBE -->
                <span
                    v-if="can.checkOut && activeStays.some(s => s.can_check_out) && checkOutAllDisabled"
                    class="inline-flex cursor-not-allowed items-center gap-1.5 border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-400"
                    title="Còn số dư cần thanh toán trước khi trả tất cả phòng"
                >
                    <LogOut class="h-3.5 w-3.5" /> Trả tất cả phòng
                </span>
                <button
                    v-else-if="can.checkOut && activeStays.some(s => s.can_check_out)"
                    type="button"
                    class="inline-flex items-center gap-1.5 border border-gray-300 px-3 py-1 text-xs font-semibold text-steel hover:border-pine hover:text-pine"
                    @click="checkOutAll"
                >
                    <LogOut class="h-3.5 w-3.5" /> Trả tất cả phòng
                </button>
            </div>
        </div>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-steel">
                    <tr>
                        <th class="px-4 py-3">Phòng</th>
                        <th class="px-4 py-3">Dự kiến nhận phòng</th>
                        <th class="px-4 py-3">Dự kiến trả phòng</th>
                        <th class="px-4 py-3">Thực nhận phòng</th>
                        <th class="px-4 py-3">Thực trả phòng</th>
                        <th class="px-4 py-3">Trạng thái</th>
                        <th class="px-4 py-3 text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="stay in activeStays" :key="stay.id">
                        <td class="px-4 py-3">{{ stay.room_number }}</td>
                        <td class="px-4 py-3">{{ stay.planned_checkin_at }}</td>
                        <td class="px-4 py-3">{{ stay.planned_checkout_at }}</td>
                        <td class="px-4 py-3">{{ stay.actual_checkin_at }}</td>
                        <td class="px-4 py-3">{{ stay.actual_checkout_at }}</td>
                        <td class="px-4 py-3">{{ labelFor('stayStatus', stay.status) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <button
                                v-if="can.checkIn && stay.can_check_in"
                                type="button"
                                class="mr-2 inline-flex items-center gap-2 border border-gray-300 px-3 py-1 text-xs font-semibold text-steel hover:border-pine hover:text-pine"
                                @click="checkIn(stay)"
                            >
                                <LogIn class="h-3.5 w-3.5" /> Nhận phòng
                            </button>
                            <span
                                v-else-if="can.checkIn && stay.checkin_too_early"
                                class="mr-2 text-[10px] text-amber-600"
                                :title="`Thời gian nhận phòng dự kiến: ${stay.planned_checkin_label}`"
                            >
                                Chưa đến giờ nhận phòng
                            </span>
                            <!-- ADR-52: disabled for last checked-in stay with outstanding balance -->
                            <span
                                v-if="can.checkOut && stay.can_check_out && checkoutDisabled(stay)"
                                class="inline-flex cursor-not-allowed items-center gap-2 border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-400"
                                :title="`Còn số dư cần thanh toán trước khi trả phòng cuối cùng`"
                            >
                                <LogOut class="h-3.5 w-3.5" /> Trả phòng
                            </span>
                            <button
                                v-else-if="can.checkOut && stay.can_check_out"
                                type="button"
                                class="inline-flex items-center gap-2 border border-gray-300 px-3 py-1 text-xs font-semibold text-steel hover:border-pine hover:text-pine"
                                @click="checkOut(stay)"
                            >
                                <LogOut class="h-3.5 w-3.5" /> Trả phòng
                            </button>
                            <CheckCircle v-if="stay.status === 'CHECKED_OUT'" class="ml-auto h-4 w-4 text-pine" />
                        </td>
                    </tr>
                    <tr v-if="activeStays.length === 0">
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-steel">Chưa có lưu trú đang hoạt động.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Last-stay checkout confirmation modal (ADR-52: UX only guard) -->
    <div v-if="lastStayConfirmTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
        <div class="w-full max-w-sm border border-gray-200 bg-white p-5 shadow-xl">
            <h2 class="text-base font-semibold">Xác nhận trả phòng cuối cùng</h2>
            <p class="mt-2 text-sm text-steel">
                Phòng <strong>{{ lastStayConfirmTarget.room_number }}</strong> là phòng đang lưu trú cuối cùng. Trả phòng sẽ kết thúc toàn bộ booking.
            </p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" class="border border-gray-300 px-4 py-2 text-sm font-semibold text-steel hover:text-ink" @click="lastStayConfirmTarget = null">Hủy</button>
                <button type="button" class="border border-pine bg-pine px-4 py-2 text-sm font-semibold text-white hover:bg-pine/90" @click="confirmLastStayCheckout">Xác nhận trả phòng</button>
            </div>
        </div>
    </div>

    <!-- Non-last stay with outstanding balance warning modal -->
    <div v-if="checkoutWarningStay" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
        <div class="w-full max-w-sm border border-gray-200 bg-white p-5 shadow-xl">
            <h2 class="text-base font-semibold text-coral">Booking còn số dư chưa thanh toán</h2>
            <p class="mt-2 text-sm text-steel">
                Bạn có muốn tiếp tục trả phòng <strong>{{ checkoutWarningStay.room_number }}</strong> không?
            </p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" class="border border-gray-300 px-4 py-2 text-sm font-semibold text-steel hover:text-ink" @click="checkoutWarningStay = null">Hủy</button>
                <button type="button" class="border border-coral bg-coral px-4 py-2 text-sm font-semibold text-white hover:bg-coral/90" @click="confirmCheckoutWithWarning">Vẫn trả phòng</button>
            </div>
        </div>
    </div>
</template>
