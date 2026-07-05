<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    booking: { type: Object, required: true },
    can: { type: Object, required: true },
});

// ─── Catalog ────────────────────────────────────────────────────────────────

const CATEGORIES = [
    { value: 'bed_config',    label: 'Cấu hình giường' },
    { value: 'extra_item',    label: 'Thêm đồ dùng' },
    { value: 'decoration',    label: 'Trang trí' },
    { value: 'accessibility', label: 'Hỗ trợ đặc biệt' },
    { value: 'general',       label: 'Yêu cầu khác' },
];

const REQUEST_TYPE_CATALOG = {
    bed_config: [
        { value: 'twin_keep',       label: 'Giữ 2 giường đôi' },
        { value: 'twin_to_double',  label: 'Ghép thành giường đôi' },
        { value: 'separate_beds',   label: 'Tách giường đơn' },
        { value: 'extra_bed',       label: 'Thêm giường phụ' },
    ],
    extra_item: [
        { value: 'baby_cot',           label: 'Nôi em bé' },
        { value: 'extra_pillow',       label: 'Thêm gối' },
        { value: 'non_feather_pillow', label: 'Gối không lông vũ' },
        { value: 'extra_blanket',      label: 'Thêm chăn' },
        { value: 'extra_towel',        label: 'Thêm khăn tắm' },
        { value: 'welcome_fruit',      label: 'Hoa quả chào mừng' },
        { value: 'welcome_amenity',    label: 'Quà chào mừng' },
    ],
    decoration: [
        { value: 'anniversary',       label: 'Kỷ niệm ngày cưới' },
        { value: 'honeymoon',         label: 'Tuần trăng mật' },
        { value: 'birthday',          label: 'Sinh nhật' },
        { value: 'vip_setup',         label: 'Đón khách VIP' },
        { value: 'flower_arrangement', label: 'Cắm hoa' },
    ],
    accessibility: [
        { value: 'wheelchair',        label: 'Xe lăn' },
        { value: 'non_smoking_prep',  label: 'Phòng không khói thuốc' },
        { value: 'ground_floor',      label: 'Tầng trệt' },
        { value: 'near_elevator',     label: 'Gần thang máy' },
    ],
    general: [
        { value: 'late_arrival',    label: 'Đến muộn' },
        { value: 'airport_pickup',  label: 'Đón sân bay' },
        { value: 'connecting_room', label: 'Phòng thông nhau' },
        { value: 'other',           label: 'Yêu cầu khác' },
    ],
};

const REQUEST_TYPE_LABELS = Object.fromEntries(
    Object.values(REQUEST_TYPE_CATALOG).flat().map(({ value, label }) => [value, label]),
);

// ─── Computed ────────────────────────────────────────────────────────────────

const specialRequests = computed(() => props.booking.specialRequests ?? []);
const availableStays  = computed(() => props.booking.availableStays ?? []);
const pendingCount    = computed(() => props.booking.pendingCount ?? 0);

const requestTypesForCategory = computed(() =>
    form.category ? (REQUEST_TYPE_CATALOG[form.category] ?? []) : [],
);

// ─── Add Request Form ────────────────────────────────────────────────────────

const showForm = ref(false);

const form = useForm({
    category:     '',
    request_type: '',
    quantity:     1,
    stay_id:      '',
    note:         '',
});

function onCategoryChange() {
    form.request_type = '';
}

function submitForm() {
    form.post(route('admin.bookings.special-requests.store', props.booking.id), {
        preserveScroll: true,
        onSuccess: () => {
            showForm.value = false;
            form.reset();
        },
    });
}

function cancelForm() {
    showForm.value = false;
    form.reset();
}

const formValid = computed(() =>
    form.category && form.request_type && form.quantity >= 1,
);

// ─── Actions ─────────────────────────────────────────────────────────────────

function acknowledge(id) {
    router.patch(
        route('admin.bookings.special-requests.acknowledge', [props.booking.id, id]),
        {},
        { preserveScroll: true },
    );
}

function fulfill(id) {
    router.patch(
        route('admin.bookings.special-requests.fulfill', [props.booking.id, id]),
        {},
        { preserveScroll: true },
    );
}

function cancel(id) {
    if (!confirm('Hủy yêu cầu này?')) return;
    router.delete(
        route('admin.bookings.special-requests.destroy', [props.booking.id, id]),
        { preserveScroll: true },
    );
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function requestTypeLabel(type) {
    return REQUEST_TYPE_LABELS[type] ?? type;
}

const STATUS_BADGE = {
    pending:      'bg-amber-100 text-amber-800',
    acknowledged: 'bg-blue-100 text-blue-800',
    fulfilled:    'bg-green-100 text-green-800',
    cancelled:    'bg-gray-100 text-gray-600',
};

const STATUS_LABEL = {
    pending:      'Chờ xử lý',
    acknowledged: 'Đã tiếp nhận',
    fulfilled:    'Đã hoàn thành',
    cancelled:    'Đã hủy',
};

function statusBadgeClass(status) {
    return STATUS_BADGE[status] ?? 'bg-gray-100 text-gray-600';
}

function statusLabel(status) {
    return STATUS_LABEL[status] ?? status;
}
</script>

<template>
    <!-- Header -->
    <div class="mb-4 flex items-center justify-between">
        <div class="flex items-center gap-2">
            <h2 class="text-base font-semibold text-ink">Yêu cầu đặc biệt</h2>
            <span
                v-if="pendingCount > 0"
                class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800"
            >{{ pendingCount }} chờ</span>
        </div>
        <button
            v-if="can.createSpecialRequest && !booking.is_cancelled && !showForm"
            type="button"
            class="bg-pine px-3 py-1.5 text-sm font-medium text-white hover:bg-pine/90"
            @click="showForm = true"
        >
            + Thêm yêu cầu
        </button>
    </div>

    <!-- Add Request Form -->
    <div v-if="showForm && can.createSpecialRequest" class="mb-4 border border-gray-200 bg-gray-50 p-4">
        <h3 class="mb-3 text-sm font-semibold text-ink">Thêm yêu cầu mới</h3>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <!-- Category -->
            <div>
                <label class="mb-1 block text-xs font-medium text-steel">Danh mục <span class="text-red-500">*</span></label>
                <select
                    v-model="form.category"
                    class="w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none"
                    @change="onCategoryChange"
                >
                    <option value="">— Chọn danh mục —</option>
                    <option v-for="cat in CATEGORIES" :key="cat.value" :value="cat.value">{{ cat.label }}</option>
                </select>
                <p v-if="form.errors.category" class="mt-1 text-xs text-red-600">{{ form.errors.category }}</p>
            </div>

            <!-- Request type -->
            <div>
                <label class="mb-1 block text-xs font-medium text-steel">Loại yêu cầu <span class="text-red-500">*</span></label>
                <select
                    v-model="form.request_type"
                    :disabled="!form.category"
                    class="w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none disabled:bg-gray-100 disabled:opacity-60"
                >
                    <option value="">— Chọn loại —</option>
                    <option v-for="rt in requestTypesForCategory" :key="rt.value" :value="rt.value">{{ rt.label }}</option>
                </select>
                <p v-if="form.errors.request_type" class="mt-1 text-xs text-red-600">{{ form.errors.request_type }}</p>
            </div>

            <!-- Quantity -->
            <div>
                <label class="mb-1 block text-xs font-medium text-steel">Số lượng <span class="text-red-500">*</span></label>
                <input
                    v-model.number="form.quantity"
                    type="number"
                    min="1"
                    class="w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none"
                />
                <p v-if="form.errors.quantity" class="mt-1 text-xs text-red-600">{{ form.errors.quantity }}</p>
            </div>

            <!-- Stay (optional) -->
            <div>
                <label class="mb-1 block text-xs font-medium text-steel">Áp dụng cho stay (tùy chọn)</label>
                <select
                    v-model="form.stay_id"
                    class="w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none"
                >
                    <option value="">— Không chọn —</option>
                    <option v-for="stay in availableStays" :key="stay.id" :value="stay.id">{{ stay.label }}</option>
                </select>
                <p v-if="form.errors.stay_id" class="mt-1 text-xs text-red-600">{{ form.errors.stay_id }}</p>
            </div>

            <!-- Note -->
            <div class="sm:col-span-2">
                <label class="mb-1 block text-xs font-medium text-steel">Ghi chú</label>
                <input
                    v-model="form.note"
                    type="text"
                    maxlength="500"
                    placeholder="Ghi chú thêm..."
                    class="w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none"
                />
                <p v-if="form.errors.note" class="mt-1 text-xs text-red-600">{{ form.errors.note }}</p>
            </div>
        </div>

        <div class="mt-3 flex items-center gap-2">
            <button
                type="button"
                :disabled="!formValid || form.processing"
                class="bg-pine px-4 py-1.5 text-sm font-medium text-white hover:bg-pine/90 disabled:cursor-not-allowed disabled:opacity-50"
                @click="submitForm"
            >
                Lưu yêu cầu
            </button>
            <button
                type="button"
                class="px-4 py-1.5 text-sm text-steel hover:text-ink"
                @click="cancelForm"
            >
                Hủy bỏ
            </button>
        </div>
    </div>

    <!-- Empty state -->
    <div
        v-if="specialRequests.length === 0"
        class="border border-gray-200 bg-white p-8 text-center text-sm text-steel"
    >
        <span v-if="can.createSpecialRequest">Chưa có yêu cầu nào. Nhấn "Thêm yêu cầu" để bắt đầu.</span>
        <span v-else>Chưa có yêu cầu đặc biệt nào cho booking này.</span>
    </div>

    <!-- Request table -->
    <div v-else class="overflow-x-auto border border-gray-200 bg-white">
        <table class="min-w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50 text-xs font-semibold uppercase tracking-wide text-steel">
                <tr>
                    <th class="px-3 py-2 text-left">#</th>
                    <th class="px-3 py-2 text-left">Danh mục</th>
                    <th class="px-3 py-2 text-left">Yêu cầu</th>
                    <th class="px-3 py-2 text-center">SL</th>
                    <th class="px-3 py-2 text-left">Phòng</th>
                    <th class="px-3 py-2 text-left">Ghi chú</th>
                    <th class="px-3 py-2 text-left">Trạng thái</th>
                    <th class="px-3 py-2 text-left">Hành động</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr
                    v-for="(req, idx) in specialRequests"
                    :key="req.id"
                    class="hover:bg-gray-50"
                >
                    <td class="px-3 py-2 text-steel">{{ idx + 1 }}</td>
                    <td class="px-3 py-2 text-ink">{{ req.category_label }}</td>
                    <td class="px-3 py-2 font-medium text-ink">{{ requestTypeLabel(req.request_type) }}</td>
                    <td class="px-3 py-2 text-center text-ink">{{ req.quantity }}</td>
                    <td class="px-3 py-2 text-steel">{{ req.room_number ?? '—' }}</td>
                    <td class="max-w-xs px-3 py-2">
                        <span
                            v-if="req.note"
                            class="block truncate text-steel"
                            :title="req.note"
                        >{{ req.note }}</span>
                        <span v-else class="text-gray-300">—</span>
                    </td>
                    <td class="px-3 py-2">
                        <span
                            class="inline-block rounded px-2 py-0.5 text-xs font-semibold"
                            :class="statusBadgeClass(req.status)"
                        >{{ statusLabel(req.status) }}</span>
                    </td>
                    <td class="px-3 py-2">
                        <div class="flex items-center gap-1.5">
                            <!-- Tiếp nhận: pending → acknowledged -->
                            <button
                                v-if="can.fulfillSpecialRequest && req.status === 'pending'"
                                type="button"
                                class="rounded border border-blue-300 px-2 py-0.5 text-xs text-blue-700 hover:bg-blue-50"
                                @click="acknowledge(req.id)"
                            >
                                Tiếp nhận
                            </button>

                            <!-- Hoàn thành: acknowledged → fulfilled -->
                            <button
                                v-if="can.fulfillSpecialRequest && req.status === 'acknowledged'"
                                type="button"
                                class="rounded border border-green-300 px-2 py-0.5 text-xs text-green-700 hover:bg-green-50"
                                @click="fulfill(req.id)"
                            >
                                Hoàn thành
                            </button>

                            <!-- Hủy: pending or acknowledged, requires cancelSpecialRequest -->
                            <button
                                v-if="can.cancelSpecialRequest && (req.status === 'pending' || req.status === 'acknowledged')"
                                type="button"
                                class="rounded border border-red-200 px-2 py-0.5 text-xs text-red-600 hover:bg-red-50"
                                @click="cancel(req.id)"
                            >
                                Hủy
                            </button>

                            <span v-if="req.status === 'fulfilled' || req.status === 'cancelled'" class="text-xs text-gray-400">
                                {{ req.status === 'fulfilled' ? 'Đã hoàn thành' : 'Đã hủy' }}
                            </span>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Meta info for expanded row (detail under table) -->
    <div
        v-for="req in specialRequests"
        :key="`meta-${req.id}`"
        class="hidden"
    >
        <!-- Request details are shown inline in the table; no expand needed -->
    </div>
</template>
