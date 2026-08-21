<script setup>
import RoomFloorGrid from '@/Components/RoomBoard/RoomFloorGrid.vue';
import RoomOperationsCell from './RoomOperationsCell.vue';

defineProps({
    floors: { type: Array, default: () => [] },
    selectedIds: { type: Object, required: true }, // Set
    canEditNote: { type: Boolean, default: false },
    canAdjustActualTime: { type: Boolean, default: false },
    // User request (2026-08-20 chat) — ordered room-id arrays for the
    // "pick replacement rooms on the board" swap flow; empty when not
    // currently picking. Order IS the pairing (index 0 <-> index 0, ...).
    swapSourceRoomIds: { type: Array, default: () => [] },
    swapTargetRoomIds: { type: Array, default: () => [] },
});

const emit = defineEmits(['toggle-select', 'view-booking', 'save-note', 'select-booking-rooms', 'edit-check-in', 'edit-check-out', 'view-other-services']);
</script>

<template>
    <RoomFloorGrid :floors="floors" empty-message="Không có phòng nào.">
        <template #card="{ room }">
            <RoomOperationsCell
                :room="room"
                :selected="selectedIds.has(room.id)"
                :can-edit-note="canEditNote"
                :can-adjust-actual-time="canAdjustActualTime"
                :swap-source-room-ids="swapSourceRoomIds"
                :swap-target-room-ids="swapTargetRoomIds"
                @toggle-select="emit('toggle-select', $event)"
                @view-booking="emit('view-booking', $event)"
                @save-note="emit('save-note', $event)"
                @select-booking-rooms="emit('select-booking-rooms', $event)"
                @edit-check-in="emit('edit-check-in', $event)"
                @edit-check-out="emit('edit-check-out', $event)"
                @view-other-services="emit('view-other-services', $event)"
            />
        </template>
    </RoomFloorGrid>
</template>
