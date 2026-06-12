<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { BedDouble, CircleCheck, CircleOff, DoorOpen, Tags, Users } from 'lucide-vue-next';

const props = defineProps({
    metrics: {
        type: Object,
        required: true,
    },
});

const cards = [
    { label: 'Total Rooms', value: props.metrics.total_rooms, icon: BedDouble },
    { label: 'Available Rooms', value: props.metrics.available_rooms, icon: CircleCheck },
    { label: 'Occupied Rooms', value: props.metrics.occupied_rooms, icon: DoorOpen },
    { label: 'Out Of Order Rooms', value: props.metrics.out_of_order_rooms, icon: CircleOff },
    { label: 'Users', value: props.metrics.users, icon: Users },
    { label: 'Room Types', value: props.metrics.room_types, icon: Tags },
];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <template #header>
            <h1 class="text-lg font-semibold">Dashboard</h1>
        </template>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <article v-for="card in cards" :key="card.label" class="border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-sm font-medium text-steel">{{ card.label }}</p>
                        <p class="mt-2 text-3xl font-semibold text-ink">{{ card.value }}</p>
                    </div>
                    <div class="grid h-12 w-12 place-items-center bg-linen text-pine">
                        <component :is="card.icon" class="h-6 w-6" />
                    </div>
                </div>
            </article>
        </section>
    </AppLayout>
</template>
