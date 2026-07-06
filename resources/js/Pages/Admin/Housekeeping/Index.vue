<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { housekeepingAssignmentStatusLabels, cleaningPriorityLabels } from '@/Support/vietnameseLabels';
import { roomStatusBadge } from '@/Support/roomStatusBadges';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import HousekeepingActionDialog from './Partials/HousekeepingActionDialog.vue';

const props = defineProps({
    rooms: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const sortedRooms = computed(() =>
    [...props.rooms].sort((a, b) => a.room_number.localeCompare(b.room_number, 'vi', { numeric: true })),
);

function actionsFor(room) {
    const actions = [];
    const assignment = room.active_assignment;

    if (room.status === 'VACANT_DIRTY' && !assignment && props.can.assign) {
        actions.push({ key: 'assign', label: 'Phân công' });
    }
    if (assignment?.status === 'pending' && props.can.updateStatus) {
        actions.push({ key: 'start', label: 'Bắt đầu dọn' });
    }
    if (assignment?.status === 'in_progress' && props.can.updateStatus) {
        actions.push({ key: 'complete', label: 'Hoàn thành dọn' });
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

function openDialog(action, room) {
    dialog.value = { action, room };
}

function closeDialog() {
    dialog.value = null;
}

function refresh() {
    router.reload({ only: ['rooms', 'can'] });
}
</script>

<template>
    <AppLayout>
        <template #header>
            <h1 class="text-lg font-semibold">Dọn phòng</h1>
        </template>

        <div v-if="sortedRooms.length === 0" class="border border-gray-200 bg-white p-8 text-center text-sm text-steel">
            Chưa có phòng nào được cấu hình.
        </div>

        <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
            <div
                v-for="room in sortedRooms"
                :key="room.id"
                class="border p-3 transition"
                :class="roomStatusBadge(room.status).card"
            >
                <div class="flex items-start justify-between gap-1">
                    <span class="text-sm font-semibold leading-tight text-ink">{{ room.room_number }}</span>
                    <span class="shrink-0 px-1.5 py-0.5 text-[10px] font-semibold" :class="roomStatusBadge(room.status).badge">
                        {{ room.status_label }}
                    </span>
                </div>

                <div v-if="room.active_assignment" class="mt-2 space-y-0.5 text-xs text-steel">
                    <div v-if="room.active_assignment.assigned_to">
                        <span class="text-gray-400">NV: </span>{{ room.active_assignment.assigned_to }}
                    </div>
                    <div>
                        <span class="text-gray-400">Ưu tiên: </span>{{ cleaningPriorityLabels[room.active_assignment.priority] ?? room.active_assignment.priority }}
                    </div>
                    <div>
                        <span class="text-gray-400">Trạng thái dọn: </span>{{ housekeepingAssignmentStatusLabels[room.active_assignment.status] ?? room.active_assignment.status }}
                    </div>
                </div>

                <div v-if="room.last_cleaned_at" class="mt-1.5 text-[11px] text-gray-400">
                    Dọn lần cuối: {{ room.last_cleaned_at }}
                </div>

                <div v-if="actionsFor(room).length > 0" class="mt-2 flex flex-wrap gap-1">
                    <button
                        v-for="action in actionsFor(room)"
                        :key="action.key"
                        type="button"
                        class="border border-gray-300 bg-white px-2 py-0.5 text-[11px] font-semibold text-ink hover:border-pine hover:text-pine"
                        @click="openDialog(action.key, room)"
                    >
                        {{ action.label }}
                    </button>
                </div>
            </div>
        </div>

        <HousekeepingActionDialog
            v-if="dialog"
            :action="dialog.action"
            :room="dialog.room"
            @close="closeDialog"
            @success="refresh"
        />
    </AppLayout>
</template>
