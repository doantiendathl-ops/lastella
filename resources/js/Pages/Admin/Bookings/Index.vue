<script setup>
import Pagination from '@/Components/Pagination.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Banknote, BedDouble, Eye, Pencil, Plus, Search, X, XCircle } from 'lucide-vue-next';
import { reactive } from 'vue';

const props = defineProps({
    bookings: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, required: true },
    can: { type: Object, required: true },
});

const query = reactive({
    booking_code: props.filters.booking_code ?? '',
    customer_name: props.filters.customer_name ?? '',
    customer_phone: props.filters.customer_phone ?? '',
    status: props.filters.status ?? '',
    booking_type: props.filters.booking_type ?? '',
    checkin_from: props.filters.checkin_from ?? '',
    checkin_to: props.filters.checkin_to ?? '',
    checkout_from: props.filters.checkout_from ?? '',
    checkout_to: props.filters.checkout_to ?? '',
    sales_user_id: props.filters.sales_user_id ?? '',
});

const applyFilters = () => router.get('/admin/bookings', clean(query), { preserveState: true, replace: true });

const resetFilters = () => {
    Object.keys(query).forEach((key) => {
        query[key] = '';
    });
    applyFilters();
};

const cancelBooking = (booking) => {
    if (!window.confirm(`Cancel booking ${booking.booking_code}?`)) {
        return;
    }

    router.post(`/admin/bookings/${booking.id}/cancel`, {}, { preserveScroll: true });
};

const clean = (value) => Object.fromEntries(Object.entries(value).filter(([, item]) => item !== '' && item !== null && item !== undefined));
</script>

<template>
    <Head title="Bookings" />

    <AppLayout>
        <template #header>
            <div class="flex min-w-0 items-center justify-between gap-4">
                <h1 class="truncate text-lg font-semibold">Bookings</h1>
                <Link
                    v-if="can.createBooking"
                    href="/admin/bookings/create"
                    class="inline-flex items-center gap-2 bg-pine px-3 py-2 text-sm font-semibold text-white hover:bg-ink"
                >
                    <Plus class="h-4 w-4" />
                    New
                </Link>
            </div>
        </template>

        <section class="border border-gray-200 bg-white shadow-sm">
            <form class="grid gap-3 border-b border-gray-200 p-4 md:grid-cols-2 xl:grid-cols-4" @submit.prevent="applyFilters">
                <input v-model="query.booking_code" type="text" placeholder="Booking code" class="border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                <input v-model="query.customer_name" type="text" placeholder="Customer name" class="border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                <input v-model="query.customer_phone" type="text" placeholder="Phone" class="border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                <select v-model="query.status" class="border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                    <option value="">All statuses</option>
                    <option v-for="status in options.statuses" :key="status.value" :value="status.value">{{ status.label }}</option>
                </select>
                <select v-model="query.booking_type" class="border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                    <option value="">All types</option>
                    <option v-for="type in options.bookingTypes" :key="type.value" :value="type.value">{{ type.label }}</option>
                </select>
                <input v-model="query.checkin_from" type="date" class="border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                <input v-model="query.checkin_to" type="date" class="border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                <select v-model="query.sales_user_id" class="border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                    <option value="">All sales users</option>
                    <option v-for="user in options.salesUsers" :key="user.value" :value="user.value">{{ user.label }}</option>
                </select>
                <input v-model="query.checkout_from" type="date" class="border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                <input v-model="query.checkout_to" type="date" class="border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                <div class="flex gap-2 md:col-span-2">
                    <button type="submit" class="inline-flex h-10 items-center gap-2 bg-pine px-3 text-sm font-semibold text-white">
                        <Search class="h-4 w-4" />
                        Apply
                    </button>
                    <button type="button" class="inline-flex h-10 items-center gap-2 border border-gray-300 px-3 text-sm text-steel hover:text-ink" @click="resetFilters">
                        <X class="h-4 w-4" />
                        Reset
                    </button>
                </div>
            </form>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-steel">
                        <tr>
                            <th class="px-4 py-3">Code</th>
                            <th class="px-4 py-3">Customer</th>
                            <th class="px-4 py-3">Phone</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Check-in</th>
                            <th class="px-4 py-3">Checkout</th>
                            <th class="px-4 py-3">Adults</th>
                            <th class="px-4 py-3">Children</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Sales</th>
                            <th class="px-4 py-3">Color</th>
                            <th class="px-4 py-3">Created</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="booking in bookings.data" :key="booking.id" class="hover:bg-gray-50">
                            <td class="whitespace-nowrap px-4 py-3 font-medium">{{ booking.booking_code }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.customer_name }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.customer_phone }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.booking_type }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.checkin_at }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.checkout_at }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.adults }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.children_under_6 }} / {{ booking.children_over_6 }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.status }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.sales_user }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <span class="inline-flex h-5 w-8 border border-gray-200" :style="{ backgroundColor: booking.booking_color }" />
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">{{ booking.created_at }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <Link :href="`/admin/bookings/${booking.id}`" class="mr-1 inline-flex h-8 w-8 items-center justify-center border border-gray-200 text-steel hover:border-pine hover:text-pine" title="View">
                                    <Eye class="h-4 w-4" />
                                </Link>
                                <Link v-if="can.updateBooking" :href="`/admin/bookings/${booking.id}/edit`" class="mr-1 inline-flex h-8 w-8 items-center justify-center border border-gray-200 text-steel hover:border-pine hover:text-pine" title="Edit">
                                    <Pencil class="h-4 w-4" />
                                </Link>
                                <Link v-if="can.updateBooking" :href="`/admin/bookings/${booking.id}?tab=requirements`" class="mr-1 inline-flex h-8 w-8 items-center justify-center border border-gray-200 text-steel hover:border-pine hover:text-pine" title="Add Requirement">
                                    <Plus class="h-4 w-4" />
                                </Link>
                                <Link v-if="can.addPayment" :href="`/admin/bookings/${booking.id}?tab=payments`" class="mr-1 inline-flex h-8 w-8 items-center justify-center border border-gray-200 text-steel hover:border-pine hover:text-pine" title="Add Deposit">
                                    <Banknote class="h-4 w-4" />
                                </Link>
                                <Link v-if="can.assignRoom" :href="`/admin/bookings/${booking.id}?tab=assignments`" class="mr-1 inline-flex h-8 w-8 items-center justify-center border border-gray-200 text-steel hover:border-pine hover:text-pine" title="Assign Room">
                                    <BedDouble class="h-4 w-4" />
                                </Link>
                                <button v-if="can.cancelBooking && booking.status !== 'CANCELLED'" type="button" class="inline-flex h-8 w-8 items-center justify-center border border-gray-200 text-steel hover:border-coral hover:text-coral" title="Cancel" @click="cancelBooking(booking)">
                                    <XCircle class="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                        <tr v-if="bookings.data.length === 0">
                            <td colspan="13" class="px-4 py-12 text-center text-sm text-steel">No bookings found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col gap-3 border-t border-gray-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-steel">Showing {{ bookings.from ?? 0 }} to {{ bookings.to ?? 0 }} of {{ bookings.total ?? 0 }}</p>
                <Pagination :links="bookings.links" />
            </div>
        </section>
    </AppLayout>
</template>
