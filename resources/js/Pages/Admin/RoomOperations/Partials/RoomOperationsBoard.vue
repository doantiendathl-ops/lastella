<script setup>
import RoomFloorGrid from '@/Components/RoomBoard/RoomFloorGrid.vue';
import RoomOperationsCell from './RoomOperationsCell.vue';

defineProps({
    floors: { type: Array, default: () => [] },
    selectedIds: { type: Object, required: true }, // Set
    canEditNote: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle-select', 'view-booking', 'save-note', 'select-booking-rooms']);
</script>

<template>
    <RoomFloorGrid :floors="floors" empty-message="Không có phòng nào.">
        <template #card="{ room }">
            <RoomOperationsCell
                :room="room"
                :selected="selectedIds.has(room.id)"
                :can-edit-note="canEditNote"
                @toggle-select="emit('toggle-select', $event)"
                @view-booking="emit('view-booking', $event)"
                @save-note="emit('save-note', $event)"
                @select-booking-rooms="emit('select-booking-rooms', $event)"
            />
        </template>
    </RoomFloorGrid>
</template>
