<script setup>
import Pagination from '@/Components/Pagination.vue';
import { useMediaQuery } from '@/Composables/useMediaQuery';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDate } from '@/Support/format';
import { labelFor } from '@/Support/vietnameseLabels';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Banknote, BedDouble, Eye, Maximize2, Pencil, Plus, RotateCcw, Search, X, XCircle } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import BookingActionsMenu from './Partials/BookingActionsMenu.vue';

const props = defineProps({
    bookings: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, required: true },
    can: { type: Object, required: true },
});

// ---- Collapsible "Thao tác" column ------------------------------------------
// Mobile always renders the collapsed "⋯" menu (no room for 6 icons); desktop
// remembers the user's expand/collapse choice in localStorage. See
// docs/implementation-reports/booking-table-collapsible-actions-report.md.
const ACTIONS_COLLAPSE_STORAGE_KEY = 'booking_table_actions_collapsed';
const isMobile = useMediaQuery('(max-width: 639px)');

const desktopCollapsedPreference = ref(false);
onMounted(() => {
    const stored = localStorage.getItem(ACTIONS_COLLAPSE_STORAGE_KEY);
    desktopCollapsedPreference.value = stored !== null ? stored === 'true' : isMobile.value;
});

const actionsCollapsed = computed(() => isMobile.value || desktopCollapsedPreference.value);

const toggleActionsCollapsed = () => {
    desktopCollapsedPreference.value = !desktopCollapsedPreference.value;
    localStorage.setItem(ACTIONS_COLLAPSE_STORAGE_KEY, String(desktopCollapsedPreference.value));
};

// Only one row's menu open at a time — also guarantees switching rows always
// reflects the newly-clicked booking, never a stale one.
const openMenuBookingId = ref(null);
const openActionsMenu = (bookingId) => { openMenuBookingId.value = bookingId; };
const closeActionsMenu = () => { openMenuBookingId.value = null; };

const query = reactive({
    booking_code: props.filters.booking_code ?? '',
    customer_name: props.filters.customer_name ?? '',
    customer_phone: props.filters.customer_phone ?? '',
    status: props.filters.status ?? '',
    booking_type: props.filters.booking_type ?? '',
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
    sales_user_id: props.filters.sales_user_id ?? '',
});

const applyFilters = () => router.get('/admin/bookings', clean(query), { preserveState: true, replace: true });

const resetFilters = () => {
    Object.keys(query).forEach((key) => {
        query[key] = '';
    });
    applyFilters();
};

const bookingToCancel = ref(null);
const cancelForm = useForm({
    booking_code_confirmation: '',
    cancellation_reason: '',
});

const canConfirmCancel = computed(() => bookingToCancel.value
    && cancelForm.booking_code_confirmation === bookingToCancel.value.booking_code
    && cancelForm.cancellation_reason.trim().length > 0
    && !cancelForm.processing);

const openCancelModal = (booking) => {
    bookingToCancel.value = booking;
    cancelForm.clearErrors();
    cancelForm.defaults({
        booking_code_confirmation: '',
        cancellation_reason: '',
    }).reset();
};

const closeCancelModal = () => {
    bookingToCancel.value = null;
    cancelForm.clearErrors();
    cancelForm.reset();
};

const submitCancel = () => {
    if (!bookingToCancel.value || !canConfirmCancel.value) {
        return;
    }

    cancelForm.post(`/admin/bookings/${bookingToCancel.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: closeCancelModal,
    });
};

const restoreBooking = (booking) => {
    router.post(`/admin/bookings/${booking.id}/restore`, {}, { preserveScroll: true });
};

/**
 * Single source of truth for row actions — both the desktop icon row and the
 * collapsed "⋯" menu render from this same list, so the two presentations can
 * never drift apart. Labels match the current source exactly (title attributes
 * below), not the icon-only UI that existed before this change.
 *
 * visible  = permission-gated (booking.can_* / props.can.*) — an invisible
 *            action must never appear in either presentation, since the
 *            backend would reject it outright.
 * disabled = business-rule-gated (booking already closed/checked-in, etc.) —
 *            still shown, grayed out, with the existing reason as a tooltip,
 *            exactly like the pre-existing desktop behavior.
 */
function bookingActions(booking) {
    return [
        {
            key: 'view',
            label: 'Xem',
            icon: Eye,
            visible: true,
            disabled: false,
            href: `/admin/bookings/${booking.id}`,
        },
        {
            key: 'edit',
            label: 'Sửa',
            icon: Pencil,
            visible: props.can.updateBooking,
            disabled: !booking.can_edit,
            disabledReason: booking.edit_disabled_reason,
            href: `/admin/bookings/${booking.id}/edit`,
        },
        {
            key: 'add-requirement',
            label: 'Thêm nhu cầu phòng',
            icon: Plus,
            visible: props.can.updateBooking,
            disabled: false,
            href: `/admin/bookings/${booking.id}?tab=info`,
        },
        {
            key: 'add-payment',
            label: 'Thêm đặt cọc',
            icon: Banknote,
            visible: props.can.addPayment,
            disabled: false,
            href: `/admin/bookings/${booking.id}?tab=payments`,
        },
        {
            key: 'assign-room',
            label: 'Phân phòng',
            icon: BedDouble,
            visible: props.can.assignRoom,
            disabled: false,
            href: `/admin/bookings/${booking.id}?tab=room_map`,
        },
        {
            key: 'cancel',
            label: 'Hủy booking',
            icon: XCircle,
            visible: props.can.cancelBooking && (booking.can_cancel || !!booking.cancel_disabled_reason),
            disabled: !booking.can_cancel,
            disabledReason: booking.cancel_disabled_reason,
            danger: true,
            handler: () => openCancelModal(booking),
        },
        {
            key: 'restore',
            label: 'Khôi phục booking',
            icon: RotateCcw,
            visible: booking.can_restore,
            disabled: false,
            handler: () => restoreBooking(booking),
        },
    ];
}

const visibleActions = (booking) => bookingActions(booking).filter((action) => action.visible);

const clean = (value) => Object.fromEntries(Object.entries(value).filter(([, item]) => item !== '' && item !== null && item !== undefined));
</script>

<template>
    <Head title="Đặt phòng" />

    <AppLayout>
        <template #header>
            <div class="flex min-w-0 items-center justify-between gap-4">
                <h1 class="truncate text-lg font-semibold">Đặt phòng</h1>
                <Link
                    v-if="can.createBooking"
                    href="/admin/bookings/create"
                    class="inline-flex items-center gap-2 bg-pine px-3 py-2 text-sm font-semibold text-white hover:bg-ink"
                >
                    <Plus class="h-4 w-4" />
                    Tạo mới
                </Link>
            </div>
        </template>

        <section class="border border-gray-200 bg-white shadow-sm">
            <form class="grid gap-3 border-b border-gray-200 p-4 md:grid-cols-2 xl:grid-cols-4" @submit.prevent="applyFilters">
                <input v-model="query.booking_code" type="text" placeholder="Mã đặt phòng" class="border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                <input v-model="query.customer_name" type="text" placeholder="Tên khách hàng" class="border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                <input v-model="query.customer_phone" type="text" placeholder="Số điện thoại" class="border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                <select v-model="query.status" class="border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                    <option value="">Tất cả trạng thái</option>
                    <option v-for="status in options.statuses" :key="status.value" :value="status.value">{{ labelFor('bookingStatus', status.value) }}</option>
                </select>
                <select v-model="query.booking_type" class="border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                    <option value="">Tất cả loại đặt phòng</option>
                    <option v-for="type in options.bookingTypes" :key="type.value" :value="type.value">{{ labelFor('bookingType', type.value) }}</option>
                </select>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-steel">Từ ngày</label>
                    <input v-model="query.date_from" type="date" class="w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-steel">Đến ngày</label>
                    <input v-model="query.date_to" type="date" class="w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                </div>
                <select v-model="query.sales_user_id" class="border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                    <option value="">Tất cả nhân viên kinh doanh</option>
                    <option v-for="user in options.salesUsers" :key="user.value" :value="user.value">{{ user.label }}</option>
                </select>
                <div class="flex gap-2 md:col-span-2">
                    <button type="submit" class="inline-flex h-10 items-center gap-2 bg-pine px-3 text-sm font-semibold text-white">
                        <Search class="h-4 w-4" />
                        Áp dụng
                    </button>
                    <button type="button" class="inline-flex h-10 items-center gap-2 border border-gray-300 px-3 text-sm text-steel hover:text-ink" @click="resetFilters">
                        <X class="h-4 w-4" />
                        Đặt lại
                    </button>
                </div>
            </form>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-steel">
                        <tr>
                            <th
                                class="sticky left-0 z-10 border-r border-gray-200 bg-gray-50 py-3"
                                :class="actionsCollapsed ? 'w-14 min-w-14 max-w-14 px-2' : 'px-4'"
                            >
                                <!-- Collapsed: "Thao tác" + full-text toggle never fits in 56px — an
                                     icon-only toggle is the only thing that fits without overflowing
                                     the cell (table has no table-fixed, so overflowing header content
                                     forces the column wider than its declared cap, defeating the
                                     collapse). Mobile has no toggle at all (always collapsed), so the
                                     header stays visually empty there aside from an sr-only label. -->
                                <div v-if="actionsCollapsed" class="flex items-center justify-center">
                                    <button
                                        v-if="!isMobile"
                                        type="button"
                                        class="inline-flex h-7 w-7 items-center justify-center text-pine hover:text-ink"
                                        title="Mở rộng cột thao tác"
                                        aria-label="Mở rộng cột thao tác"
                                        @click="toggleActionsCollapsed"
                                    >
                                        <Maximize2 class="h-4 w-4" />
                                    </button>
                                    <span v-else class="sr-only">Thao tác</span>
                                </div>
                                <div v-else class="flex items-center gap-2 whitespace-nowrap">
                                    <span>Thao tác</span>
                                    <button
                                        type="button"
                                        class="normal-case text-[10px] font-semibold text-pine underline hover:text-ink"
                                        @click="toggleActionsCollapsed"
                                    >
                                        Thu gọn
                                    </button>
                                </div>
                            </th>
                            <th class="px-4 py-3">Màu</th>
                            <th class="px-4 py-3">Mã</th>
                            <th class="px-4 py-3">Khách hàng</th>
                            <th class="px-4 py-3">Điện thoại</th>
                            <th class="px-4 py-3">Loại</th>
                            <th class="px-4 py-3">Nhận phòng</th>
                            <th class="px-4 py-3">Trả phòng</th>
                            <th class="px-4 py-3">Người lớn</th>
                            <th class="px-4 py-3">Trẻ em</th>
                            <th class="px-4 py-3">Trạng thái</th>
                            <th class="px-4 py-3">Kinh doanh</th>
                            <th class="px-4 py-3">Ngày tạo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="booking in bookings.data" :key="booking.id" class="group hover:bg-gray-50">
                            <td
                                class="sticky left-0 z-10 whitespace-nowrap border-r border-gray-200 bg-white py-3 group-hover:bg-gray-50"
                                :class="actionsCollapsed ? 'w-14 min-w-14 max-w-14 px-2' : 'px-4'"
                            >
                                <BookingActionsMenu
                                    v-if="actionsCollapsed"
                                    :actions="visibleActions(booking)"
                                    :booking-code="booking.booking_code"
                                    :is-mobile="isMobile"
                                    :open="openMenuBookingId === booking.id"
                                    @open="openActionsMenu(booking.id)"
                                    @close="closeActionsMenu"
                                />
                                <template v-else>
                                    <template v-for="action in visibleActions(booking)" :key="action.key">
                                        <Link
                                            v-if="!action.disabled && action.href"
                                            :href="action.href"
                                            class="mr-1 inline-flex h-8 w-8 items-center justify-center border border-gray-200 text-steel hover:border-pine hover:text-pine"
                                            :class="action.danger && 'hover:border-coral hover:text-coral'"
                                            :title="action.label"
                                        >
                                            <component :is="action.icon" class="h-4 w-4" />
                                        </Link>
                                        <button
                                            v-else-if="!action.disabled"
                                            type="button"
                                            class="mr-1 inline-flex h-8 w-8 items-center justify-center border border-gray-200 text-steel hover:border-pine hover:text-pine"
                                            :class="action.danger && 'hover:border-coral hover:text-coral'"
                                            :title="action.label"
                                            @click="action.handler?.()"
                                        >
                                            <component :is="action.icon" class="h-4 w-4" />
                                        </button>
                                        <span
                                            v-else
                                            class="mr-1 inline-flex"
                                            :title="action.disabledReason"
                                            :aria-label="action.disabledReason"
                                        >
                                            <button type="button" class="inline-flex h-8 w-8 cursor-not-allowed items-center justify-center border border-gray-200 text-gray-300" disabled>
                                                <component :is="action.icon" class="h-4 w-4" />
                                            </button>
                                        </span>
                                    </template>
                                </template>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <span class="inline-flex h-5 w-8 border border-gray-200" :style="{ backgroundColor: booking.booking_color }" />
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium">{{ booking.booking_code }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.customer_name }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.customer_phone }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ labelFor('bookingType', booking.booking_type) }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ formatDate(booking.checkin_at) }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ formatDate(booking.checkout_at) }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.adults }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.children_under_6 }} / {{ booking.children_over_6 }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ labelFor('bookingStatus', booking.status) }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.sales_user ?? 'Chưa phân công' }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ formatDate(booking.created_at) }}</td>
                        </tr>
                        <tr v-if="bookings.data.length === 0">
                            <td colspan="13" class="px-4 py-12 text-center text-sm text-steel">Không có đặt phòng.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col gap-3 border-t border-gray-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-steel">Hiển thị {{ bookings.from ?? 0 }} đến {{ bookings.to ?? 0 }} trên {{ bookings.total ?? 0 }}</p>
                <Pagination :links="bookings.links" />
            </div>
        </section>

        <div v-if="bookingToCancel" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
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
                        <dd class="mt-1 font-semibold">{{ bookingToCancel.cancel_confirmation.booking_code }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-steel">Khách hàng</dt>
                        <dd class="mt-1 font-semibold">{{ bookingToCancel.cancel_confirmation.customer_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-steel">Nhận phòng</dt>
                        <dd class="mt-1">{{ formatDate(bookingToCancel.cancel_confirmation.checkin_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-steel">Trả phòng</dt>
                        <dd class="mt-1">{{ formatDate(bookingToCancel.cancel_confirmation.checkout_at) }}</dd>
                    </div>
                </dl>

                <div class="mt-4 border border-coral/30 bg-coral/5 p-3 text-sm font-medium text-coral">
                    {{ bookingToCancel.cancel_confirmation.warning }}
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
