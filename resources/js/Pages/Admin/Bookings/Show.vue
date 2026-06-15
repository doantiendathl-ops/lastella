<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { labelFor } from '@/Support/vietnameseLabels';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Banknote, BedDouble, CheckCircle, LogIn, LogOut, Pencil, Plus, RotateCcw, Trash2, X, XCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

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
const selectedRoomIds = ref([]);

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

const assignmentSummaryWithSelection = computed(() => props.assignmentSummary.map((item) => {
    const selected = selectedCountForRoomType(item.room_type_id);

    return {
        ...item,
        selected,
        remaining_after_selection: Math.max(Number(item.required) - Number(item.assigned) - selected, 0),
    };
}));

const existingRemainingRooms = computed(() => props.assignmentSummary.reduce(
    (total, item) => total + Math.max(Number(item.required) - Number(item.assigned), 0),
    0,
));

const hasAssignmentShortage = computed(() => assignmentSummaryWithSelection.value.some((item) => item.remaining_after_selection > 0));
const hasAssignmentOverage = computed(() => selectedRoomIds.value.length > existingRemainingRooms.value);

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

const roomTooltipText = (room) => {
    const lines = [
        `Phòng: ${room.room_number}`,
        `Loại phòng: ${room.room_type_name ?? room.room_type}`,
        `Trạng thái phòng: ${room.status_label}`,
        `Khả dụng: ${availabilityLabel(room)}`,
    ];

    if (room.assignment_detail) {
        lines.push(
            `Mã booking: ${room.assignment_detail.booking_code}`,
            `Khách hàng: ${room.assignment_detail.customer_name}`,
            `Nhận phòng: ${room.assignment_detail.checkin_at}`,
            `Trả phòng: ${room.assignment_detail.checkout_at}`,
            `Trạng thái phân phòng: ${labelFor('assignmentStatus', room.assignment_detail.status)}`,
        );
    }

    if (room.disabled_reason) {
        lines.push(`Lý do: ${room.disabled_reason}`);
    }

    if (!room.matches_requirement) {
        lines.push('Cảnh báo: Không đúng loại phòng yêu cầu');
    }

    if (isRoomSelected(room)) {
        lines.push('Đã chọn cho booking hiện tại');
    }

    return lines.filter(Boolean).join('\n');
};

const toggleRoomSelection = (room) => {
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

    if (room.availability_status === 'conflict' || room.availability_status === 'unavailable' || room.availability_status === 'current_booking') {
        return 'cursor-not-allowed border-gray-200 bg-gray-100 text-gray-400';
    }

    if (!room.matches_requirement) {
        return 'border-amber-300 bg-amber-50 text-ink hover:border-amber-400 hover:shadow-sm';
    }

    return 'border-gray-200 bg-white text-ink hover:border-pine hover:shadow-sm';
};

const roomCardStyle = (room) => isRoomSelected(room)
    ? { backgroundColor: props.booking.booking_color, borderColor: props.booking.booking_color }
    : {};

const roomStatusDotClass = (room) => {
    if (isRoomSelected(room)) {
        return 'bg-white';
    }

    if (room.availability_status === 'conflict' || room.availability_status === 'current_booking') {
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
    assignmentForm.room_ids = selectedRoomIds.value;
    assignmentForm.post(`/admin/bookings/${props.booking.id}/assignments`, {
        preserveScroll: true,
        onSuccess: () => {
            selectedRoomIds.value = [];
            assignmentForm.reset('room_ids');
        },
    });
};

const releaseAssignment = (assignment) => {
    const release_reason = window.prompt('Lý do giải phóng phòng') ?? '';
    router.post(`/admin/bookings/${props.booking.id}/assignments/${assignment.id}/release`, { release_reason }, { preserveScroll: true });
};

const checkIn = (stay) => router.post(`/admin/bookings/${props.booking.id}/stays/${stay.id}/check-in`, {}, { preserveScroll: true });
const checkOut = (stay) => router.post(`/admin/bookings/${props.booking.id}/stays/${stay.id}/check-out`, {}, { preserveScroll: true });

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

            <div v-if="tab === 'info'" class="grid gap-5 p-5 md:grid-cols-2 xl:grid-cols-3">
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
                    <div class="mt-2 text-sm">Người lớn: {{ booking.adults }}</div>
                    <div class="mt-1 text-sm">Trẻ dưới 6 tuổi: {{ booking.children_under_6 }}</div>
                    <div class="mt-1 text-sm">Trẻ trên 6 tuổi: {{ booking.children_over_6 }}</div>
                </div>
                <div class="border border-gray-100 p-4">
                    <div class="text-xs uppercase tracking-wide text-steel">Trạng thái</div>
                    <div class="mt-2 text-sm font-semibold">{{ labelFor('bookingStatus', booking.status) }}</div>
                    <div class="mt-2 inline-flex h-6 w-12 border border-gray-200" :style="{ backgroundColor: booking.booking_color }" />
                    <div class="mt-2 text-sm text-steel">Kinh doanh: {{ booking.sales_user ?? 'Chưa phân công' }}</div>
                </div>
                <div class="border border-gray-100 p-4 md:col-span-2">
                    <div class="text-xs uppercase tracking-wide text-steel">Ghi chú</div>
                    <div class="mt-2 whitespace-pre-line text-sm">{{ booking.note }}</div>
                    <div class="mt-3 whitespace-pre-line text-sm text-steel">{{ booking.internal_note }}</div>
                </div>
            </div>

            <div v-if="tab === 'info'" class="space-y-5 p-5">
                <form v-if="can.updateBooking" class="grid gap-3 border border-gray-100 p-4 md:grid-cols-4" @submit.prevent="submitRequirement">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Loại phòng</label>
                        <select v-model="requirementForm.room_type_id" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" @change="applySuggestedPrice(requirementForm)">
                            <option v-for="type in options.roomTypes" :key="type.value" :value="type.value">{{ type.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Số lượng</label>
                        <input v-model="requirementForm.quantity" type="number" min="1" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Người lớn</label>
                        <input v-model="requirementForm.adults" type="number" min="0" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Trẻ em dưới 6 tuổi</label>
                        <input v-model="requirementForm.children_under_6" type="number" min="0" max="50" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Trẻ em từ 6 tuổi</label>
                        <input v-model="requirementForm.children_over_6" type="number" min="0" max="50" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Giá phòng</label>
                        <input v-model="requirementForm.room_price" type="number" min="0" step="0.01" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                        <p class="mt-1 text-xs text-steel">Giá tham khảo lấy từ bảng giá, có thể sửa trực tiếp.</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Nguồn giá</label>
                        <select v-model="requirementForm.price_source" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                            <option v-for="source in options.priceSources" :key="source.value" :value="source.value">{{ labelFor('priceSource', source.value) }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Ghi chú</label>
                        <input v-model="requirementForm.note" type="text" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                    </div>
                    <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 self-end bg-pine px-3 py-2 text-sm font-semibold text-white">
                        <Plus class="h-4 w-4" />
                        Thêm nhu cầu
                    </button>
                    <p v-if="Object.keys(requirementForm.errors).length" class="md:col-span-4 text-sm text-coral">{{ Object.values(requirementForm.errors)[0] }}</p>
                </form>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-steel">
                            <tr>
                                <th class="px-4 py-3">Loại phòng</th>
                                <th class="px-4 py-3">Số lượng</th>
                                <th class="px-4 py-3">Người lớn</th>
                                <th class="px-4 py-3">Trẻ em</th>
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
                                    <td class="px-4 py-3"><input v-model="editRequirementForm.adults" type="number" min="0" class="w-20 border border-gray-300 px-2 py-1"></td>
                                    <td class="px-4 py-3">
                                        <div class="space-y-1">
                                            <label class="block text-xs text-steel">Dưới 6</label>
                                            <input v-model="editRequirementForm.children_under_6" type="number" min="0" max="50" class="w-20 border border-gray-300 px-2 py-1">
                                            <label class="block text-xs text-steel">Từ 6+</label>
                                            <input v-model="editRequirementForm.children_over_6" type="number" min="0" max="50" class="w-20 border border-gray-300 px-2 py-1">
                                        </div>
                                    </td>
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
                                    <td class="whitespace-nowrap px-4 py-3">{{ requirement.adults }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <div>Dưới 6: {{ requirement.children_under_6 }}</div>
                                        <div>Từ 6+: {{ requirement.children_over_6 }}</div>
                                    </td>
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
                                <td :colspan="can.updateBooking ? 8 : 7" class="px-4 py-10 text-center text-sm text-steel">Chưa có nhu cầu phòng.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="tab === 'payments'" class="space-y-5 p-5">
                <div class="grid gap-3 md:grid-cols-3">
                    <div class="border border-gray-100 p-4">
                        <div class="text-xs uppercase tracking-wide text-steel">Tổng tiền dự kiến</div>
                        <div class="mt-2 text-lg font-semibold">{{ formatCurrency(booking.payment_summary.expected_total) }}</div>
                    </div>
                    <div class="border border-gray-100 p-4">
                        <div class="text-xs uppercase tracking-wide text-steel">Đã thanh toán</div>
                        <div class="mt-2 text-lg font-semibold">{{ formatCurrency(booking.payment_summary.paid_total) }}</div>
                    </div>
                    <div class="border border-gray-100 p-4">
                        <div class="text-xs uppercase tracking-wide text-steel">Còn phải thanh toán</div>
                        <div class="mt-2 text-lg font-semibold" :class="booking.payment_summary.remaining_balance > 0 ? 'text-coral' : 'text-pine'">{{ formatCurrency(booking.payment_summary.remaining_balance) }}</div>
                    </div>
                </div>
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

            <div v-if="tab === 'room_map'" class="space-y-5 p-5">
                <div v-if="assignmentSummaryWithSelection.length" class="grid gap-3 md:grid-cols-3">
                    <div v-for="item in assignmentSummaryWithSelection" :key="item.room_type_id" class="border border-gray-100 p-3 text-sm">
                        <div class="font-semibold">{{ item.room_type_code }}</div>
                        <div class="mt-1 text-steel">Cần {{ item.required }} - Đã phân {{ item.assigned }} - Đang chọn {{ item.selected }} - Còn lại {{ item.remaining_after_selection }}</div>
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

                    <div v-if="hasAssignmentShortage" class="border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800">
                        Booking còn thiếu phòng.
                    </div>
                    <div v-if="hasAssignmentOverage" class="border border-coral/30 bg-coral/5 px-3 py-2 text-sm font-medium text-coral">
                        Bạn đã chọn vượt số lượng phòng yêu cầu.
                    </div>
                    <p v-if="Object.keys(assignmentForm.errors).length" class="text-sm text-coral">{{ Object.values(assignmentForm.errors)[0] }}</p>

                    <div class="space-y-4">
                        <section v-for="floor in roomBoard.floors" :key="floor.id" class="space-y-2">
                            <div class="flex items-center justify-between border-b border-gray-100 pb-1.5">
                                <h3 class="text-sm font-semibold">{{ floor.code === 'B1' ? 'B1' : `Tầng ${floor.code}` }}</h3>
                                <span class="text-xs text-steel">{{ floor.rooms.length }} phòng</span>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="room in floor.rooms"
                                    :key="room.id"
                                    type="button"
                                    class="group relative h-16 w-20 border px-2 py-1.5 text-center text-xs transition focus:outline-none focus:ring-2 focus:ring-pine/40"
                                    :class="roomCardClass(room)"
                                    :style="roomCardStyle(room)"
                                    :aria-disabled="!canSelectRoom(room)"
                                    :tabindex="canSelectRoom(room) ? 0 : -1"
                                    :title="roomTooltipText(room)"
                                    @click="toggleRoomSelection(room)"
                                >
                                    <div class="flex h-full flex-col items-center justify-center gap-0.5">
                                        <div class="text-base font-semibold leading-none">{{ room.room_number }}</div>
                                        <div class="max-w-full truncate text-[10px] font-semibold uppercase leading-tight">{{ shortRoomTypeCode(room) }}</div>
                                        <span class="mt-0.5 h-2 w-2 rounded-full" :class="roomStatusDotClass(room)" />
                                    </div>

                                    <div class="pointer-events-none absolute left-1/2 top-full z-30 mt-2 hidden w-64 -translate-x-1/2 border border-gray-200 bg-gray-950 p-3 text-left text-xs leading-relaxed text-white shadow-xl group-hover:block group-focus:block">
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
                                            </div>
                                        </dl>
                                        <div v-if="room.disabled_reason" class="mt-2 border-t border-white/10 pt-2 font-semibold">
                                            {{ room.disabled_reason }}
                                        </div>
                                        <div v-if="!room.matches_requirement" class="mt-2 text-amber-200">
                                            Không đúng loại phòng yêu cầu
                                        </div>
                                        <div v-if="isRoomSelected(room)" class="mt-2 text-emerald-200">
                                            Đã chọn cho booking hiện tại
                                        </div>
                                    </div>
                                </button>
                            </div>
                        </section>
                    </div>
                </form>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-steel">
                            <tr><th class="px-4 py-3">Phòng</th><th class="px-4 py-3">Loại</th><th class="px-4 py-3">Bắt đầu</th><th class="px-4 py-3">Kết thúc</th><th class="px-4 py-3">Trạng thái</th><th class="px-4 py-3">Phân bởi</th><th class="px-4 py-3">Giải phóng lúc</th><th class="px-4 py-3">Lý do</th><th v-if="can.releaseRoom" class="px-4 py-3 text-right">Thao tác</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="assignment in booking.assignments" :key="assignment.id"><td class="px-4 py-3">{{ assignment.room_number }}</td><td class="px-4 py-3">{{ assignment.room_type }}</td><td class="px-4 py-3">{{ assignment.start_at }}</td><td class="px-4 py-3">{{ assignment.end_at }}</td><td class="px-4 py-3">{{ labelFor('assignmentStatus', assignment.status) }}</td><td class="px-4 py-3">{{ assignment.assigned_by }}</td><td class="px-4 py-3">{{ assignment.released_at }}</td><td class="px-4 py-3">{{ assignment.release_reason }}</td><td v-if="can.releaseRoom" class="px-4 py-3 text-right"><button v-if="assignment.can_release" type="button" class="border border-gray-300 px-3 py-1 text-xs font-semibold text-steel hover:border-coral hover:text-coral" @click="releaseAssignment(assignment)">Giải phóng</button></td></tr>
                            <tr v-if="booking.assignments.length === 0"><td :colspan="can.releaseRoom ? 9 : 8" class="px-4 py-10 text-center text-sm text-steel">Chưa có phân phòng.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="tab === 'room_map'" class="overflow-x-auto p-5">
                <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-steel">
                        <tr><th class="px-4 py-3">Phòng</th><th class="px-4 py-3">Dự kiến nhận phòng</th><th class="px-4 py-3">Dự kiến trả phòng</th><th class="px-4 py-3">Thực nhận phòng</th><th class="px-4 py-3">Thực trả phòng</th><th class="px-4 py-3">Trạng thái</th><th class="px-4 py-3 text-right">Thao tác</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="stay in booking.stays" :key="stay.id">
                            <td class="px-4 py-3">{{ stay.room_number }}</td>
                            <td class="px-4 py-3">{{ stay.planned_checkin_at }}</td>
                            <td class="px-4 py-3">{{ stay.planned_checkout_at }}</td>
                            <td class="px-4 py-3">{{ stay.actual_checkin_at }}</td>
                            <td class="px-4 py-3">{{ stay.actual_checkout_at }}</td>
                            <td class="px-4 py-3">{{ labelFor('stayStatus', stay.status) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <button v-if="can.checkIn && stay.status === 'RESERVED'" type="button" class="mr-2 inline-flex items-center gap-2 border border-gray-300 px-3 py-1 text-xs font-semibold text-steel hover:border-pine hover:text-pine" @click="checkIn(stay)"><LogIn class="h-3.5 w-3.5" /> Nhận phòng</button>
                                <button v-if="can.checkOut && stay.status === 'CHECKED_IN'" type="button" class="inline-flex items-center gap-2 border border-gray-300 px-3 py-1 text-xs font-semibold text-steel hover:border-pine hover:text-pine" @click="checkOut(stay)"><LogOut class="h-3.5 w-3.5" /> Trả phòng</button>
                                <CheckCircle v-if="stay.status === 'CHECKED_OUT'" class="ml-auto h-4 w-4 text-pine" />
                            </td>
                        </tr>
                        <tr v-if="booking.stays.length === 0"><td colspan="7" class="px-4 py-10 text-center text-sm text-steel">Chưa có lưu trú.</td></tr>
                    </tbody>
                </table>
            </div>

            <div v-if="tab === 'history'" class="p-5 text-sm text-steel">
                Lịch sử booking sẽ được hiển thị ở giai đoạn sau.
            </div>
        </section>

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
