<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    booking: { type: Object, required: true },
    special_requests: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

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

function acknowledge(req) {
    router.patch(
        route('admin.bookings.special-requests.acknowledge', [props.booking.id, req.id]),
        {},
        { preserveScroll: true },
    );
}

function fulfill(req) {
    router.patch(
        route('admin.bookings.special-requests.fulfill', [props.booking.id, req.id]),
        {},
        { preserveScroll: true },
    );
}

function cancel(req) {
    if (!confirm('Hủy yêu cầu này?')) return;
    router.delete(
        route('admin.bookings.special-requests.destroy', [props.booking.id, req.id]),
        { preserveScroll: true },
    );
}
</script>

<template>
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <span class="text-steel">Booking {{ booking.booking_code }}</span>
                <span class="text-gray-300">/</span>
                <span class="font-semibold">Yêu cầu đặc biệt</span>
            </div>
        </template>

        <div class="mx-auto max-w-5xl px-4 py-6">
            <div class="mb-4">
                <h1 class="text-lg font-semibold text-ink">{{ booking.customer_name }}</h1>
                <p class="text-sm text-steel">Booking: {{ booking.booking_code }}</p>
            </div>

            <div v-if="special_requests.length === 0" class="border border-gray-200 bg-white p-10 text-center text-sm text-steel">
                Chưa có yêu cầu đặc biệt nào.
            </div>

            <div v-else class="overflow-x-auto border border-gray-200 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-gray-200 bg-gray-50 text-xs font-semibold uppercase tracking-wide text-steel">
                        <tr>
                            <th class="px-4 py-2 text-left">Danh mục</th>
                            <th class="px-4 py-2 text-left">Yêu cầu</th>
                            <th class="px-4 py-2 text-center">SL</th>
                            <th class="px-4 py-2 text-left">Phòng</th>
                            <th class="px-4 py-2 text-left">Ghi chú</th>
                            <th class="px-4 py-2 text-left">Trạng thái</th>
                            <th class="px-4 py-2 text-left">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="req in special_requests" :key="req.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-steel">{{ req.category?.replace('_', ' ') }}</td>
                            <td class="px-4 py-3 font-medium text-ink">{{ req.request_type?.replace(/_/g, ' ') }}</td>
                            <td class="px-4 py-3 text-center">{{ req.quantity }}</td>
                            <td class="px-4 py-3 text-steel">{{ req.stay?.room?.room_number ?? '—' }}</td>
                            <td class="px-4 py-3 text-steel">
                                <span v-if="req.note" class="block truncate" :title="req.note">{{ req.note }}</span>
                                <span v-else class="text-gray-300">—</span>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-block rounded px-2 py-0.5 text-xs font-semibold"
                                    :class="statusBadgeClass(req.status)"
                                >{{ statusLabel(req.status) }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-1.5">
                                    <button
                                        v-if="can.fulfill && req.status === 'pending'"
                                        type="button"
                                        class="rounded border border-blue-300 px-2 py-0.5 text-xs text-blue-700 hover:bg-blue-50"
                                        @click="acknowledge(req)"
                                    >
                                        Tiếp nhận
                                    </button>
                                    <button
                                        v-if="can.fulfill && req.status === 'acknowledged'"
                                        type="button"
                                        class="rounded border border-green-300 px-2 py-0.5 text-xs text-green-700 hover:bg-green-50"
                                        @click="fulfill(req)"
                                    >
                                        Hoàn thành
                                    </button>
                                    <button
                                        v-if="can.cancel && (req.status === 'pending' || req.status === 'acknowledged')"
                                        type="button"
                                        class="rounded border border-red-200 px-2 py-0.5 text-xs text-red-600 hover:bg-red-50"
                                        @click="cancel(req)"
                                    >
                                        Hủy
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
