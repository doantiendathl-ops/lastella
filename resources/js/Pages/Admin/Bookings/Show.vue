<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { labelFor } from '@/Support/vietnameseLabels';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Banknote, BedDouble, CheckCircle, Eye, LogIn, LogOut, Pencil, Plus, RotateCcw, Trash2, Unlock, X, XCircle } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    booking: { type: Object, required: true },
    activeTab: { type: String, default: 'info' },
    tabs: { type: Array, default: () => [] },
    assignmentSummary: { type: Array, default: () => [] },
    roomBoard: { type: Object, default: () => ({ floors: [] }) },
    options: { type: Object, required: true },
    can: { type: Object, required: true },
});

const tab = ref(props.activeTab);
const editingRequirementId = ref(null);
const showCancelModal = ref(false);
const showAddChargeForm = ref(false);
const showVoidModal = ref(false);
const voidingEntry = ref(null);
const voidReasonInput = ref('');
const selectedRoomIds = ref([]);
const showConflictPanel = ref(false);
const conflictPanelRoom = ref(null);
const showCurrentBookingPanel = ref(false);
const currentBookingPanelRoom = ref(null);
const showAssignmentHistory = ref(false);

const anyModalOpen = computed(() =>
    showConflictPanel.value ||
    showCurrentBookingPanel.value ||
    releaseDialogAssignment.value !== null,
);

const nowLocal = () => {
    const date = new Date();
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 16);
};

const suggestedPriceForRoomType = (roomTypeId) => props.options.roomTypes.find((type) => String(type.value) === String(roomTypeId))?.suggested_price ?? null;

const normalizePrice = (value) => {
    if (value === null || value === undefined || value === '') {
        return 0;
    }

    return Number(value);
};

const defaultRoomPrice = (roomTypeId) => normalizePrice(suggestedPriceForRoomType(roomTypeId));

const applySuggestedPrice = (form) => {
    form.room_price = defaultRoomPrice(form.room_type_id);
};

const formatCurrency = (value) => `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(normalizePrice(value))} đ`;

const emptyRequirement = () => ({
    room_type_id: props.options.roomTypes[0]?.value ?? '',
    quantity: 1,
    adults: 1,
    children_under_6: 0,
    children_over_6: 0,
    room_price: defaultRoomPrice(props.options.roomTypes[0]?.value ?? ''),
    price_source: 'MANUAL',
    note: '',
});

const requirementForm = useForm(emptyRequirement());
const editRequirementForm = useForm(emptyRequirement());
const paymentForm = useForm({
    payment_type: 'DEPOSIT',
    amount: '',
    payment_method: 'CASH',
    payment_at: nowLocal(),
    note: '',
});
const cancelForm = useForm({
    booking_code_confirmation: '',
    cancellation_reason: '',
});
const chargeForm = useForm({
    charge_type: 'OTHER',
    description: '',
    quantity: 1,
    unit_price: 0,
    entry_date: new Date().toISOString().slice(0, 10),
    amount: 0,
});
const assignmentForm = useForm({
    room_ids: [],
    start_at: props.booking.checkin_at,
    end_at: props.booking.checkout_at,
});

const submitRequirement = () => {
    requirementForm.post(`/admin/bookings/${props.booking.id}/requirements`, {
        preserveScroll: true,
        onSuccess: () => requirementForm.defaults(emptyRequirement()).reset(),
    });
};

const startEditRequirement = (requirement) => {
    editingRequirementId.value = requirement.id;
    editRequirementForm.defaults({
        room_type_id: requirement.room_type_id,
        quantity: requirement.quantity,
        adults: requirement.adults,
        children_under_6: requirement.children_under_6,
        children_over_6: requirement.children_over_6,
        room_price: requirement.room_price,
        price_source: requirement.price_source ?? 'MANUAL',
        note: requirement.note ?? '',
    }).reset();
};

const updateRequirement = (requirement) => {
    editRequirementForm.put(`/admin/bookings/${props.booking.id}/requirements/${requirement.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editingRequirementId.value = null;
        },
    });
};

const deleteRequirement = (requirement) => {
    if (!window.confirm('Xóa nhu cầu phòng này?')) {
        return;
    }

    router.delete(`/admin/bookings/${props.booking.id}/requirements/${requirement.id}`, { preserveScroll: true });
};

const submitPayment = () => {
    paymentForm.post(`/admin/bookings/${props.booking.id}/payments`, {
        preserveScroll: true,
        onSuccess: () => paymentForm.defaults({
            payment_type: 'DEPOSIT',
            amount: '',
            payment_method: 'CASH',
            payment_at: nowLocal(),
            note: '',
        }).reset(),
    });
};

const allBoardRooms = computed(() => props.roomBoard.floors?.flatMap((floor) => floor.rooms ?? []) ?? []);

const roomById = computed(() => new Map(allBoardRooms.value.map((room) => [Number(room.id), room])));

const selectedRooms = computed(() => selectedRoomIds.value
    .map((roomId) => roomById.value.get(Number(roomId)))
    .filter(Boolean));

const selectedCountForRoomType = (roomTypeId) => selectedRooms.value
    .filter((room) => Number(room.room_type_id) === Number(roomTypeId))
    .length;

const buildSummaryCard = (item) => {
    const selected = selectedCountForRoomType(item.room_type_id);
    const required = Number(item.required);
    const assigned = Number(item.assigned);
    const total = assigned + selected;
    const diff = total - required;

    let status, statusLabel;
    if (required === 0 && selected > 0) {
        status = 'extra';
        statusLabel = 'Chọn ngoài nhu cầu';
    } else if (diff < 0) {
        status = 'missing';
        statusLabel = `Thiếu ${Math.abs(diff)}`;
    } else if (diff === 0) {
        status = 'complete';
        statusLabel = 'Đủ';
    } else {
        status = 'over';
        statusLabel = `Thừa ${diff}`;
    }

    return {
        ...item,
        selected,
        total,
        remaining_after_selection: Math.max(required - total, 0),
        status,
        statusLabel,
    };
};

const assignmentSummaryWithSelection = computed(() => {
    const fromRequirements = props.assignmentSummary.map(buildSummaryCard);
    const requirementTypeIds = new Set(fromRequirements.map((i) => Number(i.room_type_id)));

    const extraTypeIds = [...new Set(
        selectedRooms.value
            .filter((r) => !requirementTypeIds.has(Number(r.room_type_id)))
            .map((r) => Number(r.room_type_id)),
    )];

    const extraCards = extraTypeIds.map((typeId) => {
        const room = selectedRooms.value.find((r) => Number(r.room_type_id) === typeId);
        return buildSummaryCard({
            room_type_id: typeId,
            room_type_code: room?.room_type ?? '???',
            required: 0,
            assigned: 0,
            remaining: 0,
        });
    });

    return [...fromRequirements, ...extraCards];
});

const hasExtraSelection = computed(() => assignmentSummaryWithSelection.value.some((i) => i.status === 'extra'));
const extraSelectionDetails = computed(() => assignmentSummaryWithSelection.value.filter((i) => i.status === 'extra'));

const existingRemainingRooms = computed(() => props.assignmentSummary.reduce(
    (total, item) => total + Math.max(Number(item.required) - Number(item.assigned), 0),
    0,
));

const hasAssignmentShortage = computed(() => assignmentSummaryWithSelection.value.some((item) => item.remaining_after_selection > 0));
const hasAssignmentOverage = computed(() => selectedRoomIds.value.length > existingRemainingRooms.value);

const buildRoomTypeSummaryWithSelection = (summaryList) => summaryList.map((summary) => {
    const pendingCount = selectedRooms.value.filter((r) => Number(r.room_type_id) === Number(summary.room_type_id)).length;
    const selected = summary.current_booking + pendingCount;
    const remaining = Math.max(summary.remaining - pendingCount, 0);

    let badge = '🟢';
    if (remaining === 0) badge = '🔴';
    else if (summary.total > 0 && remaining / summary.total <= 0.3) badge = '🟡';

    const badgeText = { '🟢': 'Còn nhiều', '🟡': 'Sắp hết', '🔴': 'Hết phòng' }[badge];

    return { ...summary, selected, remaining, badge, badgeText };
});

const roomTypeSummaryDisplay = computed(() => {
    if (!props.roomBoard.room_type_summary?.length) return [];
    return buildRoomTypeSummaryWithSelection(props.roomBoard.room_type_summary);
});

const allRoomTypeSummaryDisplay = computed(() => {
    if (!props.roomBoard.all_room_type_summary?.length) return [];
    return buildRoomTypeSummaryWithSelection(props.roomBoard.all_room_type_summary);
});

const requiredRoomTypeIds = computed(() => new Set(
    (props.roomBoard.room_type_summary ?? []).map((s) => Number(s.room_type_id)),
));

const availabilityInRequirement = computed(() =>
    allRoomTypeSummaryDisplay.value.filter((s) => requiredRoomTypeIds.value.has(Number(s.room_type_id))),
);

const availabilityOutsideRequirement = computed(() =>
    allRoomTypeSummaryDisplay.value.filter((s) => !requiredRoomTypeIds.value.has(Number(s.room_type_id))),
);

const guestAllocation = computed(() => {
    const requirements = props.booking.requirements ?? [];
    const allocAdults = requirements.reduce((s, r) => s + (r.adults ?? 0), 0);
    const allocUnder6 = requirements.reduce((s, r) => s + (r.children_under_6 ?? 0), 0);
    const allocOver6 = requirements.reduce((s, r) => s + (r.children_over_6 ?? 0), 0);

    const adults = { total: props.booking.adults ?? 0, allocated: allocAdults, remaining: (props.booking.adults ?? 0) - allocAdults };
    const childrenUnder6 = { total: props.booking.children_under_6 ?? 0, allocated: allocUnder6, remaining: (props.booking.children_under_6 ?? 0) - allocUnder6 };
    const childrenOver6 = { total: props.booking.children_over_6 ?? 0, allocated: allocOver6, remaining: (props.booking.children_over_6 ?? 0) - allocOver6 };

    const isOverAllocated = adults.remaining < 0 || childrenUnder6.remaining < 0 || childrenOver6.remaining < 0;
    const isFullyAllocated = !isOverAllocated && adults.remaining === 0 && childrenUnder6.remaining === 0 && childrenOver6.remaining === 0;

    return { adults, childrenUnder6, childrenOver6, isOverAllocated, isFullyAllocated };
});

const isRoomSelected = (room) => selectedRoomIds.value.includes(Number(room.id));

const canSelectRoom = (room) => room.availability_status === 'available';

const shortRoomTypeCode = (room) => ({
    TWIN: 'TWN',
    DOUBLE: 'DBL',
    TRIP: 'TRP',
    FAMILY: 'FAM',
    TRIP_FAMILY: 'TFM',
}[room.room_type] ?? room.room_type);

const availabilityLabel = (room) => ({
    available: 'Có thể chọn',
    conflict: 'Đã có booking khác',
    unavailable: 'Không khả dụng',
    current_booking: 'Đã phân cho booking này',
}[room.availability_status] ?? 'Không xác định');

const openConflictPanel = (room) => {
    conflictPanelRoom.value = room;
    showConflictPanel.value = true;
};

const closeConflictPanel = () => {
    showConflictPanel.value = false;
    conflictPanelRoom.value = null;
};

const unassignConflictRoom = () => {
    if (!conflictPanelRoom.value?.conflict_booking?.assignment_id) {
        return;
    }

    const assignmentId = conflictPanelRoom.value.conflict_booking.assignment_id;
    closeConflictPanel();
    openReleaseDialog({ assignment_id: assignmentId }, true);
};

const openCurrentBookingPanel = (room) => {
    currentBookingPanelRoom.value = room;
    showCurrentBookingPanel.value = true;
};

const closeCurrentBookingPanel = () => {
    showCurrentBookingPanel.value = false;
    currentBookingPanelRoom.value = null;
};

const releaseCurrentBookingAssignment = () => {
    if (!currentBookingPanelRoom.value?.current_assignment?.assignment_id) {
        return;
    }

    const assignmentId = currentBookingPanelRoom.value.current_assignment.assignment_id;
    closeCurrentBookingPanel();
    openReleaseDialog({ assignment_id: assignmentId }, false);
};

const toggleRoomSelection = (room) => {
    if (room.availability_status === 'conflict') {
        openConflictPanel(room);

        return;
    }

    if (room.availability_status === 'current_booking') {
        openCurrentBookingPanel(room);

        return;
    }

    if (!canSelectRoom(room)) {
        return;
    }

    const roomId = Number(room.id);

    if (isRoomSelected(room)) {
        selectedRoomIds.value = selectedRoomIds.value.filter((selectedRoomId) => selectedRoomId !== roomId);

        return;
    }

    selectedRoomIds.value = [...selectedRoomIds.value, roomId];
};

const roomCardClass = (room) => {
    if (isRoomSelected(room)) {
        return 'border-transparent text-white shadow-sm ring-2 ring-pine/20 ring-offset-1';
    }

    if (room.availability_status === 'conflict') {
        return 'cursor-pointer border-gray-200 bg-gray-100';
    }

    if (room.availability_status === 'current_booking') {
        return 'cursor-pointer border-transparent text-ink shadow-sm';
    }

    if (room.availability_status === 'unavailable') {
        return 'cursor-not-allowed border-gray-200 bg-gray-100 text-gray-400';
    }

    if (!room.matches_requirement) {
        return 'border-amber-300 bg-amber-50 text-ink hover:border-amber-400 hover:shadow-sm';
    }

    return 'border-gray-200 bg-white text-ink hover:border-pine hover:shadow-sm';
};

const hexToRgb = (hex) => {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    return `${r}, ${g}, ${b}`;
};

const roomCardStyle = (room) => {
    const color = props.booking.booking_color;
    if (isRoomSelected(room)) {
        return { backgroundColor: color, borderColor: color };
    }
    if (room.availability_status === 'current_booking' && color) {
        const rgb = hexToRgb(color);
        return { backgroundColor: `rgba(${rgb}, 0.15)`, borderColor: `rgba(${rgb}, 0.4)` };
    }
    return {};
};

const roomStatusDotClass = (room) => {
    if (isRoomSelected(room)) {
        return 'bg-white';
    }

    if (room.availability_status === 'current_booking') {
        return 'bg-pine';
    }

    if (room.availability_status === 'conflict') {
        return 'bg-gray-400';
    }

    if (room.availability_status === 'unavailable') {
        return 'bg-coral';
    }

    if (!room.matches_requirement) {
        return 'bg-amber-500';
    }

    return 'bg-pine';
};

const submitAssignment = () => {
    if (hasExtraSelection.value) {
        const details = extraSelectionDetails.value
            .map((i) => `${i.room_type_code}: Yêu cầu 0, Đang chọn ${i.selected}`)
            .join('\n');
        if (!window.confirm(`Bạn đang chọn phòng ngoài nhu cầu booking.\n\n${details}\n\nBạn có muốn tiếp tục lưu không?`)) {
            return;
        }
    } else if (hasAssignmentOverage.value) {
        if (!window.confirm('Bạn đã chọn vượt số lượng phòng yêu cầu. Vẫn tiếp tục lưu phân phòng?')) {
            return;
        }
    }

    assignmentForm.room_ids = selectedRoomIds.value;
    assignmentForm.post(`/admin/bookings/${props.booking.id}/assignments`, {
        preserveScroll: true,
        onSuccess: () => {
            selectedRoomIds.value = [];
            assignmentForm.reset('room_ids');
        },
    });
};

const releaseDialogAssignment = ref(null);
const releaseDialogIsConflict = ref(false);
const releaseForm = useForm({ release_reason: '' });
const releaseConfirmed = ref(false);

const openReleaseDialog = (assignment, isConflict = false) => {
    releaseDialogAssignment.value = assignment;
    releaseDialogIsConflict.value = isConflict;
    releaseForm.release_reason = '';
    releaseConfirmed.value = false;
};

const closeReleaseDialog = () => {
    releaseDialogAssignment.value = null;
    releaseDialogIsConflict.value = false;
    releaseForm.release_reason = '';
    releaseConfirmed.value = false;
};

const confirmRelease = () => {
    if (!releaseDialogAssignment.value || releaseConfirmed.value || releaseForm.processing) return;
    releaseConfirmed.value = true;
    const assignmentId = releaseDialogAssignment.value.id ?? releaseDialogAssignment.value.assignment_id;
    const url = releaseDialogIsConflict.value
        ? `/admin/bookings/${props.booking.id}/room-board/conflict/${assignmentId}/release`
        : `/admin/bookings/${props.booking.id}/assignments/${assignmentId}/release`;
    releaseForm.post(url, {
        preserveScroll: true,
        onSuccess: closeReleaseDialog,
        onError: () => { releaseConfirmed.value = false; },
    });
};

const releaseAssignment = (assignment) => {
    openReleaseDialog(assignment);
};

const handleGlobalEsc = (event) => {
    if (event.key === 'Escape' && releaseDialogAssignment.value) {
        closeReleaseDialog();
        event.stopPropagation();
    }
};

onMounted(() => document.addEventListener('keydown', handleGlobalEsc));
onBeforeUnmount(() => document.removeEventListener('keydown', handleGlobalEsc));

const checkIn = (stay) => router.post(`/admin/bookings/${props.booking.id}/stays/${stay.id}/check-in`, {}, { preserveScroll: true });

const checkoutWarningStay = ref(null);

const checkOut = (stay) => {
    const balance = props.booking.payment_summary?.balance_due ?? 0;
    if (balance > 0) {
        checkoutWarningStay.value = stay;
        return;
    }
    router.post(`/admin/bookings/${props.booking.id}/stays/${stay.id}/check-out`, {}, { preserveScroll: true });
};

const releaseAll = () => {
    const releasable = props.booking.assignments.filter((a) => !a.is_released && a.can_release);
    if (!releasable.length) return;
    if (!window.confirm(`Giải phóng tất cả ${releasable.length} phân phòng?`)) return;
    const doNext = (i) => {
        if (i >= releasable.length) return;
        router.post(
            `/admin/bookings/${props.booking.id}/assignments/${releasable[i].id}/release`,
            { release_reason: '' },
            { preserveScroll: true, onSuccess: () => doNext(i + 1) },
        );
    };
    doNext(0);
};

const checkInAll = () => {
    const stays = activeStays.value.filter((s) => s.can_check_in);
    if (!stays.length) return;
    const doNext = (i) => {
        if (i >= stays.length) return;
        router.post(
            `/admin/bookings/${props.booking.id}/stays/${stays[i].id}/check-in`,
            {},
            { preserveScroll: true, onSuccess: () => doNext(i + 1) },
        );
    };
    doNext(0);
};

const checkOutAll = () => {
    const stays = activeStays.value.filter((s) => s.can_check_out);
    if (!stays.length) return;
    const balance = props.booking.payment_summary?.balance_due ?? 0;
    if (balance > 0 && !window.confirm(`Booking còn nợ. Vẫn trả tất cả phòng?`)) return;
    const doNext = (i) => {
        if (i >= stays.length) return;
        router.post(
            `/admin/bookings/${props.booking.id}/stays/${stays[i].id}/check-out`,
            {},
            { preserveScroll: true, onSuccess: () => doNext(i + 1) },
        );
    };
    doNext(0);
};

const closeCheckoutWarning = () => {
    checkoutWarningStay.value = null;
};

const confirmCheckoutWithWarning = () => {
    if (!checkoutWarningStay.value) return;
    const stayId = checkoutWarningStay.value.id;
    checkoutWarningStay.value = null;
    router.post(`/admin/bookings/${props.booking.id}/stays/${stayId}/check-out`, {}, { preserveScroll: true });
};

const canConfirmCancel = computed(() => cancelForm.booking_code_confirmation === props.booking.booking_code
    && cancelForm.cancellation_reason.trim().length > 0
    && !cancelForm.processing);

const openCancelModal = () => {
    showCancelModal.value = true;
    cancelForm.clearErrors();
    cancelForm.defaults({
        booking_code_confirmation: '',
        cancellation_reason: '',
    }).reset();
};

const closeCancelModal = () => {
    showCancelModal.value = false;
    cancelForm.clearErrors();
    cancelForm.reset();
};

const submitCancel = () => {
    if (!canConfirmCancel.value) {
        return;
    }

    cancelForm.post(`/admin/bookings/${props.booking.id}/cancel`, {
        preserveScroll: true,
        onSuccess: closeCancelModal,
    });
};

const restoreBooking = () => router.post(`/admin/bookings/${props.booking.id}/restore`, {}, { preserveScroll: true });

const deletePayment = (payment) => {
    if (!window.confirm('Bạn có chắc muốn xóa giao dịch này? Hành động không thể hoàn tác.')) {
        return;
    }

    router.delete(`/admin/bookings/${props.booking.id}/payments/${payment.id}`, { preserveScroll: true });
};

const visibleAssignments = computed(() => {
    if (showAssignmentHistory.value) return props.booking.assignments;
    return props.booking.assignments.filter((a) => !a.is_released);
});

const activeStays = computed(() => (props.booking.stays ?? []).filter((stay) => stay.status !== 'CANCELLED' && !stay.is_released));

const roomTypeCapacity = {
    DOUBLE: { adults: 2, childrenUnder6: 2 },
    TWIN: { adults: 2, childrenUnder6: 2 },
    TRIP: { adults: 3, childrenUnder6: 2 },
    TRIP_FAMILY: { adults: 3, childrenUnder6: 2 },
    FAMILY: { adults: 4, childrenUnder6: 3 },
};

const capacityWarning = computed(() => {
    const requirements = props.booking.requirements ?? [];
    if (requirements.length === 0) return null;

    let totalAdultCapacity = 0;
    let totalChildUnder6Capacity = 0;

    for (const req of requirements) {
        const code = req.room_type ?? '';
        const cap = roomTypeCapacity[code] ?? { adults: 2, childrenUnder6: 2 };
        const qty = req.quantity ?? 0;
        totalAdultCapacity += cap.adults * qty;
        totalChildUnder6Capacity += cap.childrenUnder6 * qty;
    }

    const bookingAdults = (props.booking.adults ?? 0) + (props.booking.children_over_6 ?? 0);
    const bookingChildrenUnder6 = props.booking.children_under_6 ?? 0;

    const adultRemaining = totalAdultCapacity - bookingAdults;
    const childRemaining = totalChildUnder6Capacity - bookingChildrenUnder6;
    const isSufficient = adultRemaining >= 0 && childRemaining >= 0;

    return {
        requiredAdults: bookingAdults,
        requiredChildrenUnder6: bookingChildrenUnder6,
        assignedAdultCapacity: totalAdultCapacity,
        assignedChildCapacity: totalChildUnder6Capacity,
        adultRemaining,
        childRemaining,
        isSufficient,
    };
});

const chargeBreakdown = computed(() => {
    const entries = props.booking.folio?.entries ?? [];
    const totals = {};
    for (const entry of entries) {
        if (entry.is_voided) continue;
        const key = entry.charge_type_label ?? entry.charge_type;
        totals[key] = (totals[key] ?? 0) + Number(entry.amount);
    }
    return Object.entries(totals).map(([label, amount]) => ({ label, amount }));
});

const roomChargeBreakdown = computed(() => {
    const lines = (props.booking.requirements ?? [])
        .filter((r) => Number(r.quantity) > 0)
        .map((r) => ({
            room_type: r.room_type ?? '—',
            quantity: Number(r.quantity),
            unit_price: Number(r.room_price),
            subtotal: Number(r.room_price) * Number(r.quantity),
        }));
    const total = lines.reduce((sum, l) => sum + l.subtotal, 0);
    return { lines, total };
});

const chargeFormTotal = computed(() => (parseFloat(chargeForm.quantity) || 0) * (parseFloat(chargeForm.unit_price) || 0));

const submitCharge = () => {
    chargeForm.amount = chargeFormTotal.value;
    chargeForm.post(`/admin/bookings/${props.booking.id}/folio/entries`, {
        preserveScroll: true,
        onSuccess: () => {
            showAddChargeForm.value = false;
            chargeForm.reset();
        },
    });
};

const openVoidModal = (entry) => {
    voidingEntry.value = entry;
    voidReasonInput.value = '';
    showVoidModal.value = true;
};

const closeVoidModal = () => {
    showVoidModal.value = false;
    voidingEntry.value = null;
    voidReasonInput.value = '';
};

const confirmVoid = () => {
    if (!voidingEntry.value || voidReasonInput.value.trim().length < 5) return;
    router.patch(
        `/admin/bookings/${props.booking.id}/folio/entries/${voidingEntry.value.id}`,
        { void_reason: voidReasonInput.value },
        { preserveScroll: true, onSuccess: closeVoidModal },
    );
};

const closeFolio = () => {
    if (!window.confirm('Đóng folio này? Sau khi đóng, không thể thêm phí mới.')) return;
    router.patch(`/admin/bookings/${props.booking.id}/folio/close`, {}, { preserveScroll: true });
};

const reopenFolio = () => {
    if (!window.confirm('Mở lại folio này?')) return;
    router.patch(`/admin/bookings/${props.booking.id}/folio/reopen`, {}, { preserveScroll: true });
};

const folioStatusLabel = (status) => ({ OPEN: 'Đang mở', CLOSED: 'Đã đóng', VOIDED: 'Đã hủy' }[status] ?? status);

const tabClass = (key) => tab.value === key ? 'border-pine text-pine' : 'border-transparent text-steel hover:text-ink';
</script>

<template>
    <Head :title="booking.booking_code" />

    <AppLayout>
        <template #header>
            <div class="flex min-w-0 items-center justify-between gap-4">
                <div class="min-w-0">
                    <h1 class="truncate text-lg font-semibold">{{ booking.booking_code }}</h1>
                    <p class="truncate text-sm text-steel">{{ booking.customer_name }} - {{ labelFor('bookingStatus', booking.status) }}</p>
                </div>
                <div class="flex gap-2">
                    <Link v-if="can.editBooking" :href="`/admin/bookings/${booking.id}/edit`" class="inline-flex items-center gap-2 border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-steel hover:text-ink">
                        <Pencil class="h-4 w-4" />
                        Sửa
                    </Link>
                    <span
                        v-else-if="can.updateBooking"
                        class="inline-flex"
                        :title="can.editDisabledReason"
                        :aria-label="can.editDisabledReason"
                    >
                        <button
                            type="button"
                            class="inline-flex cursor-not-allowed items-center gap-2 border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-300"
                            disabled
                        >
                            <Pencil class="h-4 w-4" />
                            Sửa
                        </button>
                    </span>
                    <button v-if="can.restoreBooking" type="button" class="inline-flex items-center gap-2 border border-pine px-3 py-2 text-sm font-semibold text-pine hover:bg-pine hover:text-white" @click="restoreBooking">
                        <RotateCcw class="h-4 w-4" />
                        Khôi phục booking
                    </button>
                    <button v-if="can.cancelBookingNormally" type="button" class="inline-flex items-center gap-2 border border-coral px-3 py-2 text-sm font-semibold text-coral hover:bg-coral hover:text-white" @click="openCancelModal">
                        <XCircle class="h-4 w-4" />
                        Hủy booking
                    </button>
                    <span
                        v-else-if="can.cancelBooking && booking.status !== 'CANCELLED' && can.cancelDisabledReason"
                        class="inline-flex"
                        :title="can.cancelDisabledReason"
                        :aria-label="can.cancelDisabledReason"
                    >
                        <button type="button" class="inline-flex cursor-not-allowed items-center gap-2 border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-300" disabled>
                            <XCircle class="h-4 w-4" />
                            Hủy booking
                        </button>
                    </span>
                </div>
            </div>
        </template>

        <section class="border border-gray-200 bg-white shadow-sm">
            <div class="flex overflow-x-auto border-b border-gray-200 px-4">
                <button
                    v-for="item in tabs"
                    :key="item.key"
                    type="button"
                    class="whitespace-nowrap border-b-2 px-4 py-3 text-sm font-semibold"
                    :class="tabClass(item.key)"
                    @click="tab = item.key"
                >
                    {{ item.label }}
                </button>
            </div>

            <div v-if="tab === 'info'" class="grid gap-3 p-5 md:grid-cols-3 xl:grid-cols-5">
                <div class="border border-gray-100 p-4">
                    <div class="text-xs uppercase tracking-wide text-steel">Khách hàng</div>
                    <div class="mt-2 text-sm font-semibold">{{ booking.customer_name }}</div>
                    <div class="mt-1 text-sm text-steel">{{ booking.customer_phone }}</div>
                    <div class="mt-1 text-sm text-steel">{{ booking.customer_email }}</div>
                    <div class="mt-1 text-sm text-steel">{{ labelFor('customerType', booking.customer_type) }}</div>
                </div>
                <div class="border border-gray-100 p-4">
                    <div class="text-xs uppercase tracking-wide text-steel">Lưu trú</div>
                    <div class="mt-2 text-sm">Loại: {{ labelFor('bookingType', booking.booking_type) }}</div>
                    <div class="mt-1 text-sm">Nhận phòng: {{ booking.checkin_at }}</div>
                    <div class="mt-1 text-sm">Trả phòng: {{ booking.checkout_at }}</div>
                </div>
                <div class="border border-gray-100 p-4">
                    <div class="text-xs uppercase tracking-wide text-steel">Số khách</div>
                    <div class="mt-2 space-y-1 text-sm">
                        <div>Người lớn: {{ booking.adults }}</div>
                        <div>Trẻ dưới 6: {{ booking.children_under_6 }}</div>
                        <div>Trẻ từ 6: {{ booking.children_over_6 }}</div>
                    </div>
                </div>
                <div class="border border-gray-100 p-4">
                    <div class="text-xs uppercase tracking-wide text-steel">Trạng thái</div>
                    <div class="mt-2 text-sm font-semibold">{{ labelFor('bookingStatus', booking.status) }}</div>
                    <div class="mt-2 inline-flex h-6 w-12 border border-gray-200" :style="{ backgroundColor: booking.booking_color }" />
                    <div class="mt-2 text-sm text-steel">Kinh doanh: {{ booking.sales_user ?? 'Chưa phân công' }}</div>
                </div>
                <div class="border border-gray-100 p-4 md:col-span-3 xl:col-span-5">
                    <div class="text-xs uppercase tracking-wide text-steel">Ghi chú</div>
                    <div class="mt-2 whitespace-pre-line text-sm">{{ booking.note }}</div>
                    <div class="mt-3 whitespace-pre-line text-sm text-steel">{{ booking.internal_note }}</div>
                </div>
            </div>

            <div v-if="tab === 'info'" class="space-y-5 p-5">
                <!-- Capacity Warning Panel -->
                <div v-if="capacityWarning" class="border p-4" :class="capacityWarning.isSufficient ? 'border-pine/30 bg-pine/5' : 'border-coral/30 bg-coral/5'">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold" :class="capacityWarning.isSufficient ? 'text-pine' : 'text-coral'">
                            {{ capacityWarning.isSufficient ? 'Đủ sức chứa' : 'Thiếu sức chứa' }}
                        </span>
                    </div>
                    <div class="mt-2 grid gap-2 text-sm sm:grid-cols-3">
                        <div>
                            <span class="text-xs text-steel">Người lớn + Trẻ từ 6 tuổi</span>
                            <div class="mt-0.5">Cần: {{ capacityWarning.requiredAdults }} · Sức chứa: {{ capacityWarning.assignedAdultCapacity }} · <span :class="capacityWarning.adultRemaining >= 0 ? 'text-pine' : 'text-coral'" class="font-semibold">Còn: {{ capacityWarning.adultRemaining }}</span></div>
                        </div>
                        <div>
                            <span class="text-xs text-steel">Trẻ dưới 6 tuổi</span>
                            <div class="mt-0.5">Cần: {{ capacityWarning.requiredChildrenUnder6 }} · Sức chứa: {{ capacityWarning.assignedChildCapacity }} · <span :class="capacityWarning.childRemaining >= 0 ? 'text-pine' : 'text-coral'" class="font-semibold">Còn: {{ capacityWarning.childRemaining }}</span></div>
                        </div>
                    </div>
                </div>

                <div v-if="allRoomTypeSummaryDisplay.length" class="border border-gray-100 p-4">
                    <div class="mb-3 text-xs font-semibold uppercase tracking-wide text-steel">Tình trạng loại phòng</div>
                    <div class="divide-y divide-gray-100">
                        <div
                            v-for="item in allRoomTypeSummaryDisplay"
                            :key="item.room_type_id"
                            class="py-2.5 first:pt-0 last:pb-0"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <span class="min-w-0 truncate text-sm font-semibold">{{ item.room_type_code }}</span>
                                <span
                                    class="shrink-0 text-xs font-semibold"
                                    :class="item.remaining === 0 ? 'text-coral' : item.badge === '🟡' ? 'text-amber-600' : 'text-pine'"
                                >{{ item.badge }} {{ item.badgeText }}</span>
                            </div>
                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                <span class="inline-flex items-center gap-1 border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs text-steel">
                                    Tổng: <span class="font-medium text-ink">{{ item.total }}</span>
                                </span>
                                <span class="inline-flex items-center gap-1 border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs text-steel">
                                    Đã giữ: <span class="font-medium text-ink">{{ item.occupied }}</span>
                                </span>
                                <span class="inline-flex items-center gap-1 border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs text-steel">
                                    Đã chọn: <span class="font-medium text-ink">{{ item.selected }}</span>
                                </span>
                                <span
                                    class="inline-flex items-center gap-1 border px-2 py-0.5 text-xs font-semibold"
                                    :class="item.remaining === 0 ? 'border-coral/30 bg-coral/5 text-coral' : item.badge === '🟡' ? 'border-amber-200 bg-amber-50 text-amber-700' : 'border-pine/20 bg-pine/5 text-pine'"
                                >Còn lại: {{ item.remaining }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <form v-if="can.updateBooking" class="flex flex-wrap items-end gap-3 border border-gray-100 p-4" @submit.prevent="submitRequirement">
                    <div class="min-w-[140px] flex-1">
                        <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Loại phòng</label>
                        <select v-model="requirementForm.room_type_id" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" @change="applySuggestedPrice(requirementForm)">
                            <option v-for="type in options.roomTypes" :key="type.value" :value="type.value">{{ type.label }}</option>
                        </select>
                    </div>
                    <div class="w-20">
                        <label class="block text-xs font-semibold uppercase tracking-wide text-steel">SL</label>
                        <input v-model="requirementForm.quantity" type="number" min="1" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                    </div>
                    <div class="w-28">
                        <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Giá phòng</label>
                        <input v-model="requirementForm.room_price" type="number" min="0" step="0.01" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                    </div>
                    <div class="w-28">
                        <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Nguồn giá</label>
                        <select v-model="requirementForm.price_source" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                            <option v-for="source in options.priceSources" :key="source.value" :value="source.value">{{ labelFor('priceSource', source.value) }}</option>
                        </select>
                    </div>
                    <div class="min-w-[100px] flex-1">
                        <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Ghi chú</label>
                        <input v-model="requirementForm.note" type="text" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                    </div>
                    <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 bg-pine px-3 py-2 text-sm font-semibold text-white">
                        <Plus class="h-4 w-4" />
                        Thêm
                    </button>
                    <p v-if="Object.keys(requirementForm.errors).length" class="w-full text-sm text-coral">{{ Object.values(requirementForm.errors)[0] }}</p>
                </form>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-steel">
                            <tr>
                                <th class="px-4 py-3">Loại phòng</th>
                                <th class="px-4 py-3">Số lượng</th>
                                <th class="px-4 py-3">Giá phòng</th>
                                <th class="px-4 py-3">Nguồn giá</th>
                                <th class="px-4 py-3">Ghi chú</th>
                                <th v-if="can.updateBooking" class="px-4 py-3 text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="requirement in booking.requirements" :key="requirement.id">
                                <template v-if="editingRequirementId === requirement.id">
                                    <td class="px-4 py-3"><select v-model="editRequirementForm.room_type_id" class="w-full border border-gray-300 px-2 py-1" @change="applySuggestedPrice(editRequirementForm)"><option v-for="type in options.roomTypes" :key="type.value" :value="type.value">{{ type.label }}</option></select></td>
                                    <td class="px-4 py-3"><input v-model="editRequirementForm.quantity" type="number" min="1" class="w-20 border border-gray-300 px-2 py-1"></td>
                                    <td class="px-4 py-3"><input v-model="editRequirementForm.room_price" type="number" min="0" step="0.01" class="w-28 border border-gray-300 px-2 py-1"></td>
                                    <td class="px-4 py-3"><select v-model="editRequirementForm.price_source" class="border border-gray-300 px-2 py-1"><option v-for="source in options.priceSources" :key="source.value" :value="source.value">{{ labelFor('priceSource', source.value) }}</option></select></td>
                                    <td class="px-4 py-3"><input v-model="editRequirementForm.note" type="text" class="w-full border border-gray-300 px-2 py-1"></td>
                                    <td class="px-4 py-3 text-right">
                                        <button type="button" class="mr-2 bg-pine px-3 py-1 text-xs font-semibold text-white" @click="updateRequirement(requirement)">Lưu</button>
                                        <button type="button" class="border border-gray-300 px-3 py-1 text-xs" @click="editingRequirementId = null">Hủy</button>
                                    </td>
                                </template>
                                <template v-else>
                                    <td class="whitespace-nowrap px-4 py-3">{{ requirement.room_type }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ requirement.quantity }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ formatCurrency(requirement.room_price) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ labelFor('priceSource', requirement.price_source) }}</td>
                                    <td class="px-4 py-3">{{ requirement.note }}</td>
                                    <td v-if="can.updateBooking" class="whitespace-nowrap px-4 py-3 text-right">
                                        <button type="button" class="mr-2 inline-flex h-8 w-8 items-center justify-center border border-gray-200 text-steel hover:border-pine hover:text-pine" @click="startEditRequirement(requirement)"><Pencil class="h-4 w-4" /></button>
                                        <button type="button" class="inline-flex h-8 w-8 items-center justify-center border border-gray-200 text-steel hover:border-coral hover:text-coral" @click="deleteRequirement(requirement)"><Trash2 class="h-4 w-4" /></button>
                                    </td>
                                </template>
                            </tr>
                            <tr v-if="booking.requirements.length === 0">
                                <td :colspan="can.updateBooking ? 6 : 5" class="px-4 py-10 text-center text-sm text-steel">Chưa có nhu cầu phòng.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="tab === 'payments'" class="space-y-5 p-5">
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
                            <div class="mt-3 flex gap-4">
                                <button type="button" class="text-xs font-semibold text-amber-800 underline underline-offset-2" @click="tab = 'room_map'">Xem sơ đồ phòng</button>
                                <button type="button" class="text-xs font-semibold text-amber-800 underline underline-offset-2" @click="tab = 'info'">Xem thông tin booking</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Financial Summary Cards -->
                <div class="grid gap-3 md:grid-cols-3">
                    <div class="border border-gray-100 p-4">
                        <div class="text-xs uppercase tracking-wide text-steel">Tổng phí phát sinh</div>
                        <div class="mt-2 text-lg font-semibold">{{ formatCurrency(booking.payment_summary.total_charges) }}</div>
                    </div>
                    <div class="border border-gray-100 p-4">
                        <div class="text-xs uppercase tracking-wide text-steel">Đã thu</div>
                        <div class="mt-2 text-lg font-semibold">{{ formatCurrency(booking.payment_summary.paid_total) }}</div>
                    </div>
                    <div class="border border-gray-100 p-4">
                        <div class="text-xs uppercase tracking-wide text-steel">Còn lại</div>
                        <div class="mt-2 text-lg font-semibold" :class="booking.payment_summary.balance_due > 0 ? 'text-coral' : 'text-pine'">{{ formatCurrency(booking.payment_summary.balance_due) }}</div>
                    </div>
                </div>

                <!-- Room charge breakdown table -->
                <div v-if="roomChargeBreakdown.lines.length" class="border border-gray-100">
                    <div class="border-b border-gray-100 bg-gray-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-steel">Tiền phòng</div>
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 text-xs uppercase tracking-wide text-steel">
                                <th class="px-4 py-2 text-left font-medium">Loại phòng</th>
                                <th class="px-4 py-2 text-right font-medium">Đơn giá</th>
                                <th class="px-4 py-2 text-right font-medium">Số phòng</th>
                                <th class="px-4 py-2 text-right font-medium">Thành tiền</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="line in roomChargeBreakdown.lines" :key="line.room_type">
                                <td class="px-4 py-2 font-medium">{{ line.room_type }}</td>
                                <td class="px-4 py-2 text-right text-steel">{{ formatCurrency(line.unit_price) }}</td>
                                <td class="px-4 py-2 text-right text-steel">{{ line.quantity }}</td>
                                <td class="px-4 py-2 text-right font-semibold">{{ formatCurrency(line.subtotal) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-gray-200 bg-gray-50">
                                <td colspan="3" class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-steel">Tổng tiền phòng</td>
                                <td class="px-4 py-2 text-right text-base font-bold text-ink">{{ formatCurrency(roomChargeBreakdown.total) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Charge type breakdown chips -->
                <div v-if="chargeBreakdown.length" class="flex flex-wrap gap-2 border border-gray-100 p-3">
                    <span v-for="item in chargeBreakdown" :key="item.label" class="inline-flex items-center gap-1.5 border border-gray-200 bg-gray-50 px-2 py-1 text-xs">
                        <span class="font-medium">{{ item.label }}</span>
                        <span class="text-steel">{{ formatCurrency(item.amount) }}</span>
                    </span>
                </div>

                <!-- Payment type breakdown row -->
                <div class="grid grid-cols-2 gap-2 border border-gray-100 p-3 text-sm md:grid-cols-4">
                    <div>
                        <div class="text-xs text-steel">Đặt cọc</div>
                        <div class="mt-1 font-semibold">{{ formatCurrency(booking.payment_summary.total_deposit) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-steel">Thanh toán</div>
                        <div class="mt-1 font-semibold">{{ formatCurrency(booking.payment_summary.total_payment) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-steel">Hoàn tiền</div>
                        <div class="mt-1 font-semibold" :class="booking.payment_summary.total_refund > 0 ? 'text-coral' : ''">{{ booking.payment_summary.total_refund > 0 ? '−' : '' }}{{ formatCurrency(booking.payment_summary.total_refund) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-steel">Điều chỉnh</div>
                        <div class="mt-1 font-semibold">{{ formatCurrency(booking.payment_summary.total_adjustment) }}</div>
                    </div>
                </div>

                <!-- Charges section -->
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-steel">Phí phát sinh</h3>
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

                    <!-- Add Charge inline form -->
                    <form v-if="showAddChargeForm && can.createCharge" class="grid gap-3 border border-gray-100 p-4 md:grid-cols-5" @submit.prevent="submitCharge">
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Loại phí</label>
                            <select v-model="chargeForm.charge_type" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                                <option v-for="type in options.chargeTypes" :key="type.value" :value="type.value">{{ type.label }}</option>
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Mô tả</label>
                            <input v-model="chargeForm.description" type="text" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Số lượng</label>
                            <input v-model="chargeForm.quantity" type="number" min="0.01" step="0.01" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Đơn giá</label>
                            <input v-model="chargeForm.unit_price" type="number" min="0" step="0.01" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Ngày phát sinh</label>
                            <input v-model="chargeForm.entry_date" type="date" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                        </div>
                        <div class="flex items-end gap-2">
                            <div class="mr-auto text-sm">
                                <div class="text-xs text-steel">Thành tiền</div>
                                <div class="font-semibold">{{ formatCurrency(chargeFormTotal) }}</div>
                            </div>
                            <button type="button" class="border border-gray-300 px-3 py-2 text-sm font-semibold text-steel hover:text-ink" @click="showAddChargeForm = false">Hủy</button>
                            <button type="submit" class="inline-flex items-center gap-2 bg-pine px-3 py-2 text-sm font-semibold text-white disabled:bg-gray-300" :disabled="chargeForm.processing">
                                <Plus class="h-4 w-4" />
                                Thêm
                            </button>
                        </div>
                        <p v-if="Object.keys(chargeForm.errors).length" class="md:col-span-5 text-sm text-coral">{{ Object.values(chargeForm.errors)[0] }}</p>
                    </form>

                    <!-- Folio entries table -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-steel">
                                <tr>
                                    <th class="px-4 py-3">Ngày</th>
                                    <th class="px-4 py-3">Loại phí</th>
                                    <th class="px-4 py-3">Mô tả</th>
                                    <th class="px-4 py-3">SL</th>
                                    <th class="px-4 py-3">Đơn giá</th>
                                    <th class="px-4 py-3">Thành tiền</th>
                                    <th class="px-4 py-3">Người đăng</th>
                                    <th v-if="can.voidCharge" class="px-4 py-3 text-right">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="entry in (booking.folio?.entries ?? [])" :key="entry.id" :class="entry.is_voided ? 'bg-gray-50' : ''">
                                    <td class="whitespace-nowrap px-4 py-3" :class="entry.is_voided ? 'text-gray-400 line-through' : ''">{{ entry.entry_date }}</td>
                                    <td class="whitespace-nowrap px-4 py-3" :class="entry.is_voided ? 'text-gray-400 line-through' : ''">{{ entry.charge_type_label }}</td>
                                    <td class="px-4 py-3" :class="entry.is_voided ? 'text-gray-400 line-through' : ''">{{ entry.description }}</td>
                                    <td class="whitespace-nowrap px-4 py-3" :class="entry.is_voided ? 'text-gray-400 line-through' : ''">{{ entry.quantity }}</td>
                                    <td class="whitespace-nowrap px-4 py-3" :class="entry.is_voided ? 'text-gray-400 line-through' : ''">{{ formatCurrency(entry.unit_price) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3" :class="entry.is_voided ? 'text-gray-400 line-through' : ''">{{ formatCurrency(entry.amount) }}</td>
                                    <td class="px-4 py-3">
                                        <template v-if="entry.is_voided">
                                            <span class="inline-flex items-center border border-gray-200 bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-400">VOIDED</span>
                                            <div v-if="entry.voided_by" class="mt-0.5 text-xs text-gray-400">{{ entry.voided_by }}{{ entry.void_reason ? ' · ' + entry.void_reason : '' }}</div>
                                        </template>
                                        <template v-else>{{ entry.posted_by }}</template>
                                    </td>
                                    <td v-if="can.voidCharge" class="whitespace-nowrap px-4 py-3 text-right">
                                        <button
                                            v-if="entry.can_void"
                                            type="button"
                                            class="inline-flex h-8 items-center gap-1 border border-gray-200 px-2 text-xs font-semibold text-steel hover:border-coral hover:text-coral"
                                            @click="openVoidModal(entry)"
                                        >
                                            <XCircle class="h-3.5 w-3.5" />
                                            Hủy
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="!(booking.folio?.entries ?? []).length">
                                    <td :colspan="can.voidCharge ? 8 : 7" class="px-4 py-10 text-center text-sm text-steel">Chưa có phí phát sinh.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Folio metadata + status controls -->
                    <div v-if="booking.folio" class="flex flex-wrap items-center justify-between gap-2 border border-gray-100 px-3 py-2 text-sm">
                        <div class="text-steel">
                            Folio <span class="font-mono font-medium text-ink">{{ booking.folio.folio_number }}</span>
                            · Trạng thái:
                            <span class="font-medium" :class="booking.folio.status === 'OPEN' ? 'text-pine' : 'text-steel'">{{ folioStatusLabel(booking.folio.status) }}</span>
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

                <!-- Payments section -->
                <div class="space-y-3">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-steel">Thanh toán</h3>

                    <form v-if="can.addPayment" class="grid gap-3 border border-gray-100 p-4 md:grid-cols-5" @submit.prevent="submitPayment">
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Loại thanh toán</label>
                            <select v-model="paymentForm.payment_type" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                                <option v-for="type in options.paymentTypes" :key="type.value" :value="type.value">{{ labelFor('paymentType', type.value) }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Số tiền</label>
                            <input v-model="paymentForm.amount" type="number" min="0.01" step="0.01" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Phương thức</label>
                            <select v-model="paymentForm.payment_method" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                                <option v-for="method in options.paymentMethods" :key="method.value" :value="method.value">{{ labelFor('paymentMethod', method.value) }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Thời gian thanh toán</label>
                            <input v-model="paymentForm.payment_at" type="datetime-local" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Ghi chú</label>
                            <input v-model="paymentForm.note" type="text" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                        </div>
                        <button type="submit" class="inline-flex items-center justify-center gap-2 bg-pine px-3 py-2 text-sm font-semibold text-white">
                            <Banknote class="h-4 w-4" />
                            Thêm thanh toán
                        </button>
                        <p v-if="Object.keys(paymentForm.errors).length" class="md:col-span-5 text-sm text-coral">{{ Object.values(paymentForm.errors)[0] }}</p>
                    </form>

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
                                    <th v-if="can.deletePayment" class="px-4 py-3 text-right">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="payment in booking.payments" :key="payment.id">
                                    <td class="px-4 py-3">{{ labelFor('paymentType', payment.payment_type) }}</td>
                                    <td class="px-4 py-3" :class="payment.payment_type === 'REFUND' ? 'text-coral' : ''">{{ payment.payment_type === 'REFUND' ? '−' : '' }}{{ formatCurrency(payment.amount) }}</td>
                                    <td class="px-4 py-3">{{ labelFor('paymentMethod', payment.payment_method) }}</td>
                                    <td class="px-4 py-3">{{ payment.payment_at }}</td>
                                    <td class="px-4 py-3">{{ payment.confirmed_by }}</td>
                                    <td class="px-4 py-3">{{ payment.note }}</td>
                                    <td v-if="can.deletePayment" class="px-4 py-3 text-right">
                                        <button v-if="payment.can_delete" type="button" class="inline-flex h-8 w-8 items-center justify-center border border-gray-200 text-steel hover:border-coral hover:text-coral" :title="'Xóa giao dịch'" @click="deletePayment(payment)">
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="booking.payments.length === 0">
                                    <td :colspan="can.deletePayment ? 7 : 6" class="px-4 py-10 text-center text-sm text-steel">Chưa có thanh toán.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div v-if="tab === 'room_map'" class="space-y-5 p-5">
                <div v-if="assignmentSummaryWithSelection.length" class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                    <div
                        v-for="item in assignmentSummaryWithSelection"
                        :key="item.room_type_id"
                        class="border px-3 py-2 transition-colors duration-150"
                        :class="{
                            'border-green-300 bg-green-50/50': item.status === 'complete',
                            'border-red-300 bg-red-50/50': item.status === 'missing',
                            'border-orange-300 bg-orange-50/50': item.status === 'over' || item.status === 'extra',
                        }"
                    >
                        <div class="flex items-center justify-between gap-1">
                            <span class="text-xs font-semibold">{{ item.room_type_code }}</span>
                            <span
                                class="inline-flex items-center gap-0.5 px-1.5 py-0.5 text-[9px] font-bold leading-none"
                                :class="{
                                    'bg-green-100 text-green-800': item.status === 'complete',
                                    'bg-red-100 text-red-800': item.status === 'missing',
                                    'bg-orange-100 text-orange-800': item.status === 'over' || item.status === 'extra',
                                }"
                            >
                                <span v-if="item.status === 'complete'">🟢</span>
                                <span v-else-if="item.status === 'missing'">🔴</span>
                                <span v-else>🟠</span>
                                {{ item.statusLabel }}
                            </span>
                        </div>
                        <div
                            class="mt-1.5 text-center text-2xl font-bold leading-none transition-all duration-150"
                            :class="{
                                'text-green-700': item.status === 'complete',
                                'text-red-700': item.status === 'missing',
                                'text-orange-700': item.status === 'over' || item.status === 'extra',
                            }"
                        >
                            {{ item.total }} / {{ item.required }}
                        </div>
                        <div class="mt-1 text-center text-[10px] text-steel">
                            Phân {{ item.assigned }} · Chọn {{ item.selected }}
                        </div>
                    </div>
                </div>

                <div v-if="allRoomTypeSummaryDisplay.length">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-steel">Tình trạng loại phòng</div>
                    <div class="flex flex-wrap">
                        <div v-if="availabilityInRequirement.length" class="flex flex-col gap-1.5 pr-3">
                            <div class="text-[10px] font-bold uppercase tracking-widest text-steel">Trong nhu cầu</div>
                            <div class="flex flex-wrap gap-1.5">
                                <div
                                    v-for="item in availabilityInRequirement"
                                    :key="item.room_type_id"
                                    class="w-44 border px-2 py-2 transition-colors duration-150"
                                    :class="{
                                        'border-green-300 bg-green-50/50': item.badge === '🟢',
                                        'border-amber-300 bg-amber-50/50': item.badge === '🟡',
                                        'border-red-300 bg-red-50/50': item.badge === '🔴',
                                    }"
                                >
                                    <div class="flex items-center justify-between gap-1">
                                        <span class="min-w-0 flex-1 truncate text-xs font-semibold" :title="item.room_type_code">{{ item.room_type_code }}</span>
                                        <span
                                            class="shrink-0 inline-flex items-center px-1 py-0.5 text-[9px] font-bold leading-none"
                                            :class="{
                                                'bg-green-100 text-green-800': item.badge === '🟢',
                                                'bg-amber-100 text-amber-800': item.badge === '🟡',
                                                'bg-red-100 text-red-800': item.badge === '🔴',
                                            }"
                                        >{{ item.badgeText }}</span>
                                    </div>
                                    <div
                                        class="mt-1.5 text-center text-2xl font-bold leading-none transition-all duration-150"
                                        :class="{
                                            'text-green-700': item.badge === '🟢',
                                            'text-amber-600': item.badge === '🟡',
                                            'text-red-700': item.badge === '🔴',
                                        }"
                                    >
                                        {{ item.remaining }} / {{ item.total }}
                                    </div>
                                    <div class="mt-1 text-center text-[10px] text-steel">
                                        Trống {{ item.remaining }} • Giữ {{ item.occupied }}<template v-if="item.maintenance"> • Bảo trì {{ item.maintenance }}</template>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="availabilityInRequirement.length && availabilityOutsideRequirement.length"
                            class="hidden lg:block w-px bg-gray-200"
                        ></div>

                        <div
                            v-if="availabilityOutsideRequirement.length"
                            class="flex flex-col gap-1.5 pl-3"
                            :class="availabilityInRequirement.length ? 'w-full border-t border-gray-100 pt-3 mt-2 lg:w-auto lg:border-t-0 lg:pt-0 lg:mt-0' : ''"
                        >
                            <div class="text-[10px] font-bold uppercase tracking-widest text-steel">Ngoài nhu cầu</div>
                            <div class="flex flex-wrap gap-1.5">
                                <div
                                    v-for="item in availabilityOutsideRequirement"
                                    :key="item.room_type_id"
                                    class="w-44 border px-2 py-2 transition-colors duration-150"
                                    :class="{
                                        'border-green-300 bg-green-50/50': item.badge === '🟢',
                                        'border-amber-300 bg-amber-50/50': item.badge === '🟡',
                                        'border-red-300 bg-red-50/50': item.badge === '🔴',
                                    }"
                                >
                                    <div class="flex items-center justify-between gap-1">
                                        <span class="min-w-0 flex-1 truncate text-xs font-semibold" :title="item.room_type_code">{{ item.room_type_code }}</span>
                                        <span
                                            class="shrink-0 inline-flex items-center px-1 py-0.5 text-[9px] font-bold leading-none"
                                            :class="{
                                                'bg-green-100 text-green-800': item.badge === '🟢',
                                                'bg-amber-100 text-amber-800': item.badge === '🟡',
                                                'bg-red-100 text-red-800': item.badge === '🔴',
                                            }"
                                        >{{ item.badgeText }}</span>
                                    </div>
                                    <div
                                        class="mt-1.5 text-center text-2xl font-bold leading-none transition-all duration-150"
                                        :class="{
                                            'text-green-700': item.badge === '🟢',
                                            'text-amber-600': item.badge === '🟡',
                                            'text-red-700': item.badge === '🔴',
                                        }"
                                    >
                                        {{ item.remaining }} / {{ item.total }}
                                    </div>
                                    <div class="mt-1 text-center text-[10px] text-steel">
                                        Trống {{ item.remaining }} • Giữ {{ item.occupied }}<template v-if="item.maintenance"> • Bảo trì {{ item.maintenance }}</template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <form v-if="can.assignRoom" class="space-y-4 border border-gray-100 p-4" @submit.prevent="submitAssignment">
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <div class="text-sm font-semibold">Sơ đồ phòng</div>
                            <div class="mt-1 text-sm text-steel">{{ booking.checkin_at }} - {{ booking.checkout_at }}</div>
                        </div>
                        <button type="submit" class="inline-flex items-center justify-center gap-2 bg-pine px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-gray-300" :disabled="selectedRoomIds.length === 0 || assignmentForm.processing">
                            <BedDouble class="h-4 w-4" />
                            Lưu phân phòng
                        </button>
                    </div>

                    <p v-if="Object.keys(assignmentForm.errors).length" class="text-sm text-coral">{{ Object.values(assignmentForm.errors)[0] }}</p>

                    <div class="space-y-1.5">
                        <section v-for="floor in roomBoard.floors" :key="floor.id" class="flex flex-nowrap items-center gap-2">
                            <div class="shrink-0 w-16 border-r border-gray-100 pr-2">
                                <div class="text-sm font-semibold leading-tight">{{ floor.code === 'B1' ? 'B1' : `Tầng ${floor.code}` }}</div>
                                <div class="text-[10px] text-steel">{{ floor.rooms.length }} phòng</div>
                            </div>
                            <div
                                v-for="room in floor.rooms"
                                    :key="room.id"
                                    role="button"
                                    class="group relative shrink-0 h-16 w-20 cursor-pointer border px-2 py-1.5 text-center text-xs transition focus:outline-none focus:ring-2 focus:ring-pine/40"
                                    :class="roomCardClass(room)"
                                    :style="roomCardStyle(room)"
                                    :aria-disabled="!canSelectRoom(room) && room.availability_status !== 'conflict' && room.availability_status !== 'current_booking'"
                                    :tabindex="canSelectRoom(room) || room.availability_status === 'conflict' || room.availability_status === 'current_booking' ? 0 : -1"
                                    @click="toggleRoomSelection(room)"
                                    @keydown.enter="toggleRoomSelection(room)"
                                    @keydown.space.prevent="toggleRoomSelection(room)"
                                >
                                    <!-- Conflict booking color strip -->
                                    <div
                                        v-if="room.availability_status === 'conflict' && room.conflict_booking?.booking_color"
                                        class="absolute left-0 right-0 top-0 h-[5px]"
                                        :style="{ backgroundColor: room.conflict_booking.booking_color }"
                                    />

                                    <div
                                        class="flex h-full flex-col items-center justify-center gap-0.5"
                                        :class="room.availability_status === 'conflict' ? 'opacity-45 text-gray-600' : ''"
                                    >
                                        <div class="text-base font-semibold leading-none">{{ room.room_number }}</div>
                                        <div class="max-w-full truncate text-[10px] font-semibold uppercase leading-tight">{{ shortRoomTypeCode(room) }}</div>
                                        <span class="mt-0.5 h-2 w-2 rounded-full" :class="roomStatusDotClass(room)" />
                                    </div>

                                    <div
                                        class="absolute left-1/2 top-full z-30 mt-2 w-64 -translate-x-1/2 border border-gray-200 bg-gray-950 p-3 text-left text-xs leading-relaxed text-white shadow-xl"
                                        :class="anyModalOpen ? 'hidden' : 'hidden group-hover:block group-focus:block'"
                                    >
                                        <div class="font-semibold">{{ room.room_number }} - {{ room.room_type_name ?? room.room_type }}</div>
                                        <dl class="mt-2 space-y-1">
                                            <div class="flex justify-between gap-3">
                                                <dt class="text-gray-300">Trạng thái phòng</dt>
                                                <dd class="text-right font-medium">{{ room.status_label }}</dd>
                                            </div>
                                            <div class="flex justify-between gap-3">
                                                <dt class="text-gray-300">Khả dụng</dt>
                                                <dd class="text-right font-medium">{{ availabilityLabel(room) }}</dd>
                                            </div>
                                            <div v-if="room.assignment_detail" class="mt-2 border-t border-white/10 pt-2">
                                                <div class="flex justify-between gap-3">
                                                    <dt class="text-gray-300">Mã booking</dt>
                                                    <dd class="text-right font-medium">{{ room.assignment_detail.booking_code }}</dd>
                                                </div>
                                                <div class="flex justify-between gap-3">
                                                    <dt class="text-gray-300">Khách hàng</dt>
                                                    <dd class="text-right font-medium">{{ room.assignment_detail.customer_name }}</dd>
                                                </div>
                                                <div class="flex justify-between gap-3">
                                                    <dt class="text-gray-300">Nhận phòng</dt>
                                                    <dd class="text-right font-medium">{{ room.assignment_detail.checkin_at }}</dd>
                                                </div>
                                                <div class="flex justify-between gap-3">
                                                    <dt class="text-gray-300">Trả phòng</dt>
                                                    <dd class="text-right font-medium">{{ room.assignment_detail.checkout_at }}</dd>
                                                </div>
                                                <div class="flex justify-between gap-3">
                                                    <dt class="text-gray-300">Phân phòng</dt>
                                                    <dd class="text-right font-medium">{{ labelFor('assignmentStatus', room.assignment_detail.status) }}</dd>
                                                </div>
                                                <div v-if="room.conflict_booking" class="flex justify-between gap-3">
                                                    <dt class="text-gray-300">Trạng thái booking</dt>
                                                    <dd class="text-right font-medium">{{ labelFor('bookingStatus', room.conflict_booking.status) }}</dd>
                                                </div>
                                            </div>
                                        </dl>
                                        <div v-if="room.disabled_reason" class="mt-2 border-t border-white/10 pt-2 font-semibold">
                                            {{ room.disabled_reason }}
                                        </div>
                                        <div v-if="room.conflict_booking?.lock_reason || room.current_assignment?.lock_reason" class="mt-2 text-amber-300">
                                            {{ room.conflict_booking?.lock_reason ?? room.current_assignment?.lock_reason }}
                                        </div>
                                        <div v-if="!room.matches_requirement" class="mt-2 text-amber-200">
                                            Không đúng loại phòng yêu cầu
                                        </div>
                                        <div v-if="isRoomSelected(room)" class="mt-2 text-emerald-200">
                                            Đã chọn cho booking hiện tại
                                        </div>
                                        <div v-if="room.conflict_booking?.can_view && room.conflict_booking?.id" class="pointer-events-auto mt-2 border-t border-white/10 pt-2">
                                            <Link
                                                :href="`/admin/bookings/${room.conflict_booking.id}`"
                                                class="inline-flex items-center gap-1 bg-white/10 px-2 py-1 text-[10px] font-semibold text-white hover:bg-white/20"
                                                @click.stop
                                            >
                                                <Eye class="h-3 w-3" /> Xem booking
                                            </Link>
                                        </div>
                                        <div v-if="room.info_booking" class="mt-2 border-t border-white/10 pt-2">
                                            <div class="font-semibold">{{ room.info_booking.booking_code }} · {{ room.info_booking.customer_name }}</div>
                                            <div class="text-gray-300">{{ room.info_booking.checkin_at }} → {{ room.info_booking.checkout_at }}</div>
                                            <div class="mt-1 text-amber-200">Phòng có booking ở thời điểm khác, không ảnh hưởng tới khoảng thời gian hiện tại.</div>
                                        </div>
                                    </div>
                                </div>
                        </section>
                    </div>
                </form>

                <div class="mt-2 flex items-center justify-between border-b-2 border-pine/30 pb-2 pt-4">
                    <div class="flex items-center gap-2">
                        <div class="h-5 w-1 bg-pine"></div>
                        <span class="text-sm font-bold uppercase tracking-wide">Phân phòng</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <button
                            v-if="can.releaseRoom && booking.assignments.some(a => !a.is_released && a.can_release)"
                            type="button"
                            class="inline-flex items-center gap-1 border border-gray-300 px-3 py-1 text-xs font-semibold text-steel hover:border-coral hover:text-coral"
                            @click="releaseAll"
                        >Giải phóng tất cả</button>
                        <label class="flex cursor-pointer items-center gap-2 text-xs text-steel">
                            <input v-model="showAssignmentHistory" type="checkbox" class="h-3.5 w-3.5 accent-pine" />
                            Hiển thị lịch sử phân phòng
                        </label>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-steel">
                            <tr><th class="px-4 py-3">Phòng</th><th class="px-4 py-3">Loại</th><th class="px-4 py-3">Bắt đầu</th><th class="px-4 py-3">Kết thúc</th><th class="px-4 py-3">Trạng thái</th><th class="px-4 py-3">Phân bởi</th><th class="px-4 py-3">Giải phóng lúc</th><th class="px-4 py-3">Lý do</th><th class="px-4 py-3 text-right">Thao tác</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="assignment in visibleAssignments" :key="assignment.id" :class="assignment.is_released ? 'opacity-60' : ''">
                                <td class="px-4 py-3">{{ assignment.room_number }}</td>
                                <td class="px-4 py-3">{{ assignment.room_type }}</td>
                                <td class="px-4 py-3">{{ assignment.start_at }}</td>
                                <td class="px-4 py-3">{{ assignment.end_at }}</td>
                                <td class="px-4 py-3">
                                    <span :class="assignment.is_released ? 'text-steel' : assignment.is_checked_out ? 'text-pine' : assignment.is_checked_in ? 'text-amber-600' : ''">
                                        {{ labelFor('assignmentStatus', assignment.status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">{{ assignment.assigned_by }}</td>
                                <td class="px-4 py-3">{{ assignment.released_at }}</td>
                                <td class="px-4 py-3">{{ assignment.release_reason }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <template v-if="assignment.is_released">
                                        <span class="text-xs text-steel">Đã giải phóng</span>
                                    </template>
                                    <template v-else-if="assignment.is_checked_out">
                                        <CheckCircle class="ml-auto h-4 w-4 text-pine" />
                                    </template>
                                    <template v-else-if="assignment.is_checked_in">
                                        <span class="text-xs text-amber-600">Đang lưu trú</span>
                                    </template>
                                    <template v-else>
                                        <button v-if="can.releaseRoom && assignment.can_release" type="button" class="inline-flex items-center gap-1 border border-gray-300 px-3 py-1 text-xs font-semibold text-steel hover:border-coral hover:text-coral" @click="releaseAssignment(assignment)">Giải phóng</button>
                                    </template>
                                </td>
                            </tr>
                            <tr v-if="visibleAssignments.length === 0"><td colspan="9" class="px-4 py-10 text-center text-sm text-steel">{{ booking.assignments.length > 0 ? 'Không có phân phòng đang hoạt động. Bật "Hiển thị lịch sử" để xem tất cả.' : 'Chưa có phân phòng.' }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="tab === 'room_map'" class="p-5">
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
                        ><LogIn class="h-3.5 w-3.5" /> Nhận tất cả phòng</button>
                        <button
                            v-if="can.checkOut && activeStays.some(s => s.can_check_out)"
                            type="button"
                            class="inline-flex items-center gap-1.5 border border-gray-300 px-3 py-1 text-xs font-semibold text-steel hover:border-pine hover:text-pine"
                            @click="checkOutAll"
                        ><LogOut class="h-3.5 w-3.5" /> Trả tất cả phòng</button>
                    </div>
                </div>
                <div class="mt-3 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-steel">
                        <tr><th class="px-4 py-3">Phòng</th><th class="px-4 py-3">Dự kiến nhận phòng</th><th class="px-4 py-3">Dự kiến trả phòng</th><th class="px-4 py-3">Thực nhận phòng</th><th class="px-4 py-3">Thực trả phòng</th><th class="px-4 py-3">Trạng thái</th><th class="px-4 py-3 text-right">Thao tác</th></tr>
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
                                <button v-if="can.checkIn && stay.can_check_in" type="button" class="mr-2 inline-flex items-center gap-2 border border-gray-300 px-3 py-1 text-xs font-semibold text-steel hover:border-pine hover:text-pine" @click="checkIn(stay)"><LogIn class="h-3.5 w-3.5" /> Nhận phòng</button>
                                <span v-else-if="can.checkIn && stay.checkin_too_early" class="mr-2 text-[10px] text-amber-600" :title="`Thời gian nhận phòng dự kiến: ${stay.planned_checkin_label}`">Chưa đến giờ nhận phòng</span>
                                <button v-if="can.checkOut && stay.can_check_out" type="button" class="inline-flex items-center gap-2 border border-gray-300 px-3 py-1 text-xs font-semibold text-steel hover:border-pine hover:text-pine" @click="checkOut(stay)"><LogOut class="h-3.5 w-3.5" /> Trả phòng</button>
                                <CheckCircle v-if="stay.status === 'CHECKED_OUT'" class="ml-auto h-4 w-4 text-pine" />
                            </td>
                        </tr>
                        <tr v-if="activeStays.length === 0"><td colspan="7" class="px-4 py-10 text-center text-sm text-steel">Chưa có lưu trú đang hoạt động.</td></tr>
                    </tbody>
                </table>
                </div>
            </div>

            <div v-if="tab === 'history'" class="p-5 text-sm text-steel">
                Lịch sử booking sẽ được hiển thị ở giai đoạn sau.
            </div>
        </section>

        <!-- Conflict Room Panel -->
        <div v-if="showConflictPanel && conflictPanelRoom" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-md border border-gray-200 bg-white p-5 shadow-xl">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold">Chi tiết xung đột phòng</h2>
                        <p class="mt-1 text-sm text-steel">Phòng này đang được chiếm bởi một booking khác.</p>
                    </div>
                    <button type="button" class="text-steel hover:text-ink" @click="closeConflictPanel">
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div class="mt-4 space-y-3">
                    <div class="border border-gray-100 p-3">
                        <div class="text-xs uppercase tracking-wide text-steel">Phòng</div>
                        <div class="mt-1 text-sm font-semibold">{{ conflictPanelRoom.room_number }} — {{ conflictPanelRoom.room_type_name ?? conflictPanelRoom.room_type }}</div>
                    </div>

                    <div v-if="conflictPanelRoom.conflict_booking" class="border border-gray-100 p-3">
                        <div class="flex items-center gap-2">
                            <div
                                v-if="conflictPanelRoom.conflict_booking.booking_color"
                                class="h-3 w-3 flex-shrink-0 rounded-full"
                                :style="{ backgroundColor: conflictPanelRoom.conflict_booking.booking_color }"
                            />
                            <div class="text-xs uppercase tracking-wide text-steel">Booking đang chiếm phòng</div>
                        </div>
                        <dl class="mt-2 space-y-1.5 text-sm">
                            <div class="flex justify-between gap-3">
                                <dt class="text-steel">Mã booking</dt>
                                <dd class="font-medium">{{ conflictPanelRoom.conflict_booking.code }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-steel">Khách hàng</dt>
                                <dd class="font-medium">{{ conflictPanelRoom.conflict_booking.customer_name }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-steel">Nhận phòng</dt>
                                <dd class="font-medium">{{ conflictPanelRoom.assignment_detail?.checkin_at }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-steel">Trả phòng</dt>
                                <dd class="font-medium">{{ conflictPanelRoom.assignment_detail?.checkout_at }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-steel">Phân phòng</dt>
                                <dd class="font-medium">{{ labelFor('assignmentStatus', conflictPanelRoom.assignment_detail?.status) }}</dd>
                            </div>
                            <div v-if="conflictPanelRoom.conflict_booking.status" class="flex justify-between gap-3">
                                <dt class="text-steel">Trạng thái booking</dt>
                                <dd class="font-medium">{{ labelFor('bookingStatus', conflictPanelRoom.conflict_booking.status) }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <div v-if="conflictPanelRoom.conflict_booking?.is_assignment_locked" class="mt-4 border border-amber-200 bg-amber-50 p-2 text-xs text-amber-800">
                    Phòng đã nhận phòng thực tế. Vui lòng trả phòng trước khi giải phóng.
                </div>

                <div class="mt-5 flex flex-col gap-2 sm:flex-row sm:justify-end">
                    <button type="button" class="border border-gray-300 px-3 py-2 text-sm font-semibold text-steel hover:text-ink" @click="closeConflictPanel">
                        Đóng
                    </button>
                    <Link
                        v-if="conflictPanelRoom.conflict_booking?.can_view && conflictPanelRoom.conflict_booking?.id"
                        :href="`/admin/bookings/${conflictPanelRoom.conflict_booking.id}`"
                        class="inline-flex items-center justify-center gap-2 border border-pine px-3 py-2 text-sm font-semibold text-pine hover:bg-pine hover:text-white"
                    >
                        Xem chi tiết booking
                    </Link>
                    <button
                        v-if="conflictPanelRoom.conflict_booking?.can_unassign_room && conflictPanelRoom.conflict_booking?.assignment_id"
                        type="button"
                        class="inline-flex items-center justify-center gap-2 bg-coral px-3 py-2 text-sm font-semibold text-white hover:opacity-90"
                        @click="unassignConflictRoom"
                    >
                        Gỡ phòng
                    </button>
                </div>
            </div>
        </div>

        <!-- Current Booking Assignment Panel -->
        <div v-if="showCurrentBookingPanel && currentBookingPanelRoom" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-md border border-gray-200 bg-white p-5 shadow-xl">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold">Phòng đã phân cho booking này</h2>
                        <p class="mt-1 text-sm text-steel">Chi tiết phân phòng của booking hiện tại.</p>
                    </div>
                    <button type="button" class="text-steel hover:text-ink" @click="closeCurrentBookingPanel">
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div class="mt-4 space-y-3">
                    <div class="border border-gray-100 p-3">
                        <div class="text-xs uppercase tracking-wide text-steel">Phòng</div>
                        <div class="mt-1 text-sm font-semibold">{{ currentBookingPanelRoom.room_number }} — {{ currentBookingPanelRoom.room_type_name ?? currentBookingPanelRoom.room_type }}</div>
                    </div>

                    <div v-if="currentBookingPanelRoom.current_assignment" class="border border-gray-100 p-3">
                        <div class="text-xs uppercase tracking-wide text-steel">Phân phòng</div>
                        <dl class="mt-2 space-y-1.5 text-sm">
                            <div class="flex justify-between gap-3">
                                <dt class="text-steel">Mã booking</dt>
                                <dd class="font-medium">{{ booking.booking_code }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-steel">Khách hàng</dt>
                                <dd class="font-medium">{{ booking.customer_name }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-steel">Nhận phòng</dt>
                                <dd class="font-medium">{{ currentBookingPanelRoom.assignment_detail?.checkin_at }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-steel">Trả phòng</dt>
                                <dd class="font-medium">{{ currentBookingPanelRoom.assignment_detail?.checkout_at }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-steel">Trạng thái phân phòng</dt>
                                <dd class="font-medium">{{ labelFor('assignmentStatus', currentBookingPanelRoom.current_assignment.assignment_status) }}</dd>
                            </div>
                        </dl>

                        <div v-if="currentBookingPanelRoom.current_assignment.is_assignment_locked" class="mt-3 border border-amber-200 bg-amber-50 p-2 text-xs text-amber-800">
                            {{ currentBookingPanelRoom.current_assignment.lock_reason }}
                        </div>
                    </div>
                </div>

                <div class="mt-5 flex flex-col gap-2 sm:flex-row sm:justify-end">
                    <button type="button" class="border border-gray-300 px-3 py-2 text-sm font-semibold text-steel hover:text-ink" @click="closeCurrentBookingPanel">
                        Đóng
                    </button>
                    <Link
                        :href="`/admin/bookings/${booking.id}`"
                        class="inline-flex items-center justify-center gap-2 border border-pine px-3 py-2 text-sm font-semibold text-pine hover:bg-pine hover:text-white"
                    >
                        Xem chi tiết booking
                    </Link>
                    <button
                        v-if="currentBookingPanelRoom.current_assignment?.can_release_assignment"
                        type="button"
                        class="inline-flex items-center justify-center gap-2 bg-coral px-3 py-2 text-sm font-semibold text-white hover:opacity-90"
                        @click="releaseCurrentBookingAssignment"
                    >
                        Gỡ phòng
                    </button>
                </div>
            </div>
        </div>

        <!-- Void Entry Modal -->
        <div v-if="showVoidModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-md border border-gray-200 bg-white p-5 shadow-xl">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold">Hủy phí phát sinh</h2>
                        <p class="mt-1 text-sm text-steel">Nhập lý do hủy trước khi xác nhận.</p>
                    </div>
                    <button type="button" class="text-steel hover:text-ink" @click="closeVoidModal">
                        <X class="h-5 w-5" />
                    </button>
                </div>
                <div v-if="voidingEntry" class="mt-4 border border-gray-100 p-3 text-sm">
                    <div class="font-medium">{{ voidingEntry.charge_type_label }} — {{ voidingEntry.description }}</div>
                    <div class="mt-1 text-steel">{{ formatCurrency(voidingEntry.amount) }} · {{ voidingEntry.entry_date }}</div>
                </div>
                <div class="mt-4">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Lý do hủy</label>
                    <textarea v-model="voidReasonInput" rows="3" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine" placeholder="Tối thiểu 5 ký tự" />
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="border border-gray-300 px-3 py-2 text-sm font-semibold text-steel hover:text-ink" @click="closeVoidModal">Đóng</button>
                    <button
                        type="button"
                        class="bg-coral px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-gray-300"
                        :disabled="voidReasonInput.trim().length < 5"
                        @click="confirmVoid"
                    >
                        Xác nhận hủy
                    </button>
                </div>
            </div>
        </div>

        <!-- Release Assignment Dialog -->
        <div v-if="releaseDialogAssignment" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" @mousedown.self="closeReleaseDialog">
            <form class="w-full max-w-md border border-gray-200 bg-white p-5 shadow-xl" @click.stop @submit.prevent="confirmRelease">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold">Giải phóng phòng</h2>
                        <p class="mt-1 text-sm text-steel">Nhập lý do giải phóng phòng (tùy chọn).</p>
                    </div>
                    <button type="button" class="text-steel hover:text-ink" @click="closeReleaseDialog">
                        <X class="h-5 w-5" />
                    </button>
                </div>
                <div class="mt-4">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Lý do</label>
                    <textarea v-model="releaseForm.release_reason" rows="3" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine" placeholder="Lý do giải phóng phòng" />
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="border border-gray-300 px-3 py-2 text-sm font-semibold text-steel hover:text-ink" @click="closeReleaseDialog">Hủy</button>
                    <button type="submit" class="bg-coral px-3 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:cursor-not-allowed disabled:bg-gray-300" :disabled="releaseForm.processing">Xác nhận giải phóng</button>
                </div>
            </form>
        </div>

        <!-- Checkout Payment Warning -->
        <div v-if="checkoutWarningStay" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-md border border-gray-200 bg-white p-5 shadow-xl">
                <div class="flex items-start justify-between gap-4">
                    <h2 class="text-lg font-semibold">Cảnh báo thanh toán</h2>
                    <button type="button" class="text-steel hover:text-ink" @click="closeCheckoutWarning">
                        <X class="h-5 w-5" />
                    </button>
                </div>
                <div class="mt-4 border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                    Booking chưa được thanh toán đầy đủ. Vui lòng kiểm tra thanh toán trước khi hoàn tất trả phòng.
                </div>
                <div class="mt-3 text-sm text-steel">
                    Còn lại: <span class="font-semibold text-coral">{{ formatCurrency(booking.payment_summary?.balance_due ?? 0) }}</span>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="border border-gray-300 px-3 py-2 text-sm font-semibold text-steel hover:text-ink" @click="closeCheckoutWarning">Hủy trả phòng</button>
                    <button type="button" class="bg-pine px-3 py-2 text-sm font-semibold text-white hover:opacity-90" @click="confirmCheckoutWithWarning">Tiếp tục trả phòng</button>
                </div>
            </div>
        </div>

        <div v-if="showCancelModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <form class="w-full max-w-lg border border-gray-200 bg-white p-5 shadow-xl" @submit.prevent="submitCancel">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold">Hủy booking</h2>
                        <p class="mt-1 text-sm text-steel">Nhập đúng mã booking và lý do hủy trước khi xác nhận.</p>
                    </div>
                    <button type="button" class="text-steel hover:text-ink" @click="closeCancelModal">
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <dl class="mt-4 grid gap-3 border border-gray-100 p-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-steel">Mã booking</dt>
                        <dd class="mt-1 font-semibold">{{ booking.cancel_confirmation.booking_code }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-steel">Khách hàng</dt>
                        <dd class="mt-1 font-semibold">{{ booking.cancel_confirmation.customer_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-steel">Nhận phòng</dt>
                        <dd class="mt-1">{{ booking.cancel_confirmation.checkin_at }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-steel">Trả phòng</dt>
                        <dd class="mt-1">{{ booking.cancel_confirmation.checkout_at }}</dd>
                    </div>
                </dl>

                <div class="mt-4 border border-coral/30 bg-coral/5 p-3 text-sm font-medium text-coral">
                    {{ booking.cancel_confirmation.warning }}
                </div>

                <div class="mt-4">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Nhập mã booking để xác nhận</label>
                    <input v-model="cancelForm.booking_code_confirmation" type="text" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                    <p v-if="cancelForm.errors.booking_code_confirmation" class="mt-1 text-sm text-coral">{{ cancelForm.errors.booking_code_confirmation }}</p>
                </div>

                <div class="mt-4">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Lý do hủy</label>
                    <textarea v-model="cancelForm.cancellation_reason" rows="4" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine" />
                    <p v-if="cancelForm.errors.cancellation_reason" class="mt-1 text-sm text-coral">{{ cancelForm.errors.cancellation_reason }}</p>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="border border-gray-300 px-3 py-2 text-sm font-semibold text-steel hover:text-ink" @click="closeCancelModal">Đóng</button>
                    <button type="submit" class="bg-coral px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-gray-300" :disabled="!canConfirmCancel">
                        Xác nhận hủy booking
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
