<script setup>
import RoomOperationsCell from './RoomOperationsCell.vue';

defineProps({
    floors: { type: Array, default: () => [] },
    selectedIds: { type: Object, required: true }, // Set
    canEditNote: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle-select', 'view-booking', 'save-note']);
</script>

<template>
    <!--
        Mục XXII-XXV: exactly ONE horizontal-scroll container for the WHOLE
        board — never a scrollbar per floor. Every floor row below is
        flex-nowrap with no overflow of its own, so scrolling this single
        outer container moves every floor in lockstep (they're just rows at
        different vertical offsets inside the same wide scrolling content).
    -->
    <div class="room-board-overflow-x overflow-x-auto pb-2">
        <div class="room-board-content inline-flex min-w-full flex-col gap-3">
            <section v-for="floor in floors" :key="floor.id" class="floor-row flex items-start gap-3">
                <div class="floor-label sticky left-0 z-10 w-16 shrink-0 rounded bg-white/95 py-2 text-sm font-semibold text-gray-700 backdrop-blur-sm">
                    {{ floor.name }}
                </div>
                <div class="rooms-nowrap flex flex-nowrap gap-2">
                    <RoomOperationsCell
                        v-for="room in floor.rooms"
                        :key="room.id"
                        :room="room"
                        :selected="selectedIds.has(room.id)"
                        :can-edit-note="canEditNote"
                        @toggle-select="emit('toggle-select', $event)"
                        @view-booking="emit('view-booking', $event)"
                        @save-note="emit('save-note', $event)"
                    />
                </div>
            </section>
        </div>
        <p v-if="floors.length === 0" class="text-sm text-gray-500">Không có phòng nào.</p>
    </div>
</template>
