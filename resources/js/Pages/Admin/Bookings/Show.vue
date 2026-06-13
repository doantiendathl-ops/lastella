<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { labelFor } from '@/Support/vietnameseLabels';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Banknote, BedDouble, CheckCircle, LogIn, LogOut, Pencil, Plus, Trash2, XCircle } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps({
    booking: { type: Object, required: true },
    activeTab: { type: String, default: 'overview' },
    assignmentSummary: { type: Array, default: () => [] },
    options: { type: Object, required: true },
    can: { type: Object, required: true },
});

const tab = ref(props.activeTab);
const editingRequirementId = ref(null);

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
    payment_method: '',
    payment_at: nowLocal(),
    note: '',
});
const assignmentForm = useForm({
    room_id: props.options.rooms[0]?.value ?? '',
    start_at: props.booking.checkin_at,
    end_at: props.booking.checkout_at,
});

const tabs = [
    { key: 'overview', label: 'Tổng quan' },
    { key: 'requirements', label: 'Nhu cầu phòng' },
    { key: 'payments', label: 'Thanh toán / Đặt cọc' },
    { key: 'assignments', label: 'Phân phòng' },
    { key: 'stays', label: 'Lưu trú' },
];

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
            payment_method: '',
            payment_at: nowLocal(),
            note: '',
        }).reset(),
    });
};

const submitAssignment = () => {
    assignmentForm.post(`/admin/bookings/${props.booking.id}/assignments`, {
        preserveScroll: true,
    });
};

const releaseAssignment = (assignment) => {
    const release_reason = window.prompt('Lý do giải phóng phòng') ?? '';
    router.post(`/admin/bookings/${props.booking.id}/assignments/${assignment.id}/release`, { release_reason }, { preserveScroll: true });
};

const checkIn = (stay) => router.post(`/admin/bookings/${props.booking.id}/stays/${stay.id}/check-in`, {}, { preserveScroll: true });
const checkOut = (stay) => router.post(`/admin/bookings/${props.booking.id}/stays/${stay.id}/check-out`, {}, { preserveScroll: true });

const cancelBooking = () => {
    const cancellation_reason = window.prompt('Lý do hủy đặt phòng') ?? '';
    router.post(`/admin/bookings/${props.booking.id}/cancel`, { cancellation_reason }, { preserveScroll: true });
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
                    <button v-if="can.cancelBooking && booking.status !== 'CANCELLED'" type="button" class="inline-flex items-center gap-2 border border-coral px-3 py-2 text-sm font-semibold text-coral hover:bg-coral hover:text-white" @click="cancelBooking">
                        <XCircle class="h-4 w-4" />
                        Hủy
                    </button>
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

            <div v-if="tab === 'overview'" class="grid gap-5 p-5 md:grid-cols-2 xl:grid-cols-3">
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

            <div v-if="tab === 'requirements'" class="space-y-5 p-5">
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
                <form v-if="can.addPayment" class="grid gap-3 border border-gray-100 p-4 md:grid-cols-5" @submit.prevent="submitPayment">
                    <select v-model="paymentForm.payment_type" class="border border-gray-300 px-3 py-2 text-sm">
                        <option v-for="type in options.paymentTypes" :key="type.value" :value="type.value">{{ labelFor('paymentType', type.value) }}</option>
                    </select>
                    <input v-model="paymentForm.amount" type="number" min="0.01" step="0.01" class="border border-gray-300 px-3 py-2 text-sm" placeholder="Số tiền">
                    <input v-model="paymentForm.payment_method" type="text" class="border border-gray-300 px-3 py-2 text-sm" placeholder="Phương thức">
                    <input v-model="paymentForm.payment_at" type="datetime-local" class="border border-gray-300 px-3 py-2 text-sm">
                    <input v-model="paymentForm.note" type="text" class="border border-gray-300 px-3 py-2 text-sm" placeholder="Ghi chú">
                    <button type="submit" class="inline-flex items-center justify-center gap-2 bg-pine px-3 py-2 text-sm font-semibold text-white">
                        <Banknote class="h-4 w-4" />
                        Thêm thanh toán
                    </button>
                    <p v-if="Object.keys(paymentForm.errors).length" class="md:col-span-5 text-sm text-coral">{{ Object.values(paymentForm.errors)[0] }}</p>
                </form>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-steel">
                            <tr><th class="px-4 py-3">Loại</th><th class="px-4 py-3">Số tiền</th><th class="px-4 py-3">Phương thức</th><th class="px-4 py-3">Thời gian thanh toán</th><th class="px-4 py-3">Xác nhận bởi</th><th class="px-4 py-3">Ghi chú</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="payment in booking.payments" :key="payment.id"><td class="px-4 py-3">{{ labelFor('paymentType', payment.payment_type) }}</td><td class="px-4 py-3">{{ payment.amount }}</td><td class="px-4 py-3">{{ payment.payment_method }}</td><td class="px-4 py-3">{{ payment.payment_at }}</td><td class="px-4 py-3">{{ payment.confirmed_by }}</td><td class="px-4 py-3">{{ payment.note }}</td></tr>
                            <tr v-if="booking.payments.length === 0"><td colspan="6" class="px-4 py-10 text-center text-sm text-steel">Chưa có thanh toán.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="tab === 'assignments'" class="space-y-5 p-5">
                <div v-if="assignmentSummary.length" class="grid gap-3 md:grid-cols-3">
                    <div v-for="item in assignmentSummary" :key="item.room_type_id" class="border border-gray-100 p-3 text-sm">
                        <div class="font-semibold">{{ item.room_type_code }}</div>
                        <div class="mt-1 text-steel">Cần {{ item.required }} - Đã phân {{ item.assigned }} - Còn lại {{ item.remaining }}</div>
                    </div>
                </div>

                <form v-if="can.assignRoom" class="grid gap-3 border border-gray-100 p-4 md:grid-cols-4" @submit.prevent="submitAssignment">
                    <select v-model="assignmentForm.room_id" class="border border-gray-300 px-3 py-2 text-sm">
                        <option v-for="room in options.rooms" :key="room.value" :value="room.value">{{ room.label }}</option>
                    </select>
                    <input v-model="assignmentForm.start_at" type="datetime-local" class="border border-gray-300 px-3 py-2 text-sm">
                    <input v-model="assignmentForm.end_at" type="datetime-local" class="border border-gray-300 px-3 py-2 text-sm">
                    <button type="submit" class="inline-flex items-center justify-center gap-2 bg-pine px-3 py-2 text-sm font-semibold text-white">
                        <BedDouble class="h-4 w-4" />
                        Phân phòng
                    </button>
                    <p v-if="Object.keys(assignmentForm.errors).length" class="md:col-span-4 text-sm text-coral">{{ Object.values(assignmentForm.errors)[0] }}</p>
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

            <div v-if="tab === 'stays'" class="overflow-x-auto p-5">
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
        </section>
    </AppLayout>
</template>
