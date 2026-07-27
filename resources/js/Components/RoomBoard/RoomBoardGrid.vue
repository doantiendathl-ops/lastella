<script setup>
defineProps({
    floors: { type: Array, required: true },
    getKey: { type: Function, default: (room) => room.id },
    emptyMessage: { type: String, default: 'Không có phòng phù hợp.' },
});
</script>

<template>
    <div class="space-y-4">
        <div v-if="floors.length === 0" class="border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-steel">
            {{ emptyMessage }}
        </div>

        <div v-for="floor in floors" :key="floor.id" class="flex flex-col gap-2 border border-gray-200 bg-white p-3 sm:flex-row">
            <div class="flex shrink-0 items-center gap-2 pb-2 sm:w-28 sm:flex-col sm:items-start sm:border-r sm:border-gray-100 sm:pb-0 sm:pr-3">
                <span class="text-sm font-semibold text-ink">{{ floor.code }}</span>
                <span class="text-xs text-steel">{{ floor.name }}</span>
                <span class="text-xs text-steel">({{ floor.rooms.length }} phòng)</span>
            </div>

            <div class="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                <slot name="card" v-for="room in floor.rooms" :key="getKey(room)" :room="room" :floor="floor" />
            </div>
        </div>
    </div>
</template>
