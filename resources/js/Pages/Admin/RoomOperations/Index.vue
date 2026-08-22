<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import CheckoutInspectionModal from '@/Pages/Admin/CheckoutInspections/Partials/CheckoutInspectionModal.vue';
import CheckoutFlowDialogs from './Partials/CheckoutFlowDialogs.vue';
import DailyBookingSummary from './Partials/DailyBookingSummary.vue';
import RoomOperationsBoard from './Partials/RoomOperationsBoard.vue';
import RoomOperationsPrintView from './Partials/RoomOperationsPrintView.vue';
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

// User request (2026-08-22 chat) — "in sơ đồ thao tác trên trang A4... để
// buồng có thể căn cứ vào đấy làm việc". window.print() triggers the
// browser's native print dialog; the @media print rules in this file's
// <style> block swap what's visible so ONLY RoomOperationsPrintView.vue's
// table renders (never a screenshot of the colored interactive board).
function printBoard() {
    window.print();
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
    // User request (2026-08-20 chat) — while picking swap replacement rooms,
    // every checkbox click on the board means "pick this as a replacement",
    // not the normal multi-select toggle. See swapFlow below.
    if (swapFlow.value) {
        toggleSwapTarget(roomId);
        return;
    }
    const next = new Set(selectedIds.value);
    if (next.has(roomId)) next.delete(roomId);
    else next.add(roomId);
    selectedIds.value = next;
}

function clearSelection() {
    selectedIds.value = new Set();
}

const selectedRooms = computed(() => allRoomsFlat.value.filter((r) => selectedIds.value.has(r.id)));

// docs/yeucaumoi.txt mục 12 — select every room this Booking currently
// occupies (from the full, unfiltered set — a room hidden by search/filter
// still belongs to the booking and should still be selected). "Currently
// occupies" is exactly what room.occupant already encodes: only rooms whose
// occupant truly matches this booking at the selected date/time.
function selectBookingRooms(bookingId) {
    const bookingRoomIds = allRoomsFlat.value
        .filter((r) => r.occupant?.booking_id === bookingId)
        .map((r) => r.id);
    if (bookingRoomIds.length === 0) return;
    selectedIds.value = new Set([...selectedIds.value, ...bookingRoomIds]);
}

// docs/yeucaumoi.txt mục 13 — select every room currently VISIBLE, i.e.
// respecting floor/search/filters/selected date already applied to
// filteredFloors — never a room that's been filtered out.
function selectAllVisible() {
    const visibleIds = filteredFloors.value.flatMap((f) => f.rooms.map((r) => r.id));
    selectedIds.value = new Set(visibleIds);
}

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

// ---- ADMIN actual time override (User request, 2026-08-18 chat) -----------
// Same principle as RoomBoardPanel.vue on the booking-detail page: Reception's
// flow is unchanged (click → immediate POST → server uses now()); only when
// can.adjustActualTime (ADMIN) does clicking first open a small "actual time"
// dialog, defaulting to now, editable. Here it applies ONE shared time to
// every stay in the current multi-select, since bulk check-in/out already
// means "these rooms, right now, together."
const nowForDatetimeLocal = () => {
    const d = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

function handleBulkActionResult(page, successMsg, errorMsg) {
    const hasErrors = Object.keys(page.props.errors ?? {}).length > 0;
    pushToast(hasErrors ? errorMsg : (page.props.flash?.success ?? successMsg), hasErrors ? 'error' : 'success');
    clearSelection();
}

// ---- Bulk quick actions (Mục XXII/XXIII/XXIV/XXV) --------------------------
const checkInTimeDialog = ref(null); // { roomNumbers } — stay_ids lives on the form itself
const checkInTimeForm = useForm({ stay_ids: [], actual_checkin_at: '' });

function bulkCheckIn() {
    const targets = selectedRooms.value.filter((r) => r.actions?.can_check_in);
    if (targets.length === 0) return;
    const stayIds = targets.map((r) => r.occupant.stay_id);

    if (props.can.adjustActualTime) {
        checkInTimeForm.clearErrors();
        checkInTimeForm.stay_ids = stayIds;
        checkInTimeForm.actual_checkin_at = nowForDatetimeLocal();
        checkInTimeDialog.value = { roomNumbers: targets.map((r) => r.room_number) };
        return;
    }

    submitBulkCheckIn(stayIds);
}

function submitBulkCheckIn(stayIds, actualCheckinAt = null) {
    router.post(
        route('admin.room-operations.check-in'),
        { stay_ids: stayIds, ...(actualCheckinAt ? { actual_checkin_at: actualCheckinAt } : {}) },
        {
            preserveScroll: true,
            onSuccess: (page) => handleBulkActionResult(page, 'Đã nhận phòng.', 'Một số phòng không thể nhận — xem chi tiết bên dưới.'),
        },
    );
}

function closeCheckInTimeDialog() {
    checkInTimeDialog.value = null;
    checkInTimeForm.clearErrors();
}

function submitCheckInTimeDialog() {
    if (!checkInTimeDialog.value) return;
    checkInTimeForm.post(route('admin.room-operations.check-in'), {
        preserveScroll: true,
        onSuccess: (page) => {
            checkInTimeDialog.value = null;
            handleBulkActionResult(page, 'Đã nhận phòng.', 'Một số phòng không thể nhận — xem chi tiết bên dưới.');
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

// ADMIN actual checkout time — shown BEFORE the existing inspection/balance/
// final-confirm sequence below runs, exactly like RoomBoardPanel.vue's
// checkOut(): the whole flow after this point carries the chosen time.
const adminCheckoutTimeDialog = ref(null); // { rooms }
const adminCheckoutTimeValue = ref('');
const checkoutTimeError = ref('');

function checkoutOverridePayload() {
    return props.can.adjustActualTime && adminCheckoutTimeValue.value
        ? { actual_checkout_at: adminCheckoutTimeValue.value }
        : {};
}

function handleCheckoutTimeError(rooms) {
    return (errors) => {
        if (errors.actual_checkout_at) {
            checkoutTimeError.value = errors.actual_checkout_at;
            adminCheckoutTimeDialog.value = { rooms };
            checkoutFlow.value = null;
        }
    };
}

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

    if (props.can.adjustActualTime) {
        adminCheckoutTimeValue.value = nowForDatetimeLocal();
        checkoutTimeError.value = '';
        adminCheckoutTimeDialog.value = { rooms };
        return;
    }

    startCheckoutFlowFor(rooms);
}

function startCheckoutFlowFor(rooms) {
    const uninspectedRooms = rooms.filter((r) => r.inspection_status !== 'completed' && r.inspection_status !== 'skipped');

    checkoutFlow.value = uninspectedRooms.length > 0
        ? { stage: 'inspection-warning', rooms, uninspectedRooms, skipReason: '' }
        : { stage: 'confirm', rooms };
}

function closeAdminCheckoutTimeDialog() {
    adminCheckoutTimeDialog.value = null;
    checkoutTimeError.value = '';
}

function confirmAdminCheckoutTime() {
    if (!adminCheckoutTimeDialog.value) return;
    const { rooms } = adminCheckoutTimeDialog.value;
    adminCheckoutTimeDialog.value = null;
    startCheckoutFlowFor(rooms);
}

function cancelCheckoutFlow() {
    checkoutFlow.value = null;
}

// User request (2026-08-20 chat) — "chưa kiểm đồ thì không thể check out
// được": the free "Vẫn tiếp tục" bypass is gone (dismissInspectionWarning
// removed along with it). skipInspectionAndContinue() below is now the
// ONLY way past an uninspected room, and it must fail CLOSED: if the
// formal skip record fails to save, do not silently continue to checkout
// — that would recreate the exact hole this change closes.
async function skipInspectionAndContinue() {
    if (!checkoutFlow.value?.skipReason?.trim()) return;
    const { rooms, uninspectedRooms, skipReason } = checkoutFlow.value;

    try {
        await Promise.all(uninspectedRooms.map((r) => axios.post(
            route('admin.bookings.stays.inspection-skip', { booking: r.booking_id, stay: r.stay_id }),
            { reason: skipReason.trim() },
        )));
    } catch (error) {
        pushToast(
            error?.response?.data?.message ?? 'Không thể ghi nhận bỏ qua kiểm đồ — chưa trả phòng được.',
            'error',
        );
        return;
    }

    checkoutFlow.value = { stage: 'confirm', rooms };
}

function confirmCheckout() {
    if (!checkoutFlow.value) return;
    const { rooms } = checkoutFlow.value;
    const stayIds = rooms.map((r) => r.stay_id);

    router.post(route('admin.room-operations.check-out'), { stay_ids: stayIds, confirmed: false, ...checkoutOverridePayload() }, {
        preserveScroll: true,
        onError: handleCheckoutTimeError(rooms),
        onSuccess: (page) => {
            const flash = page.props.flash ?? {};
            const pendingStayIds = flash.final_checkout_confirmation_required ?? [];
            const balancesByStay = flash.final_checkout_balances ?? {};

            if (pendingStayIds.length > 0) {
                checkoutFlow.value = {
                    stage: 'final-confirm',
                    // docs/Prompt_2.txt mục VIII — each room carries its own booking's
                    // real balance_due so the dialog can warn per booking, not just once.
                    finalRooms: rooms
                        .filter((r) => pendingStayIds.includes(r.stay_id))
                        .map((r) => ({ ...r, balance_due: balancesByStay[r.stay_id] ?? 0 })),
                    pendingStayIds,
                };
                return;
            }

            const hasErrors = Object.keys(page.props.errors ?? {}).length > 0;
            pushToast(hasErrors ? 'Một số phòng không thể trả — xem chi tiết bên dưới.' : (flash.success ?? 'Đã trả phòng.'), hasErrors ? 'error' : 'success');
            checkoutFlow.value = null;
            adminCheckoutTimeValue.value = '';
            clearSelection();
        },
    });
}

function confirmFinalCheckout() {
    if (!checkoutFlow.value?.pendingStayIds) return;
    const { pendingStayIds, finalRooms } = checkoutFlow.value;

    router.post(route('admin.room-operations.check-out'), { stay_ids: pendingStayIds, confirmed: true, ...checkoutOverridePayload() }, {
        preserveScroll: true,
        onError: handleCheckoutTimeError(finalRooms),
        onSuccess: (page) => {
            const hasErrors = Object.keys(page.props.errors ?? {}).length > 0;
            pushToast(hasErrors ? 'Một số phòng không thể trả — xem chi tiết bên dưới.' : 'Đã trả phòng.', hasErrors ? 'error' : 'success');
            checkoutFlow.value = null;
            adminCheckoutTimeValue.value = '';
            clearSelection();
        },
    });
}

function updateSkipReason(value) {
    if (checkoutFlow.value) checkoutFlow.value = { ...checkoutFlow.value, skipReason: value };
}

// ---- ADMIN: correct an already-recorded actual time, from the board ------
// "nếu nhân viên quên có thể nhập lại" — mirrors RoomBoardPanel.vue's pencil-
// icon edit-after-fact dialogs exactly, using the new board-scoped PATCH
// routes (RoomOperationsController::updateActualCheckIn/Out) so the operator
// never leaves the board.
const toDatetimeLocal = (value) => (value ? value.replace(' ', 'T') : '');

const editCheckInTarget = ref(null); // room
const editCheckInForm = useForm({ actual_checkin_at: '' });

function openEditCheckIn(room) {
    editCheckInForm.clearErrors();
    editCheckInForm.actual_checkin_at = toDatetimeLocal(room.occupant.actual_checkin_at);
    editCheckInTarget.value = room;
}

function closeEditCheckIn() {
    editCheckInTarget.value = null;
    editCheckInForm.clearErrors();
}

function submitEditCheckIn() {
    if (!editCheckInTarget.value) return;
    editCheckInForm.patch(route('admin.room-operations.stays.actual-check-in', editCheckInTarget.value.occupant.stay_id), {
        preserveScroll: true,
        onSuccess: () => { editCheckInTarget.value = null; },
    });
}

const editCheckOutTarget = ref(null); // room
const editCheckOutForm = useForm({ actual_checkout_at: '' });

function openEditCheckOut(room) {
    editCheckOutForm.clearErrors();
    editCheckOutForm.actual_checkout_at = toDatetimeLocal(room.occupant.actual_checkout_at);
    editCheckOutTarget.value = room;
}

function closeEditCheckOut() {
    editCheckOutTarget.value = null;
    editCheckOutForm.clearErrors();
}

function submitEditCheckOut() {
    if (!editCheckOutTarget.value) return;
    editCheckOutForm.patch(route('admin.room-operations.stays.actual-check-out', editCheckOutTarget.value.occupant.stay_id), {
        preserveScroll: true,
        onSuccess: () => { editCheckOutTarget.value = null; },
    });
}

// ---- "Other services" popup (User request, 2026-08-20 chat) ---------------
// Every OTHER active Dịch vụ & Yêu cầu enrollment (bed join/extra bed have
// their own dedicated badge on the tile) — click the tile's summary button
// to see the full list, with a link out to the booking's own Dịch vụ &
// Yêu cầu page (the one place these are actually confirmed/completed/
// cancelled) rather than duplicating that management UI here.
const otherServicesTarget = ref(null); // room

function openOtherServices(room) {
    otherServicesTarget.value = room;
}

function closeOtherServices() {
    otherServicesTarget.value = null;
}

// User request (2026-08-20 chat) — "Phần kiểm đồ trong sơ đồ thao tác cho
// phép ghi một lượt cho nhiều phòng chỉ với 1 kết quả 'xác nhận không phát
// sinh'". Same eligible set as the single-room "Kiểm đồ" toolbar button
// (can_inspect); reuses the exact per-stay draft -> complete(items: [])
// sequence CheckoutInspectionModal.vue's "Xác nhận không phát sinh" button
// already does — complete() is idempotent, so a room whose inspection is
// already Completed is silently skipped (no-op), never overwritten.
// User request (2026-08-20 chat) — a confirmation step before this fires
// ("Bạn muốn xác nhận tất cả các phòng đã chọn đều không phát sinh phải
// không?", Đồng ý / Không xác nhận), since it silently completes inspection
// for every eligible selected room in one click.
const confirmNoChargeDialog = ref(null); // { count }

function openConfirmNoChargeDialog() {
    const targets = selectedRooms.value.filter((r) => r.actions?.can_inspect && r.occupant?.stay_id);
    if (targets.length === 0) return;
    confirmNoChargeDialog.value = { count: targets.length };
}

function closeConfirmNoChargeDialog() {
    confirmNoChargeDialog.value = null;
}

async function bulkConfirmNoCharge() {
    confirmNoChargeDialog.value = null;
    const targets = selectedRooms.value.filter((r) => r.actions?.can_inspect && r.occupant?.stay_id);
    if (targets.length === 0) return;

    const results = await Promise.allSettled(targets.map(async (room) => {
        const draft = await axios.post(route('admin.checkout-inspections.draft', room.occupant.stay_id));
        await axios.post(route('admin.checkout-inspections.complete', draft.data.id), { note: null, items: [] });
    }));

    const failed = results.filter((r) => r.status === 'rejected').length;
    pushToast(
        failed > 0
            ? `Đã xác nhận không phát sinh ${targets.length - failed}/${targets.length} phòng (${failed} lỗi).`
            : `Đã xác nhận không phát sinh cho ${targets.length} phòng.`,
        failed > 0 ? 'error' : 'success',
    );
    clearSelection();
    refresh();
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

// ---- Swap ("Đổi phòng") — Mục IX-XXI, redesigned (User request, 2026-08-20
// chat): pick source rooms via checkbox exactly as before (still the same
// selectedIds mechanism, still gated on actions.can_swap), then pick
// replacement rooms ALSO on the board instead of via a dropdown inside
// SwapRoomDialog.vue. Pairing is by SELECTION ORDER — 1st source picked <->
// 1st replacement picked, 2nd <-> 2nd... numbered badges on the tiles (see
// RoomOperationsCell.vue) make the pairing visible before confirming. Lock
// condition: replacement count must equal source count (checked in
// toggleSwapTarget() and swapTargetsReady below) — room TYPE is never
// checked here, matching the existing backend (a type mismatch is only ever
// a warning, never a blocker — see RoomSwapService::analyzePair()).
//
// The preview/warnings/confirm flow inside SwapRoomDialog.vue is completely
// UNCHANGED — only how its `pairs` input gets built changed (now pre-paired
// from board clicks instead of built from per-row dropdowns).
const swapFlow = ref(null); // { sourceRoomIds: [...], targetRoomIds: [...] } while picking, else null
const swapDialogPairs = ref(null); // set once ready, opens SwapRoomDialog

function openSwapDialog() {
    // Array.from(selectedIds) preserves insertion order (Set iteration order
    // === insertion order in JS) — this IS the "order picked" the pairing
    // needs; selectedRooms (filtered from allRoomsFlat) would give board
    // order instead, which is why this doesn't just reuse that computed.
    const sourceRoomIds = Array.from(selectedIds.value)
        .map((id) => allRoomsFlat.value.find((r) => r.id === id))
        .filter((r) => r?.actions?.can_swap)
        .map((r) => r.id);
    if (sourceRoomIds.length === 0) return;

    swapFlow.value = { sourceRoomIds, targetRoomIds: [] };
    selectedIds.value = new Set();
}

function toggleSwapTarget(roomId) {
    if (!swapFlow.value) return;
    // Same exclusion the old dropdown enforced (targetOptions filtered out
    // every sourceRoom) — a replacement room can never be one of this same
    // batch's own source rooms.
    if (swapFlow.value.sourceRoomIds.includes(roomId)) return;

    const targetRoomIds = [...swapFlow.value.targetRoomIds];
    const idx = targetRoomIds.indexOf(roomId);
    if (idx !== -1) {
        targetRoomIds.splice(idx, 1);
    } else {
        // Lock condition (User request) — never more replacements than sources.
        if (targetRoomIds.length >= swapFlow.value.sourceRoomIds.length) return;
        targetRoomIds.push(roomId);
    }
    swapFlow.value = { ...swapFlow.value, targetRoomIds };
}

const swapTargetsReady = computed(() => swapFlow.value !== null
    && swapFlow.value.sourceRoomIds.length > 0
    && swapFlow.value.targetRoomIds.length === swapFlow.value.sourceRoomIds.length);

function cancelSwapPicking() {
    swapFlow.value = null;
}

function proceedToSwapPreview() {
    if (!swapTargetsReady.value) return;

    swapDialogPairs.value = swapFlow.value.sourceRoomIds.map((sourceRoomId, i) => {
        const sourceRoom = allRoomsFlat.value.find((r) => r.id === sourceRoomId);
        const targetRoomId = swapFlow.value.targetRoomIds[i];
        const targetRoom = allRoomsFlat.value.find((r) => r.id === targetRoomId);
        return {
            source_assignment_id: sourceRoom.occupant.assignment_id,
            target_room_id: targetRoomId,
            source_room_number: sourceRoom.room_number,
            target_room_number: targetRoom?.room_number,
        };
    });
}

function closeSwapDialog() {
    swapDialogPairs.value = null;
}

function onSwapDone() {
    swapDialogPairs.value = null;
    swapFlow.value = null;
    clearSelection();
    pushToast('Đã đổi phòng thành công.');
    refresh();
}
</script>

<template>
    <AppLayout class="no-print">
        <div class="space-y-4 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-lg font-semibold text-gray-900">Sơ đồ thao tác</h1>
                <div class="flex items-center gap-2">
                    <button type="button" class="rounded border border-gray-300 px-2 py-1 text-sm" @click="shiftDay(-1)">‹</button>
                    <input v-model="selectedDate" type="date" class="rounded border border-gray-300 p-1.5 text-sm" />
                    <button type="button" class="rounded border border-gray-300 px-2 py-1 text-sm" @click="shiftDay(1)">›</button>
                    <!-- User request (2026-08-22 chat) — "in sơ đồ thao tác trên
                         trang A4... để buồng có thể căn cứ vào đấy làm việc". -->
                    <button
                        type="button"
                        class="ml-2 rounded border border-gray-300 px-2 py-1 text-sm hover:bg-gray-50"
                        title="In sơ đồ hiện tại ra khổ A4"
                        @click="printBoard"
                    >
                        🖨 In sơ đồ (A4)
                    </button>
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
                <!-- docs/yeucaumoi.txt mục 13 — select every room currently visible
                     under the filters above, in one action. -->
                <button
                    type="button"
                    class="ml-auto inline-flex items-center gap-1 rounded border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50"
                    @click="selectAllVisible"
                >
                    Chọn tất cả đang hiển thị
                </button>
            </div>

            <RoomOperationsBoard
                :floors="filteredFloors"
                :selected-ids="selectedIds"
                :can-edit-note="can.swap"
                :can-adjust-actual-time="can.adjustActualTime"
                :swap-source-room-ids="swapFlow?.sourceRoomIds ?? []"
                :swap-target-room-ids="swapFlow?.targetRoomIds ?? []"
                @toggle-select="toggleSelect"
                @view-booking="viewBooking"
                @save-note="saveNote"
                @select-booking-rooms="selectBookingRooms"
                @edit-check-in="openEditCheckIn"
                @edit-check-out="openEditCheckOut"
                @view-other-services="openOtherServices"
            />

            <div>
                <h2 class="mb-2 text-sm font-semibold text-gray-700">Booking trong ngày</h2>
                <DailyBookingSummary :bookings="dailySummary" :can-view-booking="can.viewBooking" />
            </div>
        </div>

        <RoomOperationsToolbar
            v-if="!swapFlow"
            :selected-rooms="selectedRooms"
            :can="can"
            @swap="openSwapDialog"
            @check-in="bulkCheckIn"
            @check-out="requestCheckOut"
            @inspect="openInspectFromToolbar"
            @confirm-no-charge="openConfirmNoChargeDialog"
            @clean="bulkClean"
            @clear="clearSelection"
        />

        <!-- User request (2026-08-20 chat) — replaces the toolbar while picking
             swap replacement rooms directly on the board. Hidden while the
             preview dialog is open (swapDialogPairs set) so the two never
             stack; closing the dialog via its own X button only clears
             swapDialogPairs, so this reappears with the picks still intact,
             letting the user adjust before re-opening the preview. -->
        <div
            v-if="swapFlow && !swapDialogPairs"
            class="sticky bottom-0 z-10 flex flex-wrap items-center gap-3 rounded-t-lg border border-indigo-300 bg-indigo-50 p-3 shadow-lg"
        >
            <span class="text-sm font-medium text-indigo-900">
                Đang chọn phòng thay thế: {{ swapFlow.targetRoomIds.length }}/{{ swapFlow.sourceRoomIds.length }} —
                bấm vào ô phòng trên sơ đồ theo đúng thứ tự ghép cặp (không được trùng phòng nguồn).
            </span>
            <button
                type="button"
                class="ml-auto inline-flex items-center gap-1 rounded border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50"
                @click="cancelSwapPicking"
            >
                Hủy
            </button>
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded border border-indigo-600 bg-indigo-600 px-2.5 py-1.5 text-xs font-medium text-white disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="!swapTargetsReady"
                @click="proceedToSwapPreview"
            >
                Xem trước &amp; xác nhận
            </button>
        </div>

        <SwapRoomDialog
            v-if="swapDialogPairs"
            :pairs="swapDialogPairs"
            @close="closeSwapDialog"
            @done="onSwapDone"
        />

        <CheckoutFlowDialogs
            :flow="checkoutFlow"
            :can-override-inspection="can.overrideCheckoutInspection"
            @cancel="cancelCheckoutFlow"
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

        <!-- ADMIN: actual check-in time for a bulk check-in, before the POST fires -->
        <div v-if="checkInTimeDialog" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-sm border border-gray-200 bg-white p-5 shadow-xl">
                <h2 class="text-base font-semibold">Nhận phòng — {{ checkInTimeDialog.roomNumbers.length }} phòng</h2>
                <p class="mt-1 text-xs text-steel">Phòng: {{ checkInTimeDialog.roomNumbers.join(', ') }}</p>
                <form class="mt-3" @submit.prevent="submitCheckInTimeDialog">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Thời gian nhận phòng thực tế</label>
                    <input v-model="checkInTimeForm.actual_checkin_at" type="datetime-local" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    <p v-if="checkInTimeForm.errors.actual_checkin_at" class="mt-2 text-sm text-red-600">{{ checkInTimeForm.errors.actual_checkin_at }}</p>
                    <div class="mt-4 flex justify-end gap-2">
                        <button type="button" class="border border-gray-300 px-4 py-2 text-sm font-semibold text-steel hover:text-ink" @click="closeCheckInTimeDialog">Hủy</button>
                        <button type="submit" class="border border-pine bg-pine px-4 py-2 text-sm font-semibold text-white hover:bg-pine/90 disabled:opacity-50" :disabled="checkInTimeForm.processing">
                            Xác nhận nhận phòng
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ADMIN: actual checkout time for a bulk checkout, before the existing inspection/balance/final-confirm flow runs -->
        <div v-if="adminCheckoutTimeDialog" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-sm border border-gray-200 bg-white p-5 shadow-xl">
                <h2 class="text-base font-semibold">Trả phòng — {{ adminCheckoutTimeDialog.rooms.length }} phòng</h2>
                <p class="mt-1 text-xs text-steel">Phòng: {{ adminCheckoutTimeDialog.rooms.map((r) => r.room_number).join(', ') }}</p>
                <form class="mt-3" @submit.prevent="confirmAdminCheckoutTime">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Thời gian trả phòng thực tế</label>
                    <input v-model="adminCheckoutTimeValue" type="datetime-local" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    <p v-if="checkoutTimeError" class="mt-2 text-sm text-red-600">{{ checkoutTimeError }}</p>
                    <div class="mt-4 flex justify-end gap-2">
                        <button type="button" class="border border-gray-300 px-4 py-2 text-sm font-semibold text-steel hover:text-ink" @click="closeAdminCheckoutTimeDialog">Hủy</button>
                        <button type="submit" class="border border-pine bg-pine px-4 py-2 text-sm font-semibold text-white hover:bg-pine/90">Tiếp tục trả phòng</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ADMIN: edit an already-recorded actual check-in time -->
        <div v-if="editCheckInTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-sm border border-gray-200 bg-white p-5 shadow-xl">
                <h2 class="text-base font-semibold">Sửa thời gian nhận phòng — Phòng {{ editCheckInTarget.room_number }}</h2>
                <form class="mt-3" @submit.prevent="submitEditCheckIn">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Thời gian nhận phòng thực tế</label>
                    <input v-model="editCheckInForm.actual_checkin_at" type="datetime-local" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    <p v-if="editCheckInForm.errors.actual_checkin_at" class="mt-2 text-sm text-red-600">{{ editCheckInForm.errors.actual_checkin_at }}</p>
                    <div class="mt-4 flex justify-end gap-2">
                        <button type="button" class="border border-gray-300 px-4 py-2 text-sm font-semibold text-steel hover:text-ink" @click="closeEditCheckIn">Hủy</button>
                        <button type="submit" class="border border-pine bg-pine px-4 py-2 text-sm font-semibold text-white hover:bg-pine/90 disabled:opacity-50" :disabled="editCheckInForm.processing">
                            Lưu thay đổi
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ADMIN: edit an already-recorded actual checkout time -->
        <div v-if="editCheckOutTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-sm border border-gray-200 bg-white p-5 shadow-xl">
                <h2 class="text-base font-semibold">Sửa thời gian trả phòng — Phòng {{ editCheckOutTarget.room_number }}</h2>
                <form class="mt-3" @submit.prevent="submitEditCheckOut">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Thời gian trả phòng thực tế</label>
                    <input v-model="editCheckOutForm.actual_checkout_at" type="datetime-local" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    <p v-if="editCheckOutForm.errors.actual_checkout_at" class="mt-2 text-sm text-red-600">{{ editCheckOutForm.errors.actual_checkout_at }}</p>
                    <div class="mt-4 flex justify-end gap-2">
                        <button type="button" class="border border-gray-300 px-4 py-2 text-sm font-semibold text-steel hover:text-ink" @click="closeEditCheckOut">Hủy</button>
                        <button type="submit" class="border border-pine bg-pine px-4 py-2 text-sm font-semibold text-white hover:bg-pine/90 disabled:opacity-50" :disabled="editCheckOutForm.processing">
                            Lưu thay đổi
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- "Kiểm đồ nhanh: Xác nhận tất cả không phát sinh" confirmation
             (User request, 2026-08-20 chat) -->
        <div v-if="confirmNoChargeDialog" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-sm rounded-lg border border-gray-200 bg-white p-5 shadow-xl">
                <h2 class="text-base font-semibold text-gray-900">Xác nhận không phát sinh</h2>
                <p class="mt-2 text-sm text-gray-600">
                    Bạn muốn xác nhận tất cả các phòng đã chọn ({{ confirmNoChargeDialog.count }} phòng) đều không phát sinh phải không?
                </p>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-900" @click="closeConfirmNoChargeDialog">
                        Không xác nhận
                    </button>
                    <button type="button" class="rounded border border-indigo-600 bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700" @click="bulkConfirmNoCharge">
                        Đồng ý
                    </button>
                </div>
            </div>
        </div>

        <!-- "Yêu cầu & dịch vụ khác" popup (User request, 2026-08-20 chat) -->
        <div v-if="otherServicesTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-md border border-gray-200 bg-white p-5 shadow-xl">
                <h2 class="text-base font-semibold">
                    Yêu cầu &amp; dịch vụ — Phòng {{ otherServicesTarget.room_number }}
                </h2>
                <p v-if="otherServicesTarget.occupant" class="mt-1 text-xs text-steel">
                    {{ otherServicesTarget.occupant.booking_code }} · {{ otherServicesTarget.occupant.customer_name }}
                </p>

                <ul class="mt-3 max-h-80 space-y-2 overflow-y-auto">
                    <li
                        v-for="item in otherServicesTarget.occupant?.other_services ?? []"
                        :key="item.id"
                        class="flex items-start justify-between gap-2 border-b border-gray-100 pb-2 text-sm"
                    >
                        <div>
                            <div class="font-medium text-gray-900">{{ item.name }}</div>
                            <div class="text-xs text-steel">
                                {{ item.category_name }}<template v-if="item.quantity > 1"> · SL {{ item.quantity }}{{ item.unit_label ? ` ${item.unit_label}` : '' }}</template>
                            </div>
                        </div>
                        <span class="shrink-0 text-xs font-medium text-gray-600">{{ item.status_label }}</span>
                    </li>
                    <li v-if="!(otherServicesTarget.occupant?.other_services?.length)" class="text-sm text-steel">
                        Không có yêu cầu/dịch vụ nào khác.
                    </li>
                </ul>

                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" class="border border-gray-300 px-4 py-2 text-sm font-semibold text-steel hover:text-ink" @click="closeOtherServices">
                        Thoát
                    </button>
                    <a
                        v-if="otherServicesTarget.occupant"
                        :href="route('admin.bookings.services.show', otherServicesTarget.occupant.booking_id)"
                        class="border border-pine bg-pine px-4 py-2 text-sm font-semibold text-white hover:bg-pine/90"
                    >
                        Xem
                    </a>
                </div>
            </div>
        </div>

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

    <!-- User request (2026-08-22 chat) — the ONLY thing visible when the
         browser prints (see the plain, non-scoped <style> block below for
         how AppLayout above gets hidden). Fed the SAME filteredFloors the
         screen shows, so print always matches whatever search/status
         filters are currently applied. -->
    <div class="print-only">
        <RoomOperationsPrintView :floors="filteredFloors" :date="selectedDate" />
    </div>
</template>

<style>
/* User request (2026-08-22 chat) — plain (not scoped) because @media print
   and the body-wide hide rule below must reach outside this component's
   own markup to hide AppLayout's nav/sidebar chrome too. Scoped entirely to
   this page via the .no-print class on <AppLayout> above + .print-only
   here — does not affect printing on any other page in the app. */
.print-only {
    display: none;
}

@media print {
    .no-print {
        display: none !important;
    }
    .print-only {
        display: block !important;
    }
    @page {
        size: A4 landscape;
        margin: 10mm;
    }
}
</style>
