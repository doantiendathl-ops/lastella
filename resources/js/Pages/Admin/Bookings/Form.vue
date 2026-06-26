<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { labelFor } from '@/Support/vietnameseLabels';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    booking: { type: Object, default: null },
    action: { type: String, required: true },
    method: { type: String, required: true },
    options: { type: Object, required: true },
    bookingTimeConflicts: { type: Array, default: null },
});

const isEditing = computed(() => props.booking !== null);
const checkinDisabled = computed(() => isEditing.value && (props.booking.has_checked_in || props.booking.has_checked_out || props.booking.is_cancelled));
const checkoutDisabled = computed(() => isEditing.value && (props.booking.has_checked_out || props.booking.is_cancelled));

const timeChangeWarning = computed(() => {
    if (!isEditing.value) return null;
    if (props.booking.is_cancelled) return 'Booking đã hủy. Không thể chỉnh thời gian lưu trú.';
    if (props.booking.has_checked_out) return 'Booking đã trả phòng. Không thể chỉnh thời gian lưu trú.';
    if (props.booking.has_checked_in) return 'Booking đã nhận phòng. Chỉ có thể điều chỉnh thời gian trả phòng dự kiến.';
    if (props.booking.has_active_assignments) return 'Booking đã có phòng được gán. Hệ thống sẽ kiểm tra lại khả dụng phòng khi lưu.';
    return null;
});

// 30-minute interval time options: 00:00 → 23:30
const timeOptions = Array.from({ length: 48 }, (_, i) => {
    const h = String(Math.floor(i / 2)).padStart(2, '0');
    const m = i % 2 === 0 ? '00' : '30';
    return `${h}:${m}`;
});

const localDateStr = (date) => {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
};

// Split YYYY-MM-DDTHH:mm into { date, time }; fall back to defaults for new bookings
const splitDateTime = (datetimeStr, defaultDate, defaultTime) => {
    if (datetimeStr && datetimeStr.includes('T')) {
        return { date: datetimeStr.slice(0, 10), time: datetimeStr.slice(11, 16) };
    }
    return { date: defaultDate, time: defaultTime };
};

const today = localDateStr(new Date());
const tomorrow = (() => { const d = new Date(); d.setDate(d.getDate() + 1); return localDateStr(d); })();

const checkinParts = splitDateTime(props.booking?.checkin_at, today, '14:00');
const checkoutParts = splitDateTime(props.booking?.checkout_at, tomorrow, '12:00');

const checkinDate = ref(checkinParts.date);
const checkinTime = ref(checkinParts.time);
const checkoutDate = ref(checkoutParts.date);
const checkoutTime = ref(checkoutParts.time);

const form = useForm({
    customer_name: props.booking?.customer_name ?? '',
    customer_phone: props.booking?.customer_phone ?? '',
    customer_email: props.booking?.customer_email ?? '',
    customer_type: props.booking?.customer_type ?? 'INDIVIDUAL',
    booking_type: props.booking?.booking_type ?? 'OVERNIGHT',
    checkin_at: '',
    checkout_at: '',
    adults: props.booking?.adults ?? 1,
    children_under_6: props.booking?.children_under_6 ?? 0,
    children_over_6: props.booking?.children_over_6 ?? 0,
    booking_color: props.booking?.booking_color ?? props.options.recommended_booking_colors?.[0] ?? '#196251',
    sales_user_id: props.booking?.sales_user_id ?? '',
    note: props.booking?.note ?? '',
    internal_note: props.booking?.internal_note ?? '',
});

const submit = () => {
    form.checkin_at = checkinDate.value && checkinTime.value
        ? `${checkinDate.value}T${checkinTime.value}`
        : '';
    form.checkout_at = checkoutDate.value && checkoutTime.value
        ? `${checkoutDate.value}T${checkoutTime.value}`
        : '';
    form[props.method](props.action);
};

const showConflictModal = ref(false);

watch(
    () => props.bookingTimeConflicts,
    (val) => {
        if (val && val.length > 0) {
            showConflictModal.value = true;
        }
    },
    { immediate: true },
);
</script>

<template>
    <Head :title="booking ? 'Sửa đặt phòng' : 'Tạo đặt phòng'" />

    <AppLayout>
        <template #header>
            <div class="flex min-w-0 items-center justify-between gap-4">
                <h1 class="truncate text-lg font-semibold">{{ booking ? 'Sửa đặt phòng' : 'Tạo đặt phòng' }}</h1>
                <Link :href="booking ? `/admin/bookings/${booking.id}` : '/admin/bookings'" class="inline-flex items-center gap-2 border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-steel hover:text-ink">
                    <ArrowLeft class="h-4 w-4" />
                    Quay lại
                </Link>
            </div>
        </template>

        <form class="max-w-5xl border border-gray-200 bg-white p-5 shadow-sm" @submit.prevent="submit">
            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium">Tên khách hàng</label>
                    <input v-model="form.customer_name" type="text" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                    <p v-if="form.errors.customer_name" class="mt-1 text-sm text-coral">{{ form.errors.customer_name }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium">Số điện thoại</label>
                    <input v-model="form.customer_phone" type="text" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                </div>
                <div>
                    <label class="block text-sm font-medium">Email khách hàng</label>
                    <input v-model="form.customer_email" type="email" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                    <p v-if="form.errors.customer_email" class="mt-1 text-sm text-coral">{{ form.errors.customer_email }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium">Loại khách hàng</label>
                    <select v-model="form.customer_type" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                        <option v-for="option in options.customerTypes" :key="option.value" :value="option.value">{{ labelFor('customerType', option.value) }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium">Loại đặt phòng</label>
                    <select v-model="form.booking_type" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                        <option v-for="option in options.bookingTypes" :key="option.value" :value="option.value">{{ labelFor('bookingType', option.value) }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium">Màu đặt phòng</label>
                    <div class="mt-2">
                        <div v-if="options.recommended_booking_colors?.length" class="flex flex-wrap gap-2">
                            <button
                                v-for="color in options.recommended_booking_colors"
                                :key="color"
                                type="button"
                                class="h-8 w-8 rounded-sm border-2 transition-transform"
                                :class="form.booking_color === color ? 'scale-110 border-gray-800 shadow-sm' : 'border-transparent hover:border-gray-400'"
                                :style="{ backgroundColor: color }"
                                :title="color"
                                @click="form.booking_color = color"
                            />
                        </div>
                        <p v-else-if="options.used_booking_colors?.length" class="text-xs text-steel">
                            Tất cả màu gợi ý đang được sử dụng bởi các booking chưa hoàn thành. Bạn có thể nhập màu tùy chỉnh.
                        </p>
                        <div class="mt-3 flex items-center gap-3">
                            <span class="text-xs text-steel">Màu tùy chỉnh:</span>
                            <input v-model="form.booking_color" type="color" class="h-8 w-14 cursor-pointer border border-gray-300 px-1 py-0.5">
                            <span class="font-mono text-xs uppercase text-steel">{{ form.booking_color }}</span>
                        </div>
                    </div>
                    <p v-if="form.errors.booking_color" class="mt-1 text-sm text-coral">{{ form.errors.booking_color }}</p>
                </div>
                <div v-if="timeChangeWarning" class="md:col-span-2 border px-3 py-2 text-sm" :class="checkinDisabled && checkoutDisabled ? 'border-gray-200 bg-gray-50 text-steel' : 'border-amber-200 bg-amber-50 text-amber-800'">
                    {{ timeChangeWarning }}
                </div>
                <div>
                    <label class="block text-sm font-medium" :class="checkinDisabled ? 'text-gray-400' : ''">Ngày nhận phòng</label>
                    <input v-model="checkinDate" type="date" :disabled="checkinDisabled" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400">
                </div>
                <div>
                    <label class="block text-sm font-medium" :class="checkinDisabled ? 'text-gray-400' : ''">Giờ nhận phòng</label>
                    <select v-model="checkinTime" :disabled="checkinDisabled" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400">
                        <option v-for="t in timeOptions" :key="t" :value="t">{{ t }}</option>
                    </select>
                    <p v-if="form.errors.checkin_at && !bookingTimeConflicts?.length" class="mt-1 text-sm text-coral">{{ form.errors.checkin_at }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium" :class="checkoutDisabled ? 'text-gray-400' : ''">Ngày trả phòng</label>
                    <input v-model="checkoutDate" type="date" :disabled="checkoutDisabled" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400">
                </div>
                <div>
                    <label class="block text-sm font-medium" :class="checkoutDisabled ? 'text-gray-400' : ''">Giờ trả phòng</label>
                    <select v-model="checkoutTime" :disabled="checkoutDisabled" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400">
                        <option v-for="t in timeOptions" :key="t" :value="t">{{ t }}</option>
                    </select>
                    <p v-if="form.errors.checkout_at && !bookingTimeConflicts?.length" class="mt-1 text-sm text-coral">{{ form.errors.checkout_at }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium">Người lớn</label>
                    <input v-model="form.adults" type="number" min="1" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                </div>
                <div>
                    <label class="block text-sm font-medium">Trẻ dưới 6 tuổi</label>
                    <input v-model="form.children_under_6" type="number" min="0" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                </div>
                <div>
                    <label class="block text-sm font-medium">Trẻ trên 6 tuổi</label>
                    <input v-model="form.children_over_6" type="number" min="0" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                </div>
                <div>
                    <label class="block text-sm font-medium">Nhân viên kinh doanh</label>
                    <select v-model="form.sales_user_id" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                        <option value="">Chưa phân công</option>
                        <option v-for="user in options.salesUsers" :key="user.value" :value="user.value">{{ user.label }}</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium">Ghi chú</label>
                    <textarea v-model="form.note" rows="3" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine" />
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium">Ghi chú nội bộ</label>
                    <textarea v-model="form.internal_note" rows="3" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine" />
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3 border-t border-gray-200 pt-5">
                <Link :href="booking ? `/admin/bookings/${booking.id}` : '/admin/bookings'" class="inline-flex items-center border border-gray-300 px-4 py-2 text-sm font-semibold text-steel hover:text-ink">
                    Hủy
                </Link>
                <button type="submit" class="inline-flex items-center gap-2 bg-pine px-4 py-2 text-sm font-semibold text-white hover:bg-ink" :disabled="form.processing">
                    <Save class="h-4 w-4" />
                    Lưu
                </button>
            </div>
        </form>
    </AppLayout>

    <Teleport to="body">
        <div v-if="showConflictModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-lg border border-gray-200 bg-white shadow-lg">
                <div class="border-b border-gray-200 px-5 py-4">
                    <h2 class="text-sm font-semibold">Thời gian mới bị chồng lấn phòng</h2>
                </div>
                <div class="max-h-96 space-y-3 overflow-y-auto px-5 py-4">
                    <div v-for="conflict in bookingTimeConflicts" :key="conflict.booking_id + '-' + conflict.room_id" class="border border-gray-200 p-3 text-sm">
                        <div class="flex items-start justify-between gap-2">
                            <div class="space-y-1">
                                <div>
                                    <span class="font-medium">Phòng {{ conflict.room_number }}</span>
                                    <span v-if="conflict.room_type" class="ml-1 text-steel">({{ conflict.room_type }})</span>
                                </div>
                                <div class="text-steel">Booking: <span class="font-medium text-ink">{{ conflict.booking_code }}</span></div>
                                <div class="text-steel">Khách: {{ conflict.customer_name }}</div>
                                <div class="text-steel">{{ conflict.checkin_at }} → {{ conflict.checkout_at }}</div>
                                <div>
                                    <span class="inline-block bg-amber-100 px-2 py-0.5 text-xs text-amber-800">{{ conflict.status_label }}</span>
                                </div>
                            </div>
                            <a :href="conflict.view_url" class="shrink-0 text-xs font-semibold text-pine underline hover:text-ink">
                                Xem booking
                            </a>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end border-t border-gray-200 px-5 py-4">
                    <button type="button" class="border border-gray-300 px-4 py-2 text-sm font-semibold text-steel hover:text-ink" @click="showConflictModal = false">
                        Đóng
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
