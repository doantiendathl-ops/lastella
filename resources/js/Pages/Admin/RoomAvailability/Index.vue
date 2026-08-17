<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { readableTextClass } from '@/Support/colorContrast';
import { roomStatusBadge } from '@/Support/roomStatusBadges';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    availability: Object,
    filters: Object,
    pendingRequestCounts: { type: Object, default: () => ({}) },
    can: Object,
});

const form = ref({
    start_at: props.filters.start_at.replace(' ', 'T'),
    end_at: props.filters.end_at.replace(' ', 'T'),
});

const submitting = ref(false);

function search() {
    submitting.value = true;
    router.get(
        '/admin/room-availability',
        {
            start_at: form.value.start_at.replace('T', ' '),
            end_at: form.value.end_at.replace('T', ' '),
        },
        {
            preserveState: false,
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

const selectedRoom = ref(null);

function selectRoom(room) {
    if (room.availability === 'out_of_order' || room.availability === 'cleaning') return;
    selectedRoom.value = room;
}

function closeDetail() {
    selectedRoom.value = null;
}

const availabilityStyles = {
    available: {
        card: 'border-green-200 bg-green-50 hover:border-green-400 cursor-pointer',
        dot: 'bg-green-400',
        badge: 'bg-green-100 text-green-800',
    },
    reserved: {
        card: 'border-blue-200 bg-blue-50 hover:border-blue-400 cursor-pointer',
        dot: 'bg-blue-400',
        badge: 'bg-blue-100 text-blue-800',
    },
    occupied: {
        card: 'border-orange-300 bg-orange-50 hover:border-orange-500 cursor-pointer',
        dot: 'bg-orange-500',
        badge: 'bg-orange-100 text-orange-800',
    },
    overstay: {
        card: 'border-red-400 bg-red-50 hover:border-red-600 cursor-pointer',
        dot: 'bg-red-600',
        badge: 'bg-red-100 text-red-900',
    },
    multi_booking: {
        card: 'border-amber-200 bg-amber-50 hover:border-amber-400 cursor-pointer',
        dot: 'bg-amber-400',
        badge: 'bg-amber-100 text-amber-800',
    },
    overlap: {
        card: 'border-red-300 bg-red-50 hover:border-red-500 cursor-pointer',
        dot: 'bg-red-500',
        badge: 'bg-red-100 text-red-800',
    },
    out_of_order: {
        card: 'border-gray-200 bg-gray-100 opacity-60 cursor-default',
        dot: 'bg-gray-400',
        badge: 'bg-gray-200 text-gray-600',
    },
    // Phase 4.2 Milestone 5: reuses the shared RoomStatus CLEANING colors (Support/roomStatusBadges.js)
    // so this page and the Housekeeping board never drift out of visual sync.
    cleaning: {
        card: `${roomStatusBadge('CLEANING').card} opacity-80 cursor-default`,
        dot: roomStatusBadge('CLEANING').dot,
        badge: roomStatusBadge('CLEANING').badge,
    },
};

// docs/yeucaumoi.txt mục 7 — when exactly one Booking accounts for the
// room's status (reserved/occupied/overstay), its own color becomes the
// tile's primary background; the semantic state moves to a border-only
// accent instead of owning the background. multi_booking/overlap have no
// single Booking to color by, and available/out_of_order/cleaning aren't
// about a Booking at all — those keep their existing full-tile treatment.
const SINGLE_BOOKING_STATES = ['reserved', 'occupied', 'overstay'];
const SINGLE_BOOKING_BORDER = {
    reserved: 'border-blue-400',
    occupied: 'border-orange-500',
    overstay: 'border-red-600',
};

function hasSingleBookingColor(room) {
    return SINGLE_BOOKING_STATES.includes(room.availability) && !!room.primary_color && room.booking_count === 1;
}

function cardClass(room) {
    if (hasSingleBookingColor(room)) {
        return `${SINGLE_BOOKING_BORDER[room.availability]} cursor-pointer ${readableTextClass(room.primary_color)}`;
    }
    return availabilityStyles[room.availability]?.card ?? 'border-gray-200 bg-white';
}

function cardStyle(room) {
    return hasSingleBookingColor(room) ? { backgroundColor: room.primary_color } : {};
}

function badgeClass(badge) {
    return { many: 'bg-green-100 text-green-800', low: 'bg-amber-100 text-amber-800', none: 'bg-red-100 text-red-800' }[badge] ?? 'bg-gray-100 text-gray-600';
}
</script>

<template>
    <AppLayout>
        <template #header>
            <h1 class="text-lg font-semibold">Kiểm tra phòng trống</h1>
        </template>

        <!-- Filter -->
        <div class="mb-6 flex flex-wrap items-end gap-3 border border-gray-200 bg-white p-4">
            <div>
                <label class="mb-1 block text-xs font-medium text-steel">Từ ngày giờ</label>
                <input
                    v-model="form.start_at"
                    type="datetime-local"
                    class="border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none"
                />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-steel">Đến ngày giờ</label>
                <input
                    v-model="form.end_at"
                    type="datetime-local"
                    class="border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none"
                />
            </div>
            <button
                :disabled="submitting"
                class="bg-pine px-4 py-2 text-sm font-medium text-white hover:bg-pine/90 disabled:opacity-50"
                @click="search"
            >
                Kiểm tra
            </button>
        </div>

        <!-- Range label -->
        <p class="mb-4 text-sm text-steel">
            Kết quả cho: <span class="font-medium text-ink">{{ availability.range.start }}</span> —
            <span class="font-medium text-ink">{{ availability.range.end }}</span>
        </p>

        <!-- Room type summary -->
        <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
            <div
                v-for="rt in availability.room_type_summary"
                :key="rt.room_type_id"
                class="border border-gray-200 bg-white p-3"
            >
                <div class="flex items-center justify-between gap-1">
                    <span class="text-xs font-semibold text-ink">{{ rt.room_type_code }}</span>
                    <span class="px-1.5 py-0.5 text-xs font-semibold" :class="badgeClass(rt.badge)">{{ rt.badge_label }}</span>
                </div>
                <div class="mt-0.5 truncate text-xs text-steel">{{ rt.room_type_name }}</div>
                <div class="mt-2 flex items-baseline gap-1.5">
                    <span class="text-xl font-bold text-ink">{{ rt.remaining }}</span>
                    <span class="text-xs text-steel">/ {{ rt.total - rt.out_of_order }} trống</span>
                </div>
                <div v-if="rt.occupied > 0" class="mt-0.5 text-xs text-steel">{{ rt.occupied }} đã giữ</div>
                <div v-if="rt.out_of_order > 0" class="mt-0.5 text-xs text-steel">{{ rt.out_of_order }} bảo trì</div>
            </div>
        </div>

        <!-- Legend -->
        <div class="mb-4 flex flex-wrap items-center gap-4 text-xs text-steel">
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-green-400"></span>Trống</span>
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-blue-400"></span>Đã giữ phòng</span>
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-orange-500"></span>Đang ở</span>
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-red-600"></span>Quá hạn lưu trú</span>
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-amber-400"></span>Nhiều booking</span>
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-red-500"></span>Xung đột</span>
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-gray-400"></span>Không khả dụng</span>
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-amber-500"></span>Đang dọn</span>
        </div>

        <!-- Floor map -->
        <div v-if="availability.floors.length === 0" class="border border-gray-200 bg-white p-8 text-center text-sm text-steel">
            Chưa có tầng hoặc phòng nào được cấu hình.
        </div>
        <div v-else class="space-y-1.5">
            <div v-for="floor in availability.floors" :key="floor.floor_id" class="flex flex-nowrap items-center gap-2 overflow-x-auto border border-gray-200 bg-white px-3 py-2">
                <div class="shrink-0 w-14 border-r border-gray-200 pr-2 text-xs font-semibold uppercase tracking-wide text-steel">
                    {{ floor.floor_name }}
                </div>
                <button
                    v-for="room in floor.rooms"
                    :key="room.room_id"
                    type="button"
                    class="relative shrink-0 min-w-[80px] border p-2 text-left transition"
                    :class="cardClass(room)"
                    :style="cardStyle(room)"
                    @click="selectRoom(room)"
                >
                    <div class="flex items-start justify-between gap-1">
                        <span class="text-sm font-semibold leading-tight">{{ room.room_number }}</span>
                        <span v-if="room.has_overlap" class="shrink-0 text-xs leading-tight">🚨</span>
                        <span v-else-if="room.booking_count > 1" class="shrink-0 text-xs font-bold leading-tight text-amber-700">×{{ room.booking_count }}</span>
                    </div>
                    <div
                        v-if="pendingRequestCounts[room.room_id] > 0"
                        class="mt-1 inline-flex items-center rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800"
                        title="Yêu cầu đặc biệt đang chờ"
                    >{{ pendingRequestCounts[room.room_id] }} yc</div>
                    <div class="mt-0.5 truncate text-xs" :class="hasSingleBookingColor(room) ? 'opacity-80' : 'text-steel'">{{ room.room_type_code }}</div>
                    <!-- multi_booking/overlap have no single Booking to color the whole
                         tile by (mục 7) — a color hint bar is the fallback for those. -->
                    <div
                        v-if="room.primary_color && !hasSingleBookingColor(room) && room.availability !== 'available' && room.availability !== 'out_of_order' && room.availability !== 'cleaning'"
                        class="mt-1.5 h-1 w-full rounded-full"
                        :style="{ backgroundColor: room.primary_color }"
                    ></div>
                </button>
            </div>
        </div>

        <!-- Room detail modal -->
        <div
            v-if="selectedRoom"
            class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center"
            @click.self="closeDetail"
        >
            <div class="w-full max-w-md overflow-y-auto border border-gray-200 bg-white shadow-xl sm:max-h-[80vh]">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                    <div class="flex items-baseline gap-2">
                        <span class="text-base font-semibold">Phòng {{ selectedRoom.room_number }}</span>
                        <span class="text-sm text-steel">{{ selectedRoom.room_type_name }}</span>
                    </div>
                    <button type="button" class="text-steel hover:text-ink" @click="closeDetail">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="p-4">
                    <div class="mb-3 flex items-center gap-2">
                        <span
                            class="inline-block px-2 py-0.5 text-xs font-semibold"
                            :class="availabilityStyles[selectedRoom.availability]?.badge"
                        >
                            {{ selectedRoom.availability_label }}
                        </span>
                        <span v-if="selectedRoom.room_status_label" class="text-xs text-steel">{{ selectedRoom.room_status_label }}</span>
                    </div>

                    <div v-if="selectedRoom.bookings.length === 0" class="py-6 text-center text-sm text-steel">
                        Phòng trống trong khoảng thời gian này.
                    </div>
                    <div v-else class="space-y-3">
                        <div
                            v-for="bk in selectedRoom.bookings"
                            :key="bk.booking_id"
                            class="border border-gray-200 p-3"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="h-3 w-3 shrink-0 rounded-full"
                                        :style="{ backgroundColor: bk.booking_color }"
                                    ></span>
                                    <span class="text-sm font-medium">{{ bk.booking_code }}</span>
                                </div>
                                <a
                                    v-if="can.viewBooking"
                                    :href="`/admin/bookings/${bk.booking_id}`"
                                    class="shrink-0 text-xs text-pine underline hover:no-underline"
                                >
                                    Xem booking
                                </a>
                            </div>
                            <div class="mt-1 text-xs text-steel">{{ bk.customer_name }}</div>
                            <div class="mt-2 grid grid-cols-2 gap-x-3 text-xs">
                                <div>
                                    <span class="text-steel">Nhận phòng: </span>
                                    <span class="font-medium">{{ bk.checkin_at }}</span>
                                </div>
                                <div>
                                    <span class="text-steel">Trả phòng: </span>
                                    <span class="font-medium">{{ bk.checkout_at }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
