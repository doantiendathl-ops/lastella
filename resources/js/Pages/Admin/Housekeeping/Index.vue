<script setup>
import RoomBoardGrid from '@/Components/RoomBoard/RoomBoardGrid.vue';
import RoomBulkActionBar from '@/Components/RoomBoard/RoomBulkActionBar.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { roomStatusBadge, cleaningStatusBadge } from '@/Support/roomStatusBadges';
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

// ---- Legacy multi-step actions (assign/start/complete/inspect/maintenance) --
// Kept working end-to-end for anyone who explicitly opens them from the Detail
// popup's "Nâng cao" section — the primary card no longer exposes them.
//
// Final Consistency Review: HOUSEKEEPING still holds housekeeping.assign/
// room.status.update (kept so the backend/API stay backward-compatible — nothing
// in HousekeepingService or its routes was narrowed), but the UI must not surface
// the multi-step workflow to that role — real housekeeping staff should only ever
// see Đánh dấu sạch/bẩn + Chi tiết. `canSeeAdvanced` gates the WHOLE "Nâng cao"
// section on can.inspect/can.maintenance instead — both are true only for
// ADMIN/MANAGER, so this naturally hides advanced actions for HOUSEKEEPING and
// RECEPTION without touching any backend permission.
const canSeeAdvanced = computed(() => props.can.inspect || props.can.maintenance);

function legacyActionsFor(room) {
    if (!canSeeAdvanced.value) {
        return [];
    }

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
    detailRoom.value = null;
    dialog.value = { action, room };
}

function closeDialog() {
    dialog.value = null;
}

function refresh() {
    router.reload({ only: ['floors', 'can'] });
}

// ---- One-tap ĐÁNH DẤU SẠCH / ĐÁNH DẤU BẨN ----------------------------------
const processingRoomIds = ref(new Set());
const isProcessing = (room) => processingRoomIds.value.has(room.id);

const toasts = ref([]);
let toastSeq = 0;
function pushToast(message, variant = 'success') {
    const id = ++toastSeq;
    toasts.value = [...toasts.value, { id, message, variant }];
    setTimeout(() => {
        toasts.value = toasts.value.filter((t) => t.id !== id);
    }, 3000);
}

async function markRoom(room, action) {
    // Guards against double-click/double-submit — the button is also disabled
    // while processing, this is the belt-and-suspenders check.
    if (isProcessing(room)) return;

    const next = new Set(processingRoomIds.value);
    next.add(room.id);
    processingRoomIds.value = next;

    const url = action === 'clean'
        ? route('admin.housekeeping.mark-clean', room.id)
        : route('admin.housekeeping.mark-dirty', room.id);

    try {
        await axios.patch(url);
        pushToast(`Phòng ${room.room_number}: đã đánh dấu ${action === 'clean' ? 'sạch' : 'bẩn'}.`);
        refresh();
    } catch (error) {
        // No response at all (offline/timeout) vs. a server error response are different
        // failure modes — never claim success, and never silently retry a status action.
        const message = error.response
            ? (error.response.data?.message ?? 'Có lỗi xảy ra.')
            : 'Mất kết nối, thao tác chưa được lưu.';
        pushToast(message, 'error');
    } finally {
        const cleared = new Set(processingRoomIds.value);
        cleared.delete(room.id);
        processingRoomIds.value = cleared;
    }
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
        resultMessage.value = error.response
            ? (error.response.data?.message ?? 'Có lỗi xảy ra.')
            : 'Mất kết nối, thao tác chưa được lưu.';
    } finally {
        processing.value = false;
    }
};

const bulkMarkClean = () => runBulkAction(route('admin.housekeeping.bulk.mark-clean'));
const bulkMarkDirty = () => runBulkAction(route('admin.housekeeping.bulk.mark-dirty'));
</script>

<template>
    <AppLayout>
        <template #header>
            <h1 class="text-lg font-semibold">Dọn phòng</h1>
        </template>

        <div v-if="can.markCleaning" class="mb-3 flex flex-wrap items-center gap-2 text-xs">
            <span class="text-steel">Chọn nhanh:</span>
            <button type="button" class="min-h-9 border border-gray-300 px-3 py-1.5 text-steel hover:text-ink" @click="selectAllVisible">Tất cả phòng đủ điều kiện</button>
            <button v-for="floor in floors" :key="floor.id" type="button" class="min-h-9 border border-gray-300 px-3 py-1.5 text-steel hover:text-ink" @click="selectFloor(floor)">
                Tầng {{ floor.code }}
            </button>
        </div>

        <!-- sticky on mobile so the bar stays reachable without covering the card grid -->
        <RoomBulkActionBar :selected-count="selectedCount" @clear="clearSelection">
            <button v-if="can.markCleaning" type="button" class="min-h-11 border border-pine bg-pine px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50" :disabled="processing" @click="bulkMarkClean">
                Đánh dấu sạch
            </button>
            <button v-if="can.markCleaning" type="button" class="min-h-11 border border-gray-400 px-4 py-2.5 text-sm font-semibold text-ink hover:bg-gray-100 disabled:opacity-50" :disabled="processing" @click="bulkMarkDirty">
                Đánh dấu bẩn
            </button>
        </RoomBulkActionBar>

        <p v-if="resultMessage" class="mb-3 border border-gray-200 bg-white px-4 py-2 text-sm text-ink">{{ resultMessage }}</p>

        <RoomBoardGrid :floors="floors" empty-message="Chưa có phòng nào được cấu hình.">
            <template #card="{ room }">
                <div
                    class="flex flex-col gap-1 border p-2 text-xs transition"
                    :class="room.is_maintenance ? roomStatusBadge(room.status).card : cleaningStatusBadge(room.cleaning_status).card"
                >
                    <div class="flex items-start justify-between gap-1">
                        <label class="flex items-center gap-1.5">
                            <input
                                v-if="can.markCleaning"
                                type="checkbox"
                                class="h-3.5 w-3.5"
                                :disabled="!room.is_eligible_for_bulk"
                                :checked="isSelected(room)"
                                @change="toggleRoom(room)"
                            />
                            <span class="text-sm font-semibold leading-tight text-ink">{{ room.room_number }}</span>
                        </label>
                        <div class="flex shrink-0 flex-col items-end gap-0.5">
                            <span
                                class="px-1.5 py-0.5 text-[10px] font-semibold"
                                :class="room.is_maintenance ? roomStatusBadge(room.status).badge : cleaningStatusBadge(room.cleaning_status).badge"
                            >
                                {{ room.is_maintenance ? room.status_label : room.cleaning_status_label }}
                            </span>
                            <span class="text-[10px] text-steel">{{ room.operational_status_label }}</span>
                        </div>
                    </div>

                    <span v-if="room.guest_name" class="truncate text-steel">{{ room.guest_name }}</span>

                    <div class="flex flex-wrap gap-1">
                        <span v-if="room.is_checkout_today" class="rounded bg-amber-100 px-1 py-0.5 text-[10px] font-semibold text-amber-700">Trả hôm nay</span>
                        <span v-if="room.is_checkin_today" class="rounded bg-blue-100 px-1 py-0.5 text-[10px] font-semibold text-blue-700">Đến hôm nay</span>
                        <span v-if="room.special_request_count > 0" class="inline-flex items-center gap-0.5 rounded bg-coral/10 px-1 py-0.5 text-[10px] font-semibold text-coral">
                            <AlertTriangle class="h-2.5 w-2.5" /> YC đặc biệt ({{ room.special_request_count }})
                        </span>
                    </div>

                    <div v-if="room.last_cleaned_at" class="text-[10px] text-gray-400">Dọn lần cuối: {{ room.last_cleaned_at }}</div>

                    <!-- Đúng 1 nút hành động chính (SẠCH khi bẩn, BẨN khi sạch) + nút Chi tiết —
                         không hiển thị workflow nhiều bước ở đây (xem HousekeepingDetailModal). -->
                    <div class="mt-1 flex flex-col gap-1.5">
                        <button
                            v-if="can.markCleaning && !room.is_maintenance && room.cleaning_status === 'DIRTY'"
                            type="button"
                            class="min-h-11 border border-pine bg-pine px-2 py-2 text-xs font-bold text-white hover:bg-pine/90 disabled:opacity-50"
                            :disabled="isProcessing(room)"
                            @click="markRoom(room, 'clean')"
                        >
                            {{ isProcessing(room) ? 'Đang xử lý...' : 'ĐÁNH DẤU SẠCH' }}
                        </button>
                        <button
                            v-else-if="can.markCleaning && !room.is_maintenance"
                            type="button"
                            class="min-h-11 border border-gray-400 bg-white px-2 py-2 text-xs font-semibold text-steel hover:bg-gray-50 disabled:opacity-50"
                            :disabled="isProcessing(room)"
                            @click="markRoom(room, 'dirty')"
                        >
                            {{ isProcessing(room) ? 'Đang xử lý...' : 'Đánh dấu bẩn' }}
                        </button>

                        <button
                            type="button"
                            class="inline-flex min-h-11 items-center justify-center gap-1 border border-gray-300 bg-white px-2 py-2 text-xs font-semibold text-steel hover:border-pine hover:text-pine"
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
            :legacy-actions="legacyActionsFor(detailRoom)"
            @close="detailRoom = null"
            @open-action="(key) => openDialog(key, detailRoom)"
        />

        <!-- Toast ngắn — không popup xác nhận cho thao tác đánh dấu sạch/bẩn thường xuyên. -->
        <div class="pointer-events-none fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4 sm:items-end sm:right-4 sm:left-auto">
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="pointer-events-auto w-full max-w-xs border px-3 py-2 text-xs font-semibold shadow-lg sm:w-auto"
                :class="toast.variant === 'error' ? 'border-coral bg-white text-coral' : 'border-pine bg-white text-pine'"
            >
                {{ toast.message }}
            </div>
        </div>
    </AppLayout>
</template>
