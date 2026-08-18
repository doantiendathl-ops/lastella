<script setup>
import RoomFloorGrid from '@/Components/RoomBoard/RoomFloorGrid.vue';
import RoomOperationsCell from './RoomOperationsCell.vue';

defineProps({
    floors: { type: Array, default: () => [] },
    selectedIds: { type: Object, required: true }, // Set
    canEditNote: { type: Boolean, default: false },
    canAdjustActualTime: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle-select', 'view-booking', 'save-note', 'select-booking-rooms', 'edit-check-in', 'edit-check-out']);
</script>

<template>
    <RoomFloorGrid :floors="floors" empty-message="Không có phòng nào.">
        <template #card="{ room }">
            <RoomOperationsCell
                :room="room"
                :selected="selectedIds.has(room.id)"
                :can-edit-note="canEditNote"
                :can-adjust-actual-time="canAdjustActualTime"
                @toggle-select="emit('toggle-select', $event)"
                @view-booking="emit('view-booking', $event)"
                @save-note="emit('save-note', $event)"
                @select-booking-rooms="emit('select-booking-rooms', $event)"
                @edit-check-in="emit('edit-check-in', $event)"
                @edit-check-out="emit('edit-check-out', $event)"
            />
        </template>
    </RoomFloorGrid>
</template>
