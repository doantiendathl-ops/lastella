<script setup>
import RoomBoardGrid from '@/Components/RoomBoard/RoomBoardGrid.vue';
import RoomBulkActionBar from '@/Components/RoomBoard/RoomBulkActionBar.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { housekeepingAssignmentStatusLabels, cleaningPriorityLabels } from '@/Support/vietnameseLabels';
import { roomStatusBadge } from '@/Support/roomStatusBadges';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { AlertTriangle, ClipboardList } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import HousekeepingActionDialog from './Partials/HousekeepingActionDialog.vue';
import HousekeepingDetailModal from './Partials/HousekeepingDetailModal.vue';

const props = defineProps({
    floors: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

function actionsFor(room) {
    const actions = [];
    const assignment = room.active_assignment;

    if (room.status === 'VACANT_DIRTY' && !assignment && props.can.assign) {
        actions.push({ key: 'assign', label: 'Chờ dọn' });
    }
    if (assignment?.status === 'pending' && props.can.updateStatus) {
        actions.push({ key: 'start', label: 'Đang dọn' });
    }
    if (assignment?.status === 'in_progress' && props.can.updateStatus) {
        actions.push({ key: 'complete', label: 'Đã xong' });
    }
    if (room.status === 'INSPECTED' && props.can.inspect) {
        actions.push({ key: 'pass', label: 'Đạt' });
        actions.push({ key: 'fail', label: 'Không đạt' });
        actions.push({ key: 'skip', label: 'Bỏ qua' });
    }
    if (room.status === 'OUT_OF_ORDER' && props.can.maintenance) {
        actions.push({ key: 'release', label: 'Mở khóa' });
    } else if (room.status !== 'CLEANING' && props.can.maintenance) {
        actions.push({ key: 'outOfOrder', label: 'Khóa bảo trì' });
    }

    return actions;
}

const dialog = ref(null); // { action, room }
const detailRoom = ref(null);

function openDialog(action, room) {
    dialog.value = { action, room };
}

function closeDialog() {
    dialog.value = null;
}

function refresh() {
    router.reload({ only: ['floors', 'can'] });
}

// ---- Selection & bulk actions ----------------------------------------------
const selectedIds = ref(new Set());
const selectedCount = computed(() => selectedIds.value.size);
const allVisibleRooms = computed(() => props.floors.flatMap((f) => f.rooms));

const isSelected = (room) => selectedIds.value.has(room.id);

const toggleRoom = (room) => {
    const next = new Set(selectedIds.value);
    next.has(room.id) ? next.delete(room.id) : next.add(room.id);
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

const runBulkAction = async (url) => {
    processing.value = true;
    resultMessage.value = '';
    try {
        const response = await axios.patch(url, { room_ids: Array.from(selectedIds.value) });
        const { succeeded, failed } = response.data;
        resultMessage.value = `Thành công ${succeeded.length} phòng` + (failed.length > 0 ? `, thất bại ${failed.length} phòng: ${failed.map((f) => `${f.room_number ?? f.room_id} (${f.reason})`).join('; ')}` : '.');
        clearSelection();
        refresh();
    } catch (error) {
        // No response at all (offline/timeout) vs. a server error response are different
        // failure modes — never claim success, and never silently retry a charge/status action.
        resultMessage.value = error.response
            ? (error.response.data?.message ?? 'Có lỗi xảy ra.')
            : 'Mất kết nối, thao tác chưa được lưu.';
    } finally {
        processing.value = false;
    }
};

const bulkAssign = () => runBulkAction(route('admin.housekeeping.bulk.assign'));
const bulkStart = () => runBulkAction(route('admin.housekeeping.bulk.start'));
const bulkComplete = () => runBulkAction(route('admin.housekeeping.bulk.complete'));
</script>

<template>
    <AppLayout>
        <template #header>
            <h1 class="text-lg font-semibold">Dọn phòng</h1>
        </template>

        <div v-if="can.assign || can.updateStatus" class="mb-3 flex flex-wrap items-center gap-2 text-xs">
            <span class="text-steel">Chọn nhanh:</span>
            <button type="button" class="min-h-9 border border-gray-300 px-3 py-1.5 text-steel hover:text-ink" @click="selectAllVisible">Tất cả phòng đủ điều kiện</button>
            <button v-for="floor in floors" :key="floor.id" type="button" class="min-h-9 border border-gray-300 px-3 py-1.5 text-steel hover:text-ink" @click="selectFloor(floor)">
                Tầng {{ floor.code }}
            </button>
        </div>

        <!-- sticky on mobile so the bar stays reachable without covering the card grid -->
        <RoomBulkActionBar :selected-count="selectedCount" @clear="clearSelection">
            <button v-if="can.assign" type="button" class="min-h-11 border border-gray-400 px-4 py-2.5 text-sm font-semibold text-ink hover:bg-gray-100 disabled:opacity-50" :disabled="processing" @click="bulkAssign">
                Chờ dọn
            </button>
            <button v-if="can.updateStatus" type="button" class="min-h-11 border border-sky-500 px-4 py-2.5 text-sm font-semibold text-sky-600 hover:bg-sky-50 disabled:opacity-50" :disabled="processing" @click="bulkStart">
                Đang dọn
            </button>
            <button v-if="can.updateStatus" type="button" class="min-h-11 border border-pine px-4 py-2.5 text-sm font-semibold text-pine hover:bg-pine hover:text-white disabled:opacity-50" :disabled="processing" @click="bulkComplete">
                Đã xong
            </button>
        </RoomBulkActionBar>

        <p v-if="resultMessage" class="mb-3 border border-gray-200 bg-white px-4 py-2 text-sm text-ink">{{ resultMessage }}</p>

        <RoomBoardGrid :floors="floors" empty-message="Chưa có phòng nào được cấu hình.">
            <template #card="{ room }">
                <div class="flex flex-col gap-1 border p-2 text-xs transition" :class="roomStatusBadge(room.status).card">
                    <div class="flex items-start justify-between gap-1">
                        <label class="flex items-center gap-1.5">
                            <input
                                v-if="can.assign || can.updateStatus"
                                type="checkbox"
                                class="h-3.5 w-3.5"
                                :checked="isSelected(room)"
                                @change="toggleRoom(room)"
                            />
                            <span class="text-sm font-semibold leading-tight text-ink">{{ room.room_number }}</span>
                        </label>
                        <span class="shrink-0 px-1.5 py-0.5 text-[10px] font-semibold" :class="roomStatusBadge(room.status).badge">
                            {{ room.status_label }}
                        </span>
                    </div>

                    <span v-if="room.guest_name" class="truncate text-steel">{{ room.guest_name }}</span>

                    <div class="flex flex-wrap gap-1">
                        <span v-if="room.is_checkout_today" class="rounded bg-amber-100 px-1 py-0.5 text-[10px] font-semibold text-amber-700">Trả hôm nay</span>
                        <span v-if="room.is_checkin_today" class="rounded bg-blue-100 px-1 py-0.5 text-[10px] font-semibold text-blue-700">Đến hôm nay</span>
                        <span v-if="room.special_request_count > 0" class="inline-flex items-center gap-0.5 rounded bg-coral/10 px-1 py-0.5 text-[10px] font-semibold text-coral">
                            <AlertTriangle class="h-2.5 w-2.5" /> YC đặc biệt ({{ room.special_request_count }})
                        </span>
                    </div>

                    <div v-if="room.active_assignment" class="space-y-0.5 text-[11px] text-steel">
                        <div v-if="room.active_assignment.assigned_to"><span class="text-gray-400">NV: </span>{{ room.active_assignment.assigned_to }}</div>
                        <div><span class="text-gray-400">Ưu tiên: </span>{{ cleaningPriorityLabels[room.active_assignment.priority] ?? room.active_assignment.priority }}</div>
                    </div>

                    <div v-if="room.last_cleaned_at" class="text-[10px] text-gray-400">Dọn lần cuối: {{ room.last_cleaned_at }}</div>

                    <!-- grid-cols-2 (not flex-wrap) so every button gets a full 44px-tall tap
                         target instead of shrinking to fit a crowded row on a phone. -->
                    <div class="mt-1 grid grid-cols-2 gap-1.5">
                        <button
                            v-for="action in actionsFor(room)"
                            :key="action.key"
                            type="button"
                            class="min-h-11 border border-gray-300 bg-white px-2 py-2 text-xs font-semibold text-ink hover:border-pine hover:text-pine"
                            @click="openDialog(action.key, room)"
                        >
                            {{ action.label }}
                        </button>
                        <button
                            type="button"
                            class="col-span-2 inline-flex min-h-11 items-center justify-center gap-1 border border-gray-300 bg-white px-2 py-2 text-xs font-semibold text-steel hover:border-pine hover:text-pine"
                            @click="detailRoom = room"
                        >
                            <ClipboardList class="h-3.5 w-3.5" /> Chi tiết
                        </button>
                    </div>
                </div>
            </template>
        </RoomBoardGrid>

        <HousekeepingActionDialog
            v-if="dialog"
            :action="dialog.action"
            :room="dialog.room"
            @close="closeDialog"
            @success="refresh"
        />

        <HousekeepingDetailModal
            v-if="detailRoom"
            :room="detailRoom"
            @close="detailRoom = null"
        />
    </AppLayout>
</template>
