<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import CheckoutInspectionModal from '@/Pages/Admin/CheckoutInspections/Partials/CheckoutInspectionModal.vue';
import CheckoutFlowDialogs from './Partials/CheckoutFlowDialogs.vue';
import DailyBookingSummary from './Partials/DailyBookingSummary.vue';
import RoomOperationsBoard from './Partials/RoomOperationsBoard.vue';
import RoomOperationsToolbar from './Partials/RoomOperationsToolbar.vue';
import SwapRoomDialog from './Partials/SwapRoomDialog.vue';

const props = defineProps({
    board: { type: Object, required: true },
    dailySummary: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
    can: { type: Object, required: true },
    inspectionProducts: { type: Array, default: () => [] },
});

// ---- Date selector (Mục IV) -------------------------------------------
const selectedDate = ref(props.filters.date);

function goToDate(date) {
    router.get(route('admin.room-operations.index'), { date }, { preserveState: true, preserveScroll: true });
}

watch(selectedDate, (date) => {
    if (date) goToDate(date);
});

function shiftDay(delta) {
    const d = new Date(`${selectedDate.value}T00:00:00`);
    d.setDate(d.getDate() + delta);
    selectedDate.value = d.toISOString().slice(0, 10);
}

// ---- Filters (Mục XXXVII) ----------------------------------------------
const search = ref('');
const cleanFilter = ref('all'); // all | clean | dirty
const checkinFilter = ref('all'); // all | vacant | pending | checked_in

const filteredFloors = computed(() => props.board.floors.map((floor) => ({
    ...floor,
    rooms: floor.rooms.filter((room) => {
        if (search.value.trim()) {
            const q = search.value.trim().toLowerCase();
            const matches = room.room_number.toLowerCase().includes(q)
                || room.occupant?.customer_name?.toLowerCase().includes(q)
                || room.occupant?.booking_code?.toLowerCase().includes(q);
            if (!matches) return false;
        }
        if (cleanFilter.value === 'clean' && !room.is_clean) return false;
        if (cleanFilter.value === 'dirty' && room.is_clean) return false;
        if (checkinFilter.value === 'vacant' && room.occupant) return false;
        if (checkinFilter.value === 'pending' && (!room.occupant || room.occupant.is_checked_in)) return false;
        if (checkinFilter.value === 'checked_in' && !room.occupant?.is_checked_in) return false;
        return true;
    }),
})).filter((floor) => floor.rooms.length > 0));

const allRoomsFlat = computed(() => props.board.floors.flatMap((f) => f.rooms));

// Room-Conflict Detection follow-up: a headline, room-number-specific alert
// so a double-booking is impossible to miss at the top of the board — not
// only visible on the individual room card, which an operator might not
// scroll to. Sourced from the SAME room.has_room_conflict flag the card
// itself reads, never a second computation.
const conflictedRooms = computed(() => allRoomsFlat.value.filter((r) => r.has_room_conflict));

// ---- Selection -----------------------------------------------------------
const selectedIds = ref(new Set());

function toggleSelect(roomId) {
    const next = new Set(selectedIds.value);
    if (next.has(roomId)) next.delete(roomId);
    else next.add(roomId);
    selectedIds.value = next;
}

function clearSelection() {
    selectedIds.value = new Set();
}

const selectedRooms = computed(() => allRoomsFlat.value.filter((r) => selectedIds.value.has(r.id)));

// ---- Toasts ----------------------------------------------------------------
const toasts = ref([]);
let toastSeq = 0;
function pushToast(message, variant = 'success') {
    const id = ++toastSeq;
    toasts.value = [...toasts.value, { id, message, variant }];
    setTimeout(() => {
        toasts.value = toasts.value.filter((t) => t.id !== id);
    }, 4000);
}

function refresh() {
    router.reload({ only: ['board', 'dailySummary'] });
}

// ---- Quick note (Mục VII/XXXIII) --------------------------------------------
// "Ghép giường" is no longer edited here — it is derived read-only from the
// existing Special Request module (Room-Scoped Bed Operations Correction).
function saveNote({ assignmentId, note }) {
    router.patch(
        route('admin.room-operations.assignments.quick-note', assignmentId),
        { quick_note: note },
        { preserveScroll: true, preserveState: true, only: ['board'], onSuccess: () => pushToast('Đã lưu ghi chú.') },
    );
}

// ---- Bulk quick actions (Mục XXII/XXIII/XXIV/XXV) --------------------------
function bulkCheckIn() {
    const stayIds = selectedRooms.value.filter((r) => r.actions?.can_check_in).map((r) => r.occupant.stay_id);
    if (stayIds.length === 0) return;

    router.post(route('admin.room-operations.check-in'), { stay_ids: stayIds }, {
        preserveScroll: true,
        onSuccess: (page) => {
            const hasErrors = Object.keys(page.props.errors ?? {}).length > 0;
            pushToast(hasErrors ? 'Một số phòng không thể nhận — xem chi tiết bên dưới.' : (page.props.flash?.success ?? 'Đã nhận phòng.'), hasErrors ? 'error' : 'success');
            clearSelection();
        },
    });
}

// ---- Checkout flow (Mục II/III/IV/V/VI/VII) ---------------------------------
// Reuses the EXACT same backend endpoint/service as before — StayService::
// checkOut() is unchanged and remains the sole source of truth for every
// guard (inspection is advisory only there too, balance/state are hard
// gates). This state machine only sequences WHEN that request is sent from
// the UI: inspection warning (if uninspected) → exact-room confirmation
// (always) → the request → the existing final-checkout-confirmation dialog
// if the backend asks for it. No checkout request is ever sent before the
// user reaches the "Xác nhận trả phòng" step.
const checkoutFlow = ref(null);

function requestCheckOut() {
    const rooms = selectedRooms.value
        .filter((r) => r.actions?.can_check_out)
        .map((r) => ({
            room_id: r.id,
            room_number: r.room_number,
            stay_id: r.occupant.stay_id,
            booking_id: r.occupant.booking_id,
            inspection_status: r.occupant.inspection_status,
        }));
    if (rooms.length === 0) return;

    const uninspectedRooms = rooms.filter((r) => r.inspection_status !== 'completed' && r.inspection_status !== 'skipped');

    checkoutFlow.value = uninspectedRooms.length > 0
        ? { stage: 'inspection-warning', rooms, uninspectedRooms, skipReason: '' }
        : { stage: 'confirm', rooms };
}

function cancelCheckoutFlow() {
    checkoutFlow.value = null;
}

function dismissInspectionWarning() {
    if (!checkoutFlow.value) return;
    checkoutFlow.value = { stage: 'confirm', rooms: checkoutFlow.value.rooms };
}

async function skipInspectionAndContinue() {
    if (!checkoutFlow.value?.skipReason?.trim()) return;
    const { rooms, uninspectedRooms, skipReason } = checkoutFlow.value;

    try {
        await Promise.all(uninspectedRooms.map((r) => axios.post(
            route('admin.bookings.stays.inspection-skip', { booking: r.booking_id, stay: r.stay_id }),
            { reason: skipReason.trim() },
        )));
    } catch {
        // Skip-record is a permission-gated formal action — if it fails (e.g. the
        // acting user lacks checkout_inspection.override, enforced server-side),
        // fall through to the advisory "still checkout" path rather than
        // silently blocking the operator entirely.
    }

    checkoutFlow.value = { stage: 'confirm', rooms };
}

function confirmCheckout() {
    if (!checkoutFlow.value) return;
    const { rooms } = checkoutFlow.value;
    const stayIds = rooms.map((r) => r.stay_id);

    router.post(route('admin.room-operations.check-out'), { stay_ids: stayIds, confirmed: false }, {
        preserveScroll: true,
        onSuccess: (page) => {
            const flash = page.props.flash ?? {};
            const pendingStayIds = flash.final_checkout_confirmation_required ?? [];

            if (pendingStayIds.length > 0) {
                checkoutFlow.value = {
                    stage: 'final-confirm',
                    finalRooms: rooms.filter((r) => pendingStayIds.includes(r.stay_id)),
                    pendingStayIds,
                };
                return;
            }

            const hasErrors = Object.keys(page.props.errors ?? {}).length > 0;
            pushToast(hasErrors ? 'Một số phòng không thể trả — xem chi tiết bên dưới.' : (flash.success ?? 'Đã trả phòng.'), hasErrors ? 'error' : 'success');
            checkoutFlow.value = null;
            clearSelection();
        },
    });
}

function confirmFinalCheckout() {
    if (!checkoutFlow.value?.pendingStayIds) return;
    const { pendingStayIds } = checkoutFlow.value;

    router.post(route('admin.room-operations.check-out'), { stay_ids: pendingStayIds, confirmed: true }, {
        preserveScroll: true,
        onSuccess: (page) => {
            const hasErrors = Object.keys(page.props.errors ?? {}).length > 0;
            pushToast(hasErrors ? 'Một số phòng không thể trả — xem chi tiết bên dưới.' : 'Đã trả phòng.', hasErrors ? 'error' : 'success');
            checkoutFlow.value = null;
            clearSelection();
        },
    });
}

function updateSkipReason(value) {
    if (checkoutFlow.value) checkoutFlow.value = { ...checkoutFlow.value, skipReason: value };
}

async function bulkClean() {
    const targets = selectedRooms.value.filter((r) => r.actions?.can_clean);
    if (targets.length === 0) return;

    const results = await Promise.allSettled(targets.map((room) => {
        const action = room.is_clean ? 'mark-dirty' : 'mark-clean';
        return axios.patch(route(`admin.housekeeping.${action}`, room.id));
    }));

    const failed = results.filter((r) => r.status === 'rejected').length;
    pushToast(
        failed > 0
            ? `Đã cập nhật ${targets.length - failed}/${targets.length} phòng (${failed} lỗi).`
            : `Đã cập nhật trạng thái dọn phòng cho ${targets.length} phòng.`,
        failed > 0 ? 'error' : 'success',
    );
    clearSelection();
    refresh();
}

// ---- Fast Inspection Popup (Mục I.1/II-IX) -----------------------------------
// Reuses CheckoutInspectionModal.vue AS-IS — same component, same canonical
// admin.checkout-inspections.draft/save/complete endpoints — never a second
// inspection state. Opens in place; the board is never left.
const inspectionTarget = ref(null);

function openInspectionFor(room) {
    if (!room?.occupant) return;
    inspectionTarget.value = {
        stay_id: room.occupant.stay_id,
        room_number: room.room_number,
        guest_name: room.occupant.customer_name,
        standard_occupancy: room.room_type_standard_adults,
        is_stay_checked_out: room.occupant.is_checked_out,
        inspection: null, // modal fetches/creates the draft itself (getOrCreateDraft is idempotent)
    };
}

function openInspectFromToolbar() {
    // Mục I.1 describes a single room's "Kiểm đồ" click. With multiple rooms
    // selected, the first eligible one opens — a popup is inherently a
    // one-stay-at-a-time surface (same shape as the existing Checkout
    // Inspection grid, which also opens one stay's modal at a time).
    const target = selectedRooms.value.find((r) => r.actions?.can_inspect);
    if (!target) return;
    if (selectedRooms.value.filter((r) => r.actions?.can_inspect).length > 1) {
        pushToast('Đã mở kiểm đồ cho phòng đầu tiên trong danh sách chọn.', 'success');
    }
    openInspectionFor(target);
}

function closeInspection() {
    inspectionTarget.value = null;
}

function onInspectionSuccess() {
    // Partial refresh — board.floors picks up the updated inspection_status,
    // no full page reload.
    router.reload({ only: ['board'] });
}

// Checkout inspection-warning "Kiểm đồ ngay" (Mục VII) — opens the SAME
// popup instead of navigating; never auto-checks-out on completion (Mục VIII).
function openInspectionFromCheckoutWarning() {
    if (!checkoutFlow.value?.uninspectedRooms?.length) return;
    const first = checkoutFlow.value.uninspectedRooms[0];
    const room = allRoomsFlat.value.find((r) => r.id === first.room_id);
    if (!room) return;
    checkoutFlow.value = null;
    openInspectionFor(room);
}

function viewBooking(bookingId) {
    router.visit(route('admin.bookings.show', bookingId));
}

// ---- Swap dialog (Mục IX-XXI) -----------------------------------------------
const swapDialogOpen = ref(false);

function openSwapDialog() {
    if (selectedRooms.value.filter((r) => r.actions?.can_swap).length === 0) return;
    swapDialogOpen.value = true;
}

function onSwapDone() {
    swapDialogOpen.value = false;
    clearSelection();
    pushToast('Đã đổi phòng thành công.');
    refresh();
}
</script>

<template>
    <AppLayout>
        <div class="space-y-4 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-lg font-semibold text-gray-900">Sơ đồ thao tác</h1>
                <div class="flex items-center gap-2">
                    <button type="button" class="rounded border border-gray-300 px-2 py-1 text-sm" @click="shiftDay(-1)">‹</button>
                    <input v-model="selectedDate" type="date" class="rounded border border-gray-300 p-1.5 text-sm" />
                    <button type="button" class="rounded border border-gray-300 px-2 py-1 text-sm" @click="shiftDay(1)">›</button>
                </div>
            </div>

            <div
                v-if="conflictedRooms.length > 0"
                class="flex items-start gap-2 rounded border border-red-600 bg-red-50 p-3 text-sm text-red-800"
                role="alert"
            >
                <span class="mt-0.5 font-semibold">⚠</span>
                <div>
                    <div class="font-semibold">{{ conflictedRooms.length }} phòng đang bị trùng (2 booking cùng giữ 1 phòng):</div>
                    <div class="mt-0.5">
                        Phòng {{ conflictedRooms.map((r) => r.room_number).join(', ') }} — cần vào từng phòng để gỡ hoặc chuyển 1 trong 2 booking.
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 md:grid-cols-7">
                <div
                    v-for="(value, key) in board.summary"
                    :key="key"
                    class="rounded border p-2 text-center"
                    :class="key === 'conflicted_rooms' && value > 0 ? 'border-red-600 bg-red-50' : 'border-gray-200 bg-white'"
                >
                    <div class="text-lg font-semibold" :class="key === 'conflicted_rooms' && value > 0 ? 'text-red-700' : 'text-gray-900'">{{ value }}</div>
                    <div class="text-[10px] text-gray-500">{{ key }}</div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <input v-model="search" type="text" placeholder="Tìm phòng/khách/mã booking…" class="rounded border border-gray-300 p-1.5 text-sm" />
                <select v-model="cleanFilter" class="rounded border border-gray-300 p-1.5 text-sm">
                    <option value="all">Tất cả (sạch/bẩn)</option>
                    <option value="clean">Sạch</option>
                    <option value="dirty">Bẩn</option>
                </select>
                <select v-model="checkinFilter" class="rounded border border-gray-300 p-1.5 text-sm">
                    <option value="all">Tất cả (trạng thái)</option>
                    <option value="vacant">Phòng trống</option>
                    <option value="pending">Chờ nhận phòng</option>
                    <option value="checked_in">Đã nhận phòng</option>
                </select>
            </div>

            <RoomOperationsBoard
                :floors="filteredFloors"
                :selected-ids="selectedIds"
                :can-edit-note="can.swap"
                @toggle-select="toggleSelect"
                @view-booking="viewBooking"
                @save-note="saveNote"
            />

            <div>
                <h2 class="mb-2 text-sm font-semibold text-gray-700">Booking trong ngày</h2>
                <DailyBookingSummary :bookings="dailySummary" :can-view-booking="can.viewBooking" />
            </div>
        </div>

        <RoomOperationsToolbar
            :selected-rooms="selectedRooms"
            :can="can"
            @swap="openSwapDialog"
            @check-in="bulkCheckIn"
            @check-out="requestCheckOut"
            @inspect="openInspectFromToolbar"
            @clean="bulkClean"
            @clear="clearSelection"
        />

        <SwapRoomDialog
            v-if="swapDialogOpen"
            :source-rooms="selectedRooms.filter((r) => r.actions?.can_swap)"
            :all-rooms="allRoomsFlat"
            @close="swapDialogOpen = false"
            @done="onSwapDone"
        />

        <CheckoutFlowDialogs
            :flow="checkoutFlow"
            :can-override-inspection="can.overrideCheckoutInspection"
            @cancel="cancelCheckoutFlow"
            @dismiss-inspection-warning="dismissInspectionWarning"
            @skip-inspection="skipInspectionAndContinue"
            @update:skip-reason="updateSkipReason"
            @confirm="confirmCheckout"
            @confirm-final="confirmFinalCheckout"
            @open-inspection="openInspectionFromCheckoutWarning"
        />

        <CheckoutInspectionModal
            v-if="inspectionTarget"
            :stay="inspectionTarget"
            :products="inspectionProducts"
            @close="closeInspection"
            @success="onInspectionSuccess"
        />

        <div class="fixed bottom-4 right-4 z-50 space-y-2">
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="rounded px-3 py-2 text-sm text-white shadow-lg"
                :class="toast.variant === 'error' ? 'bg-red-600' : 'bg-emerald-600'"
            >
                {{ toast.message }}
            </div>
        </div>
    </AppLayout>
</template>
