<script setup>
import RoomBoardGrid from '@/Components/RoomBoard/RoomBoardGrid.vue';
import RoomBulkActionBar from '@/Components/RoomBoard/RoomBulkActionBar.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { Plus, Search } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    floors: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
    floorOptions: { type: Array, required: true },
    roomTypeOptions: { type: Array, required: true },
    statusOptions: { type: Array, required: true },
    can: { type: Object, required: true },
});

const query = reactive({
    search: props.filters.search ?? '',
    floor_id: props.filters.floor_id ?? '',
    room_type_id: props.filters.room_type_id ?? '',
    status: props.filters.status ?? '',
});

const runSearch = () => {
    const cleaned = Object.fromEntries(Object.entries(query).filter(([, v]) => v !== '' && v !== null));
    router.get('/rooms', cleaned, { preserveState: true, replace: true });
};

const resetFilters = () => {
    query.search = '';
    query.floor_id = '';
    query.room_type_id = '';
    query.status = '';
    runSearch();
};

const statusColors = {
    VACANT_CLEAN: 'border-green-300 bg-green-50',
    VACANT_DIRTY: 'border-amber-300 bg-amber-50',
    OCCUPIED: 'border-blue-300 bg-blue-50',
    RESERVED: 'border-purple-300 bg-purple-50',
    CLEANING: 'border-sky-300 bg-sky-50',
    INSPECTED: 'border-teal-300 bg-teal-50',
    OUT_OF_ORDER: 'border-coral bg-red-50',
    OUT_OF_SERVICE: 'border-gray-400 bg-gray-100',
};

const selectedIds = ref(new Set());
const selectedCount = computed(() => selectedIds.value.size);

const allVisibleRooms = computed(() => props.floors.flatMap((f) => f.rooms));

const isSelected = (room) => selectedIds.value.has(room.id);

const toggleRoom = (room) => {
    const next = new Set(selectedIds.value);
    if (next.has(room.id)) {
        next.delete(room.id);
    } else {
        next.add(room.id);
    }
    selectedIds.value = next;
};

const selectEligible = (rooms) => {
    const next = new Set(selectedIds.value);
    rooms.filter((r) => r.is_eligible_for_bulk).forEach((r) => next.add(r.id));
    selectedIds.value = next;
};

const selectAllVisible = () => selectEligible(allVisibleRooms.value);
const selectFloor = (floor) => selectEligible(floor.rooms);
const clearSelection = () => { selectedIds.value = new Set(); };

const processing = ref(false);
const resultMessage = ref('');

const runBulkAction = async (url, extraPayload) => {
    processing.value = true;
    resultMessage.value = '';
    try {
        const response = await axios.patch(url, { room_ids: Array.from(selectedIds.value), ...extraPayload });
        const { succeeded, failed } = response.data;
        resultMessage.value = `Thành công ${succeeded.length} phòng` + (failed.length > 0 ? `, thất bại ${failed.length} phòng: ${failed.map((f) => `${f.room_number ?? f.room_id} (${f.reason})`).join('; ')}` : '.');
        clearSelection();
        router.reload({ only: ['floors'] });
    } catch (error) {
        resultMessage.value = error.response?.data?.message ?? 'Có lỗi xảy ra.';
    } finally {
        processing.value = false;
    }
};

// Inline confirm panel instead of window.confirm/prompt — native dialogs block all further
// page interaction until dismissed, which is worse UX than the custom modals used elsewhere
// in this app (HousekeepingActionDialog, CheckoutInspectionModal), so bulk maintenance actions
// follow the same pattern.
const pendingBulkAction = ref(null); // 'out-of-order' | 'release' | null
const bulkReason = ref('');

const openMarkOutOfOrder = () => {
    bulkReason.value = '';
    pendingBulkAction.value = 'out-of-order';
};

const openReleaseFromMaintenance = () => {
    pendingBulkAction.value = 'release';
};

const cancelBulkAction = () => {
    pendingBulkAction.value = null;
};

const confirmBulkAction = () => {
    if (pendingBulkAction.value === 'out-of-order') {
        if (!bulkReason.value.trim()) return;
        pendingBulkAction.value = null;
        runBulkAction('/rooms/bulk/out-of-order', { reason: bulkReason.value.trim() });
    } else if (pendingBulkAction.value === 'release') {
        pendingBulkAction.value = null;
        runBulkAction('/rooms/bulk/release', {});
    }
};
</script>

<template>
    <AppLayout>
        <Head title="Phòng" />

        <template #header>
            <div class="flex min-w-0 items-center justify-between gap-4">
                <h1 class="truncate text-lg font-semibold">Sơ đồ phòng</h1>
                <Link
                    v-if="can.create"
                    href="/rooms/create"
                    class="inline-flex items-center gap-2 bg-pine px-3 py-2 text-sm font-semibold text-white transition hover:bg-ink"
                >
                    <Plus class="h-4 w-4" />
                    Tạo phòng
                </Link>
            </div>
        </template>

        <form class="mb-4 flex flex-wrap items-end gap-3 border border-gray-200 bg-white p-4" @submit.prevent="runSearch">
            <div class="min-w-40 flex-1">
                <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Số phòng</label>
                <div class="mt-1 flex">
                    <input v-model="query.search" type="search" class="min-w-0 flex-1 border border-gray-300 px-3 py-2 text-sm" />
                    <button class="inline-flex w-10 items-center justify-center bg-pine text-white" type="submit"><Search class="h-4 w-4" /></button>
                </div>
            </div>
            <div class="min-w-40">
                <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Tầng</label>
                <select v-model="query.floor_id" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" @change="runSearch">
                    <option value="">Tất cả</option>
                    <option v-for="opt in floorOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
            </div>
            <div class="min-w-40">
                <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Loại phòng</label>
                <select v-model="query.room_type_id" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" @change="runSearch">
                    <option value="">Tất cả</option>
                    <option v-for="opt in roomTypeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
            </div>
            <div class="min-w-40">
                <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Trạng thái</label>
                <select v-model="query.status" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" @change="runSearch">
                    <option value="">Tất cả</option>
                    <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
            </div>
            <button type="button" class="h-10 border border-gray-300 px-3 text-sm text-steel hover:text-ink" @click="resetFilters">Đặt lại</button>
            <button v-if="can.bulkUpdate" type="button" class="h-10 border border-gray-300 px-3 text-sm text-steel hover:text-ink" @click="selectAllVisible">
                Chọn tất cả phòng đủ điều kiện
            </button>
        </form>

        <RoomBulkActionBar v-if="can.bulkUpdate" :selected-count="selectedCount" @clear="clearSelection">
            <button type="button" class="border border-coral px-3 py-1.5 text-xs font-semibold text-coral hover:bg-coral hover:text-white disabled:opacity-50" :disabled="processing" @click="openMarkOutOfOrder">
                Đưa vào bảo trì
            </button>
            <button type="button" class="border border-pine px-3 py-1.5 text-xs font-semibold text-pine hover:bg-pine hover:text-white disabled:opacity-50" :disabled="processing" @click="openReleaseFromMaintenance">
                Mở lại sau bảo trì
            </button>
        </RoomBulkActionBar>

        <div v-if="pendingBulkAction" class="mb-3 border border-gray-300 bg-white p-4">
            <p v-if="pendingBulkAction === 'out-of-order'" class="text-sm font-semibold text-ink">
                Xác nhận đưa {{ selectedCount }} phòng vào bảo trì
            </p>
            <p v-else class="text-sm font-semibold text-ink">
                Xác nhận mở lại {{ selectedCount }} phòng sau bảo trì
            </p>
            <textarea
                v-if="pendingBulkAction === 'out-of-order'"
                v-model="bulkReason"
                rows="2"
                placeholder="Lý do đưa vào bảo trì/sửa chữa (bắt buộc)..."
                class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm"
            />
            <div class="mt-3 flex justify-end gap-2">
                <button type="button" class="border border-gray-300 px-3 py-1.5 text-xs font-semibold text-steel hover:text-ink" @click="cancelBulkAction">Hủy</button>
                <button
                    type="button"
                    class="border border-pine bg-pine px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50"
                    :disabled="pendingBulkAction === 'out-of-order' && !bulkReason.trim()"
                    @click="confirmBulkAction"
                >
                    Xác nhận
                </button>
            </div>
        </div>

        <p v-if="resultMessage" class="mb-3 border border-gray-200 bg-white px-4 py-2 text-sm text-ink">{{ resultMessage }}</p>

        <RoomBoardGrid :floors="floors" empty-message="Không có phòng phù hợp với bộ lọc.">
            <template #card="{ room, floor }">
                <div class="flex flex-col gap-1 border p-2 text-xs" :class="statusColors[room.status] ?? 'border-gray-200 bg-white'">
                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-1.5">
                            <input
                                v-if="can.bulkUpdate"
                                type="checkbox"
                                class="h-3.5 w-3.5"
                                :checked="isSelected(room)"
                                @change="toggleRoom(room)"
                            />
                            <span class="text-sm font-semibold text-ink">{{ room.room_number }}</span>
                        </label>
                        <Link :href="`/rooms/${room.id}/edit`" class="text-[10px] text-steel underline hover:text-pine">Sửa</Link>
                    </div>
                    <span class="text-steel">{{ room.room_type }}</span>
                    <span class="font-medium text-ink">{{ room.status_label }}</span>
                    <span v-if="room.is_maintenance" class="inline-flex w-fit rounded bg-coral/10 px-1.5 py-0.5 text-[10px] font-semibold text-coral">
                        Bảo trì/sửa chữa
                    </span>
                </div>
            </template>
        </RoomBoardGrid>

        <div v-if="can.bulkUpdate" class="mt-4 flex flex-wrap gap-3 text-xs text-steel">
            <span>Chọn nhanh theo tầng:</span>
            <button v-for="floor in floors" :key="floor.id" type="button" class="underline hover:text-pine" @click="selectFloor(floor)">
                {{ floor.code }}
            </button>
        </div>
    </AppLayout>
</template>
