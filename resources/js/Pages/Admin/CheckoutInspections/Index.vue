<script setup>
import RoomBoardGrid from '@/Components/RoomBoard/RoomBoardGrid.vue';
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

const statusStyles = {
    NONE: { card: 'border-gray-200 bg-white', badge: 'bg-gray-100 text-steel' },
    DRAFT: { card: 'border-amber-300 bg-amber-50', badge: 'bg-amber-100 text-amber-700' },
    COMPLETED_CHARGE: { card: 'border-pine bg-linen', badge: 'bg-pine/10 text-pine' },
    COMPLETED_NO_CHARGE: { card: 'border-gray-300 bg-gray-50', badge: 'bg-gray-100 text-steel' },
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

        <RoomBoardGrid :floors="filteredFloors" :get-key="(stay) => stay.stay_id" empty-message="Không có phòng đang lưu trú phù hợp.">
            <template #card="{ room: stay }">
                <div class="flex flex-col gap-1 border p-2 text-xs" :class="statusStyles[inspectionState(stay)].card">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-semibold text-ink">{{ stay.room_number }}</span>
                        <span v-if="stay.is_overdue" title="Quá giờ trả phòng"><AlertTriangle class="h-3.5 w-3.5 text-coral" /></span>
                    </div>
                    <span class="truncate text-steel">{{ stay.guest_name ?? 'Khách lẻ' }}</span>
                    <span class="text-steel">Trả: {{ formatTime(stay.planned_checkout_at) }}</span>
                    <span class="inline-flex w-fit rounded px-1.5 py-0.5 text-[11px] font-semibold" :class="statusStyles[inspectionState(stay)].badge">
                        {{ inspectionLabel(stay) }}
                    </span>
                    <span v-if="stay.inspection && stay.inspection.total_amount > 0" class="font-semibold text-ink">
                        {{ new Intl.NumberFormat('vi-VN').format(stay.inspection.total_amount) }} đ
                    </span>
                    <button
                        v-if="can.perform"
                        type="button"
                        class="mt-1 inline-flex min-h-11 items-center justify-center gap-1.5 border border-pine px-2 py-2 text-xs font-semibold text-pine hover:bg-pine hover:text-white"
                        @click="openInspection(stay)"
                    >
                        <ClipboardCheck class="h-3.5 w-3.5" />
                        {{ inspectionState(stay) === 'NONE' ? 'Kiểm đồ' : 'Chi tiết' }}
                    </button>
                    <Link v-else-if="stay.booking_id" :href="`/admin/bookings/${stay.booking_id}`" class="mt-1 text-center text-[11px] text-steel underline">
                        Xem booking
                    </Link>
                </div>
            </template>
        </RoomBoardGrid>

        <CheckoutInspectionModal
            v-if="activeStay"
            :stay="activeStay"
            :products="products"
            @close="closeModal"
            @success="onSuccess"
        />
    </AppLayout>
</template>
