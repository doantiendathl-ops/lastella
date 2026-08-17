<script setup>
import RoomFloorGrid from '@/Components/RoomBoard/RoomFloorGrid.vue';
import RoomTile from '@/Components/RoomBoard/RoomTile.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle, ClipboardCheck, Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import CheckoutInspectionModal from './Partials/CheckoutInspectionModal.vue';

const props = defineProps({
    floors: { type: Array, required: true },
    products: { type: Array, required: true },
    can: { type: Object, required: true },
});

const search = ref('');
const activeStay = ref(null);

// docs/yeucaumoi.txt mục 9 — inspection status is conveyed via the badge in
// RoomTile's status-row slot, NOT via the tile's border/background (that's
// reserved for the Booking color, same principle as the other 3 Room Map
// screens). Only the badge color survives from the old card/badge pair.
const statusStyles = {
    NONE: { badge: 'bg-gray-100 text-steel' },
    DRAFT: { badge: 'bg-amber-100 text-amber-700' },
    COMPLETED_CHARGE: { badge: 'bg-pine/10 text-pine' },
    COMPLETED_NO_CHARGE: { badge: 'bg-gray-100 text-steel' },
};

const inspectionState = (stay) => {
    if (!stay.inspection) return 'NONE';
    if (stay.inspection.status === 'DRAFT') return 'DRAFT';
    return stay.inspection.total_amount > 0 ? 'COMPLETED_CHARGE' : 'COMPLETED_NO_CHARGE';
};

const inspectionLabel = (stay) => ({
    NONE: 'Chưa kiểm',
    DRAFT: 'Đang kiểm',
    COMPLETED_CHARGE: 'Có phát sinh',
    COMPLETED_NO_CHARGE: 'Không phát sinh',
}[inspectionState(stay)]);

const filteredFloors = computed(() => props.floors
    .map((floor) => ({
        ...floor,
        rooms: floor.rooms.filter((stay) => search.value === '' || stay.room_number.toLowerCase().includes(search.value.toLowerCase()) || (stay.guest_name ?? '').toLowerCase().includes(search.value.toLowerCase())),
    }))
    .filter((floor) => floor.rooms.length > 0));

const openInspection = (stay) => {
    activeStay.value = stay;
};

const closeModal = () => {
    activeStay.value = null;
};

const onSuccess = () => {
    router.reload({ only: ['floors'] });
};

const formatTime = (value) => {
    if (!value) return '—';
    return new Date(value).toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' });
};
</script>

<template>
    <AppLayout>
        <Head title="Kiểm đồ trả phòng" />

        <template #header>
            <h1 class="truncate text-lg font-semibold">Kiểm đồ nhanh khi trả phòng</h1>
        </template>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <div class="relative min-w-56 flex-1">
                <Search class="pointer-events-none absolute left-2 top-2.5 h-4 w-4 text-steel" />
                <input v-model="search" type="search" placeholder="Tìm theo số phòng hoặc tên khách..." class="w-full border border-gray-300 py-2 pl-8 pr-2 text-sm" />
            </div>
            <div class="flex items-center gap-3 text-xs text-steel">
                <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-amber-400"></span> Đang kiểm</span>
                <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-pine"></span> Có phát sinh</span>
                <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-gray-300"></span> Không phát sinh / chưa kiểm</span>
            </div>
        </div>

        <!-- docs/yeucaumoi.txt mục 6 — same shared RoomTile/RoomFloorGrid shell as
             Sơ đồ thao tác: booking_color as background, inspection status moved
             into the status-row badge instead of owning the tile's border/bg. -->
        <RoomFloorGrid :floors="filteredFloors" :get-key="(stay) => stay.stay_id" empty-message="Không có phòng đang lưu trú phù hợp.">
            <template #card="{ room: stay }">
                <RoomTile
                    :room-number="stay.room_number"
                    :room-type-label="stay.room_type"
                    :booking-color="stay.booking_color"
                >
                    <template #body>
                        <div class="space-y-0.5">
                            <div class="flex items-start justify-between gap-1">
                                <span class="truncate">{{ stay.guest_name ?? 'Khách lẻ' }}</span>
                                <span v-if="stay.is_overdue" title="Quá giờ trả phòng" aria-label="Quá giờ trả phòng" class="shrink-0">
                                    <AlertTriangle class="h-3.5 w-3.5 text-coral" />
                                </span>
                            </div>
                            <div class="text-[10px] opacity-80">Trả: {{ formatTime(stay.planned_checkout_at) }}</div>
                        </div>
                    </template>

                    <template #status-row>
                        <div class="flex flex-wrap items-center gap-1.5 rounded bg-white/75 px-1 py-1 text-gray-900">
                            <span class="inline-flex w-fit rounded px-1.5 py-0.5 text-[11px] font-semibold" :class="statusStyles[inspectionState(stay)].badge">
                                {{ inspectionLabel(stay) }}
                            </span>
                            <span v-if="stay.inspection && stay.inspection.total_amount > 0" class="text-[11px] font-semibold">
                                {{ new Intl.NumberFormat('vi-VN').format(stay.inspection.total_amount) }} đ
                            </span>
                        </div>
                    </template>

                    <template #footer>
                        <button
                            v-if="can.perform"
                            type="button"
                            class="inline-flex min-h-11 w-full items-center justify-center gap-1.5 rounded border border-pine bg-white/90 px-2 py-2 text-xs font-semibold text-pine hover:bg-pine hover:text-white"
                            @click="openInspection(stay)"
                        >
                            <ClipboardCheck class="h-3.5 w-3.5" />
                            {{ inspectionState(stay) === 'NONE' ? 'Kiểm đồ' : 'Chi tiết' }}
                        </button>
                        <Link v-else-if="stay.booking_id" :href="`/admin/bookings/${stay.booking_id}`" class="block rounded bg-white/75 px-1 py-1 text-center text-[11px] text-steel underline">
                            Xem booking
                        </Link>
                    </template>
                </RoomTile>
            </template>
        </RoomFloorGrid>

        <CheckoutInspectionModal
            v-if="activeStay"
            :stay="activeStay"
            :products="products"
            @close="closeModal"
            @success="onSuccess"
        />
    </AppLayout>
</template>
