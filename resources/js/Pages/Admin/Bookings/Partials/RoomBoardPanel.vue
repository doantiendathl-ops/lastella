<script setup>
import { labelFor } from '@/Support/vietnameseLabels';
import { router, usePage } from '@inertiajs/vue3';
import { CheckCircle, LogIn, LogOut } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    booking: { type: Object, required: true },
    can: { type: Object, required: true },
    paymentSummary: { type: Object, required: true },
})

const page = usePage()

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
const checkOutAllConfirming = ref(false)

const checkIn = (stay) =>
    router.post(`/admin/bookings/${props.booking.id}/stays/${stay.id}/check-in`, {}, { preserveScroll: true })

const checkOut = (stay) => {
    const balance = props.paymentSummary?.balance_due ?? 0

    // Non-last stay with outstanding balance — warn before proceeding (UX only; checkout will succeed)
    if (!isLastCheckedIn(stay) && balance > 0) {
        checkoutWarningStay.value = stay
        return
    }

    // ADR-55: for final checkouts, backend is authoritative. Send without confirmed; if backend
    // fires FinalCheckoutConfirmationRequiredException the flash watcher shows the dialog.
    router.post(`/admin/bookings/${props.booking.id}/stays/${stay.id}/check-out`, {}, { preserveScroll: true })
}

// ADR-55: resend with confirmed=true after user approves the charge-review dialog
const confirmLastStayCheckout = () => {
    if (!lastStayConfirmTarget.value) return
    const stayId = lastStayConfirmTarget.value.id
    lastStayConfirmTarget.value = null
    router.post(
        `/admin/bookings/${props.booking.id}/stays/${stayId}/check-out`,
        { confirmed: true },
        { preserveScroll: true },
    )
}

// ADR-55: watch the flash object (not the scalar key) so the watcher re-fires after
// dismiss-then-retry — Inertia replaces the flash object by reference on each navigation,
// guaranteeing a change even when the stay ID is the same integer as the previous redirect.
watch(
    () => page.props.flash,
    (flash) => {
        const stayId = flash?.final_checkout_confirmation_required
        if (!stayId) return
        const stay = (props.booking.stays ?? []).find((s) => s.id === stayId)
        if (stay) lastStayConfirmTarget.value = stay
    },
    { immediate: true },
)

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

// Show the pre-checkout confirmation dialog before sending any requests
const requestCheckOutAll = () => {
    const stays = activeStays.value.filter((s) => s.can_check_out)
    if (!stays.length) return
    checkOutAllConfirming.value = true
}

// ADR-55: send confirmed=true on ALL requests; backend ignores it for non-final stays
// and accepts it for the final stay — avoids frontend tracking which stay is last.
const checkOutAll = () => {
    checkOutAllConfirming.value = false
    const stays = activeStays.value.filter((s) => s.can_check_out)
    if (!stays.length) return
    const doNext = (i) => {
        if (i >= stays.length) return
        router.post(
            `/admin/bookings/${props.booking.id}/stays/${stays[i].id}/check-out`,
            { confirmed: true },
            { preserveScroll: true, onSuccess: () => doNext(i + 1) },
        )
    }
    doNext(0)
}

const checkOutAllDisabled = computed(() => (props.paymentSummary?.balance_due ?? 0) > 0)

const REQUEST_TYPE_EMOJI = {
    twin_keep: '🛏', twin_to_double: '🛏', separate_beds: '🛏', extra_bed: '🛏',
    baby_cot: '👶', extra_pillow: '🛌', non_feather_pillow: '🛌', extra_blanket: '🛌',
    extra_towel: '🛁', welcome_fruit: '🍎', welcome_amenity: '🎁',
    anniversary: '💍', honeymoon: '🌹', birthday: '🎂', vip_setup: '⭐', flower_arrangement: '🌸',
    wheelchair: '♿', non_smoking_prep: '🚭', ground_floor: '🏠', near_elevator: '🛗',
    late_arrival: '🌙', airport_pickup: '✈️', connecting_room: '🚪', other: '📝',
}

function requestEmoji(type) {
    return REQUEST_TYPE_EMOJI[type] ?? '📋'
}

function requestStatusIcon(status) {
    if (status === 'fulfilled') return '✓'
    if (status === 'cancelled') return '✕'
    return '⏳'
}

function requestStatusClass(status) {
    if (status === 'fulfilled') return 'text-green-600'
    if (status === 'cancelled') return 'text-gray-400 line-through'
    return 'text-amber-600'
}
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
                    @click="requestCheckOutAll"
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
                        <td class="px-4 py-3">
                            <div>{{ stay.room_number }}</div>
                            <div v-if="stay.special_requests?.length" class="mt-1 flex flex-wrap gap-1">
                                <span
                                    v-for="(req, i) in stay.special_requests"
                                    :key="i"
                                    class="inline-flex items-center gap-0.5 text-xs"
                                    :class="requestStatusClass(req.status)"
                                    :title="req.request_type"
                                >{{ requestEmoji(req.request_type) }}{{ requestStatusIcon(req.status) }}</span>
                            </div>
                        </td>
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

    <!-- ADR-55: Final checkout charge-review confirmation dialog (backend-driven) -->
    <div v-if="lastStayConfirmTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
        <div class="w-full max-w-md border border-gray-200 bg-white p-5 shadow-xl">
            <h2 class="text-base font-semibold">Xác nhận trả phòng cuối cùng</h2>
            <p class="mt-2 text-sm text-steel">
                Bạn đang trả phòng <strong>{{ lastStayConfirmTarget.room_number }}</strong> — phòng đang lưu trú cuối cùng của booking này.
            </p>
            <p class="mt-3 text-sm text-steel">Sau khi trả phòng:</p>
            <ul class="mt-1.5 space-y-1 text-sm text-steel">
                <li>• Phí vận hành (minibar, giặt ủi, nhà hàng…) <strong>sẽ không thể thêm hoặc huỷ nữa.</strong></li>
                <li>• Vui lòng đảm bảo tất cả phí phát sinh đã được nhập trước khi tiếp tục.</li>
            </ul>
            <div class="mt-5 flex justify-end gap-2">
                <button
                    type="button"
                    class="border border-gray-300 px-4 py-2 text-sm font-semibold text-steel hover:text-ink"
                    @click="lastStayConfirmTarget = null"
                >
                    Quay lại Tài chính
                </button>
                <button
                    type="button"
                    class="border border-pine bg-pine px-4 py-2 text-sm font-semibold text-white hover:bg-pine/90"
                    @click="confirmLastStayCheckout"
                >
                    Xác nhận trả phòng cuối cùng
                </button>
            </div>
        </div>
    </div>

    <!-- ADR-55: CheckOutAll charge-review confirmation dialog (pre-sequence) -->
    <div v-if="checkOutAllConfirming" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
        <div class="w-full max-w-md border border-gray-200 bg-white p-5 shadow-xl">
            <h2 class="text-base font-semibold">Xác nhận trả tất cả phòng</h2>
            <p class="mt-2 text-sm text-steel">
                Bạn đang trả tất cả các phòng còn đang lưu trú. Đây là hành động trả phòng cuối cùng của booking.
            </p>
            <p class="mt-3 text-sm text-steel">Sau khi trả phòng:</p>
            <ul class="mt-1.5 space-y-1 text-sm text-steel">
                <li>• Phí vận hành (minibar, giặt ủi, nhà hàng…) <strong>sẽ không thể thêm hoặc huỷ nữa.</strong></li>
                <li>• Vui lòng đảm bảo tất cả phí phát sinh đã được nhập trước khi tiếp tục.</li>
            </ul>
            <div class="mt-5 flex justify-end gap-2">
                <button
                    type="button"
                    class="border border-gray-300 px-4 py-2 text-sm font-semibold text-steel hover:text-ink"
                    @click="checkOutAllConfirming = false"
                >
                    Quay lại Tài chính
                </button>
                <button
                    type="button"
                    class="border border-pine bg-pine px-4 py-2 text-sm font-semibold text-white hover:bg-pine/90"
                    @click="checkOutAll"
                >
                    Xác nhận trả phòng cuối cùng
                </button>
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
