<script setup>
import { bookingStatusLabels } from '@/Support/vietnameseLabels';

defineProps({
    bookings: { type: Array, default: () => [] },
    canViewBooking: { type: Boolean, default: false },
});

function formatMoney(value) {
    return new Intl.NumberFormat('vi-VN').format(value ?? 0);
}
</script>

<template>
    <div class="overflow-x-auto rounded-lg border border-gray-200">
        <table class="min-w-full divide-y divide-gray-200 text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-2 py-2 text-left font-medium text-gray-600">Booking</th>
                    <th class="px-2 py-2 text-left font-medium text-gray-600">Khách hàng</th>
                    <th class="px-2 py-2 text-left font-medium text-gray-600">Trạng thái</th>
                    <th class="px-2 py-2 text-left font-medium text-gray-600">Phòng</th>
                    <th class="px-2 py-2 text-left font-medium text-gray-600">Nhận phòng (KH/TT)</th>
                    <th class="px-2 py-2 text-left font-medium text-gray-600">Trả phòng (KH/TT)</th>
                    <th class="px-2 py-2 text-right font-medium text-gray-600">Tổng</th>
                    <th class="px-2 py-2 text-right font-medium text-gray-600">Đã thu</th>
                    <th class="px-2 py-2 text-right font-medium text-gray-600">Còn lại</th>
                    <th class="px-2 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr v-for="booking in bookings" :key="booking.booking_id">
                    <td class="px-2 py-2 font-medium text-gray-900">{{ booking.booking_code }}</td>
                    <td class="px-2 py-2">{{ booking.customer_name }}</td>
                    <td class="px-2 py-2">{{ bookingStatusLabels[booking.status] ?? booking.status }}</td>
                    <td class="px-2 py-2">
                        <span v-for="(r, idx) in booking.rooms" :key="idx">{{ r.room_number }}<span v-if="idx < booking.rooms.length - 1">, </span></span>
                    </td>
                    <td class="px-2 py-2">
                        <div>{{ booking.planned_checkin_at?.slice(0, 16) }}</div>
                        <div class="text-gray-400">{{ booking.actual_checkin_at?.slice(0, 16) ?? '—' }}</div>
                    </td>
                    <td class="px-2 py-2">
                        <div>{{ booking.planned_checkout_at?.slice(0, 16) }}</div>
                        <div class="text-gray-400">{{ booking.actual_checkout_at?.slice(0, 16) ?? '—' }}</div>
                    </td>
                    <td class="px-2 py-2 text-right">{{ formatMoney(booking.folio_total) }}</td>
                    <td class="px-2 py-2 text-right">{{ formatMoney(booking.paid) }}</td>
                    <td class="px-2 py-2 text-right font-medium" :class="booking.outstanding > 0 ? 'text-red-600' : 'text-gray-500'">
                        {{ formatMoney(booking.outstanding) }}
                    </td>
                    <td class="px-2 py-2 text-right">
                        <a
                            v-if="canViewBooking"
                            :href="route('admin.bookings.show', { booking: booking.booking_id, tab: 'payments' })"
                            class="text-indigo-600 hover:underline"
                        >
                            Xem Booking
                        </a>
                    </td>
                </tr>
                <tr v-if="bookings.length === 0">
                    <td colspan="10" class="px-2 py-4 text-center text-gray-400">Không có booking nào trong ngày này.</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
