<script setup>
import Pagination from '@/Components/Pagination.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { labelFor } from '@/Support/vietnameseLabels';
import { Head, Link, router } from '@inertiajs/vue3';
import { Banknote, BedDouble, Eye, Pencil, Plus, Search, X, XCircle } from 'lucide-vue-next';
import { reactive } from 'vue';

const props = defineProps({
    bookings: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, required: true },
    can: { type: Object, required: true },
});

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

const cancelBooking = (booking) => {
    if (!window.confirm(`Hủy đặt phòng ${booking.booking_code}?`)) {
        return;
    }

    router.post(`/admin/bookings/${booking.id}/cancel`, {}, { preserveScroll: true });
};

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
                            <th class="px-4 py-3">Màu</th>
                            <th class="px-4 py-3">Ngày tạo</th>
                            <th class="px-4 py-3 text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="booking in bookings.data" :key="booking.id" class="hover:bg-gray-50">
                            <td class="whitespace-nowrap px-4 py-3 font-medium">{{ booking.booking_code }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.customer_name }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.customer_phone }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ labelFor('bookingType', booking.booking_type) }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.checkin_at }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.checkout_at }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.adults }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.children_under_6 }} / {{ booking.children_over_6 }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ labelFor('bookingStatus', booking.status) }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.sales_user ?? 'Chưa phân công' }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <span class="inline-flex h-5 w-8 border border-gray-200" :style="{ backgroundColor: booking.booking_color }" />
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.created_at }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <Link :href="`/admin/bookings/${booking.id}`" class="mr-1 inline-flex h-8 w-8 items-center justify-center border border-gray-200 text-steel hover:border-pine hover:text-pine" title="Xem">
                                    <Eye class="h-4 w-4" />
                                </Link>
                                <Link v-if="can.updateBooking && booking.can_edit" :href="`/admin/bookings/${booking.id}/edit`" class="mr-1 inline-flex h-8 w-8 items-center justify-center border border-gray-200 text-steel hover:border-pine hover:text-pine" title="Sửa">
                                    <Pencil class="h-4 w-4" />
                                </Link>
                                <span
                                    v-else-if="can.updateBooking"
                                    class="mr-1 inline-flex"
                                    :title="booking.edit_disabled_reason"
                                    :aria-label="booking.edit_disabled_reason"
                                >
                                    <button
                                        type="button"
                                        class="inline-flex h-8 w-8 cursor-not-allowed items-center justify-center border border-gray-200 text-gray-300"
                                        disabled
                                    >
                                        <Pencil class="h-4 w-4" />
                                    </button>
                                </span>
                                <Link v-if="can.updateBooking" :href="`/admin/bookings/${booking.id}?tab=info`" class="mr-1 inline-flex h-8 w-8 items-center justify-center border border-gray-200 text-steel hover:border-pine hover:text-pine" title="Thêm nhu cầu phòng">
                                    <Plus class="h-4 w-4" />
                                </Link>
                                <Link v-if="can.addPayment" :href="`/admin/bookings/${booking.id}?tab=payments`" class="mr-1 inline-flex h-8 w-8 items-center justify-center border border-gray-200 text-steel hover:border-pine hover:text-pine" title="Thêm đặt cọc">
                                    <Banknote class="h-4 w-4" />
                                </Link>
                                <Link v-if="can.assignRoom" :href="`/admin/bookings/${booking.id}?tab=room_map`" class="mr-1 inline-flex h-8 w-8 items-center justify-center border border-gray-200 text-steel hover:border-pine hover:text-pine" title="Phân phòng">
                                    <BedDouble class="h-4 w-4" />
                                </Link>
                                <button v-if="can.cancelBooking && booking.status !== 'CANCELLED'" type="button" class="inline-flex h-8 w-8 items-center justify-center border border-gray-200 text-steel hover:border-coral hover:text-coral" title="Hủy" @click="cancelBooking(booking)">
                                    <XCircle class="h-4 w-4" />
                                </button>
                            </td>
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
    </AppLayout>
</template>
