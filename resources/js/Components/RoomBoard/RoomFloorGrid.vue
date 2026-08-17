<script setup>
// docs/yeucaumoi.txt mục 6 — shared floor/grid layout for the 4 Room Map
// screens, extracted verbatim from the Sơ đồ thao tác board (the reference
// layout the other 3 screens are being unified onto): ONE horizontal-scroll
// container per screen (never per floor), each floor a sticky-labeled row of
// flex-nowrap RoomTile-sized cards. Sibling of Components/RoomBoard/
// RoomBoardGrid.vue (the wrapping-grid layout used by Housekeeping/Rooms —
// left untouched, those aren't part of the 4 Room Map screens being unified).
defineProps({
    floors: { type: Array, default: () => [] },
    getKey: { type: Function, default: (room) => room.id },
    emptyMessage: { type: String, default: 'Không có phòng nào.' },
});
</script>

<template>
    <div class="room-board-overflow-x overflow-x-auto pb-2">
        <div class="room-board-content inline-flex min-w-full flex-col gap-3">
            <section v-for="floor in floors" :key="floor.id" class="floor-row flex items-start gap-3">
                <div class="floor-label sticky left-0 z-10 w-16 shrink-0 rounded bg-white/95 py-2 text-sm font-semibold text-gray-700 backdrop-blur-sm">
                    {{ floor.name }}
                </div>
                <div class="rooms-nowrap flex flex-nowrap gap-2">
                    <slot name="card" v-for="room in floor.rooms" :key="getKey(room)" :room="room" :floor="floor" />
                </div>
            </section>
        </div>
        <p v-if="floors.length === 0" class="text-sm text-gray-500">{{ emptyMessage }}</p>
    </div>
</template>
