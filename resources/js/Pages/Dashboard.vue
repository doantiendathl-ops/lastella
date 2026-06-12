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
    { label: 'Tổng số phòng', value: props.metrics.total_rooms, icon: BedDouble },
    { label: 'Phòng còn trống', value: props.metrics.available_rooms, icon: CircleCheck },
    { label: 'Phòng đang ở', value: props.metrics.occupied_rooms, icon: DoorOpen },
    { label: 'Phòng hỏng', value: props.metrics.out_of_order_rooms, icon: CircleOff },
    { label: 'Người dùng', value: props.metrics.users, icon: Users },
    { label: 'Loại phòng', value: props.metrics.room_types, icon: Tags },
];
</script>

<template>
    <Head title="Tổng quan" />

    <AppLayout>
        <template #header>
            <h1 class="text-lg font-semibold">Tổng quan</h1>
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
